/**
 * Stop Sign — concurrent-editing collision warnings.
 *
 * Attaches to every `Craft.ElementEditor` on the page, full-page and slideout alike, and gives it
 * three things Craft does not have: a warning you cannot miss, a question before you save over
 * somebody, and an honest account of a soft lock.
 *
 * No build step. Plain ES2018 in a classic script, the same as the rest of this family.
 */
(function () {
  'use strict';

  if (typeof Craft === 'undefined' || typeof Garnish === 'undefined') {
    return;
  }

  /**
   * This tab.
   *
   * Presence is keyed per tab rather than per user, which is the only way to answer “you have
   * this open in another tab” — a real way to lose work, and the one collision Craft can never
   * report, because `getRecentActivity()` filters the current user out of its own results.
   */
  var SESSION_TOKEN = (function () {
    var bytes = new Uint8Array(16);

    if (window.crypto && window.crypto.getRandomValues) {
      window.crypto.getRandomValues(bytes);
    } else {
      for (var i = 0; i < 16; i++) {
        bytes[i] = Math.floor(Math.random() * 256);
      }
    }

    return Array.prototype.map
      .call(bytes, function (b) {
        return ('0' + b.toString(16)).slice(-2);
      })
      .join('');
  })();

  var LEVEL_CLEAR = 'clear';

  Craft.StopSign = {
    config: null,
    started: false,
    watchers: [],

    init: function (config) {
      if (this.started) {
        return;
      }

      this.started = true;
      this.config = config;

      this.scan();
      this.observe();

      // A last-ditch sweep for editors that finish initialising after us. The observer catches
      // almost everything; this catches the case where our own script and Craft's race on DOM
      // ready and the mutation has already happened by the time the observer is wired up.
      var self = this;
      window.setTimeout(function () {
        self.scan();
      }, 500);
    },

    /**
     * Finds element editors that already exist.
     *
     * Craft sets `data-element-editor` on the container in `ElementEditor.init()`, immediately
     * after storing the instance itself under the `elementEditor` data key. Reading the instance
     * rather than the markup is what makes this work identically for a full-page form and for a
     * slideout — `Craft.ElementEditorSlideout` builds a perfectly ordinary `ElementEditor` inside
     * its own container.
     */
    scan: function () {
      var self = this;

      $('[data-element-editor]').each(function () {
        self.attach(this);
      });
    },

    observe: function () {
      if (typeof MutationObserver === 'undefined') {
        return;
      }

      var self = this;
      var observer = new MutationObserver(function (mutations) {
        for (var i = 0; i < mutations.length; i++) {
          var mutation = mutations[i];

          if (mutation.type === 'attributes') {
            self.attach(mutation.target);
            continue;
          }

          for (var j = 0; j < mutation.addedNodes.length; j++) {
            var node = mutation.addedNodes[j];

            if (node.nodeType !== 1) {
              continue;
            }

            if (node.hasAttribute('data-element-editor')) {
              self.attach(node);
            }

            var nested = node.querySelectorAll('[data-element-editor]');

            for (var k = 0; k < nested.length; k++) {
              self.attach(nested[k]);
            }
          }
        }
      });

      // The attribute filter matters as much as the child list: on a full-page editor the form is
      // already in the document and only the *attribute* is added, so a childList-only observer
      // would see nothing at all on the most common screen there is.
      observer.observe(document.body, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['data-element-editor'],
      });
    },

    attach: function (element) {
      var editor = $(element).data('elementEditor');

      if (!editor || editor.stopSignWatcher) {
        return;
      }

      if (!editor.settings || !editor.settings.elementType) {
        return;
      }

      var elementId = editor.settings.canonicalId || editor.settings.elementId;

      // An element that has never been saved cannot be collided with — every “new entry” screen
      // is its own unsaved element, and there is nothing for a second person to be inside.
      if (!elementId) {
        return;
      }

      editor.stopSignWatcher = new Watcher(editor, this.config);
      this.watchers.push(editor.stopSignWatcher);
    },
  };

  // ------------------------------------------------------------------------------------ watcher

  function Watcher(editor, config) {
    this.editor = editor;
    this.config = config;
    this.settings = editor.settings;
    this.elementId = this.settings.canonicalId || this.settings.elementId;
    this.dirty = false;
    this.destroyed = false;
    this.warned = false;
    this.verdict = null;
    this.hadLock = false;
    this.bypassGuard = false;
    this.timer = null;
    this.banner = null;

    /**
     * The canonical timestamp this browser started from.
     *
     * Snapshotted once, deliberately. Craft's own activity poll *overwrites*
     * `settings.canonicalUpdatedTimestamp` with the newest value on every pass, so reading it
     * later would compare the server's answer against itself and staleness would never fire —
     * after the first fifteen seconds it would be permanently, silently clear.
     */
    this.knownUpdatedTimestamp =
      this.settings.canonicalUpdatedTimestamp || this.settings.updatedTimestamp || null;

    this.watchForChanges();
    this.wrapSubmit();
    this.watchVisibility();
    this.watchUnload();
    this.beat();
  }

  Watcher.prototype = {
    // ---------------------------------------------------------------------------- the heartbeat

    beat: function () {
      if (this.destroyed) {
        return;
      }

      var self = this;
      var firstWarning = !this.warned;

      Craft.sendActionRequest('POST', 'stopsign/presence/ping', {
        // Without this, an open editor keeps a control panel session alive for as long as the tab
        // is open — the plugin would quietly defeat `userSessionDuration` on every site that
        // installed it. Craft passes the same flag on its own activity poll.
        params: { dontExtendSession: 1 },
        data: this.payload({ firstWarning: firstWarning ? 1 : 0 }),
      })
        .then(function (response) {
          self.apply(response.data);
          self.schedule();
        })
        .catch(function (error) {
          // A signed-out session answers 400. Craft's own poll waits for the login modal rather
          // than hammering, and so does this — retrying every ten seconds against a dead session
          // is how a login prompt ends up buried under a hundred failed requests.
          if (error && error.response && error.response.status === 400) {
            Garnish.once(Craft.AuthManager, 'login', function () {
              self.beat();
            });
            return;
          }

          self.schedule();
        });
    },

    schedule: function () {
      if (this.destroyed) {
        return;
      }

      var self = this;
      var seconds = this.config.heartbeatSeconds;

      // A hidden tab with unsaved work still holds its place — closing the laptop lid should not
      // hand your half-finished entry to somebody else — but it beats a quarter as often, because
      // nobody is reading its banner.
      if (document.hidden) {
        seconds = this.dirty ? seconds * 4 : 0;
      }

      if (seconds === 0) {
        return;
      }

      this.timer = window.setTimeout(function () {
        self.beat();
      }, seconds * 1000);
    },

    payload: function (extra) {
      var data = {
        elementType: this.settings.elementType,
        elementId: this.elementId,
        draftId: this.settings.draftId || '',
        siteId: this.settings.siteId,
        sessionToken: SESSION_TOKEN,
        intent: this.dirty ? 'editing' : 'viewing',
        dirty: this.dirty ? 1 : 0,
        knownUpdatedTimestamp: this.knownUpdatedTimestamp || '',
      };

      if (extra) {
        for (var key in extra) {
          if (Object.prototype.hasOwnProperty.call(extra, key)) {
            data[key] = extra[key];
          }
        }
      }

      return data;
    },

    // ------------------------------------------------------------------------------- the verdict

    apply: function (verdict) {
      if (!verdict || verdict.watched === false || verdict.enabled === false) {
        this.destroy();
        return;
      }

      this.verdict = verdict;

      if (verdict.level !== LEVEL_CLEAR) {
        this.warned = true;
      }

      this.renderBanner(verdict);
      this.applyLock(verdict.lock);
    },

    /**
     * Reacts to a lock changing hands underneath the person looking at it.
     *
     * Craft renders the second editor read-only on its own, because the lock is enforced through
     * `canSave()` — but only for an editor that was *opened* while the lock was held. Somebody
     * who had the page open first and then lost it gets a live form that would fail at the end,
     * so the loss is announced and the save button goes.
     */
    applyLock: function (lock) {
      if (!lock || !lock.applicable) {
        return;
      }

      if (lock.isMine) {
        this.hadLock = true;
        return;
      }

      if (this.hadLock && lock.heldByOther) {
        this.hadLock = false;
        this.disableSaving();

        Craft.cp.displayError(
          this.config.strings.lostLock.replace('{name}', function () { return lock.holderName; })
        );
      }
    },

    disableSaving: function () {
      this.editor.$container
        .find('button[type="submit"], .btn.submit')
        .addClass('disabled')
        .attr('disabled', 'disabled');
    },

    // -------------------------------------------------------------------------------- the banner

    mountPoint: function () {
      var $content = this.settings.$contentContainer;

      if ($content && $content.length) {
        return $content;
      }

      var $inner = this.editor.$container.find('#content').first();

      return $inner.length ? $inner : this.editor.$container;
    },

    renderBanner: function (verdict) {
      if (verdict.level === LEVEL_CLEAR) {
        this.removeBanner();
        return;
      }

      if (!this.banner) {
        this.banner = document.createElement('div');
        this.banner.className = 'stopsign-banner';
        this.banner.setAttribute('role', 'status');
        this.banner.setAttribute('aria-live', 'polite');
        this.mountPoint().prepend(this.banner);
      }

      this.banner.setAttribute('data-level', verdict.level);
      this.banner.setAttribute('data-style', this.config.bannerStyle);
      this.banner.innerHTML = '';

      var octagon = document.createElement('span');
      octagon.className = 'stopsign-banner__mark';
      octagon.setAttribute('aria-hidden', 'true');
      this.banner.appendChild(octagon);

      var body = document.createElement('div');
      body.className = 'stopsign-banner__body';

      var headline = document.createElement('p');
      headline.className = 'stopsign-banner__headline';
      headline.textContent = verdict.headline;
      body.appendChild(headline);

      if (verdict.detail) {
        var detail = document.createElement('p');
        detail.className = 'stopsign-banner__detail';
        detail.textContent = verdict.detail;
        body.appendChild(detail);
      }

      this.banner.appendChild(body);

      var faces = this.renderFaces(verdict);

      if (faces) {
        this.banner.appendChild(faces);
      }

      var actions = this.renderActions(verdict);

      if (actions) {
        this.banner.appendChild(actions);
      }
    },

    renderFaces: function (verdict) {
      var people = (verdict.others || []).concat(verdict.ownTabs || []);

      if (!people.length) {
        return null;
      }

      var wrap = document.createElement('ul');
      wrap.className = 'stopsign-banner__faces';

      for (var i = 0; i < people.length; i++) {
        var person = people[i];
        var item = document.createElement('li');
        item.title = person.name;

        if (person.thumbHtml) {
          // The thumb comes from `User::getThumbHtml()`, so it is Craft's own markup rather than
          // anything this plugin composed, and it is the only place here that is not text.
          item.innerHTML = person.thumbHtml;
        } else {
          var initials = document.createElement('span');
          initials.className = 'stopsign-banner__initials';
          initials.textContent = person.name.slice(0, 1).toUpperCase();
          item.appendChild(initials);
        }

        wrap.appendChild(item);
      }

      return wrap;
    },

    renderActions: function (verdict) {
      var self = this;
      var wrap = document.createElement('div');
      wrap.className = 'stopsign-banner__actions';
      var any = false;

      if (verdict.lock && verdict.lock.heldByOther && verdict.lock.canTakeOver) {
        var takeOver = this.button(this.config.strings.takeOver, function () {
          self.takeOver(this);
        });
        takeOver.classList.add('stopsign-banner__btn--primary');
        wrap.appendChild(takeOver);
        any = true;
      }

      if (verdict.stale) {
        wrap.appendChild(
          this.button(this.config.strings.reload, function () {
            self.resolve('reloaded', 'stale');
            self.reload();
          })
        );
        any = true;
      }

      return any ? wrap : null;
    },

    button: function (label, handler) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'stopsign-banner__btn';
      btn.textContent = label;
      btn.addEventListener('click', handler);

      return btn;
    },

    removeBanner: function () {
      if (this.banner && this.banner.parentNode) {
        this.banner.parentNode.removeChild(this.banner);
      }

      this.banner = null;
    },

    // ----------------------------------------------------------------------------- the save guard

    /**
     * Puts a question between the save button and the save.
     *
     * `handleSubmit` is the one method worth wrapping: a full-page editor reaches it from the
     * form's submit event, and `Craft.ElementEditorSlideout.handleSubmit()` reaches it by calling
     * straight through to `this.elementEditor.handleSubmit(event)`. Wrapping the instance rather
     * than the prototype means nothing global is patched, and ⌘S is covered in both — the
     * slideout binds that shortcut to its own `handleSubmit`, which ends up here anyway.
     *
     * A full-page editor needs one more step. Garnish's `addListener(…, 'handleSubmit')` resolves
     * `this[name].bind(this)` once, at bind time, so the form's submit listener holds the
     * original method and never sees an instance property set afterwards — the guard was being
     * walked straight past on the commonest screen there is. Rebinding by name picks up the
     * wrapper. Dropping the editor's namespaced submit listeners also drops `submit.saveShortcut`,
     * which on a full-page editor is unreachable anyway: `handleSubmit` runs first and stops
     * immediate propagation.
     */
    wrapSubmit: function () {
      if (!this.config.guardSaves && !this.config.guardStaleSaves) {
        return;
      }

      if (typeof this.editor.handleSubmit !== 'function') {
        return;
      }

      var self = this;
      var original = this.editor.handleSubmit.bind(this.editor);

      this.editor.handleSubmit = function (event) {
        if (self.bypassGuard || self.destroyed) {
          self.bypassGuard = false;
          return original(event);
        }

        // Held synchronously, before any await: Craft's own handler is what normally cancels the
        // browser's native submit, and it is no longer going to run.
        if (event && typeof event.preventDefault === 'function') {
          event.preventDefault();
          event.stopPropagation();

          if (typeof event.stopImmediatePropagation === 'function') {
            event.stopImmediatePropagation();
          }
        }

        self.guard(event, original);

        return Promise.resolve();
      };

      if (!this.editor.slideout && this.editor.$container && typeof this.editor.addListener === 'function') {
        try {
          this.editor.removeListener(this.editor.$container, 'submit');
          this.editor.addListener(this.editor.$container, 'submit', 'handleSubmit');
        } catch (e) {
          // Fail open: worst case is the unguarded save Craft would have done anyway.
        }
      }
    },

    guard: function (event, original) {
      var self = this;

      // Checked again here rather than trusting the banner. Up to a full heartbeat has passed
      // since the last verdict, which is easily long enough for a colleague to arrive — and a
      // guard that waves through a collision it could have seen is worse than no guard, because
      // the author now believes it.
      Craft.sendActionRequest('POST', 'stopsign/guard/check', {
        params: { dontExtendSession: 1 },
        data: this.payload(),
      })
        .then(function (response) {
          var verdict = response.data;

          if (!verdict || !verdict.needsConfirmation) {
            self.proceed(event, original);
            return;
          }

          self.confirm(verdict, event, original);
        })
        .catch(function () {
          // If the guard itself cannot be reached, get out of the author's way. A plugin that
          // makes saving impossible when its own endpoint is down is a worse outage than the
          // collision it was trying to prevent.
          self.proceed(event, original);
        });
    },

    proceed: function (event, original) {
      this.bypassGuard = true;
      original(event);
    },

    confirm: function (verdict, event, original) {
      var self = this;
      var strings = this.config.strings;

      var $container = $('<div class="modal fitted stopsign-modal"/>').appendTo(Garnish.$bod);
      var $body = $('<div class="body"/>').appendTo($container);

      $('<h2/>').text(strings.guardTitle).appendTo($body);
      $('<p class="stopsign-modal__headline"/>').text(verdict.headline).appendTo($body);

      if (verdict.detail) {
        $('<p class="stopsign-modal__detail"/>').text(verdict.detail).appendTo($body);
      }

      var $footer = $('<div class="footer"/>').appendTo($container);
      var $buttons = $('<div class="buttons right"/>').appendTo($footer);

      var modal = new Garnish.Modal($container, { hideOnEsc: true, hideOnShadeClick: true });
      var kind = verdict.stale ? 'stale' : 'concurrent';
      var otherUserId = verdict.others && verdict.others.length ? verdict.others[0].userId : null;
      var decided = false;

      var $cancel = $('<button type="button" class="btn"/>').text(strings.cancel).appendTo($buttons);

      $cancel.on('click', function () {
        decided = true;
        self.resolve('cancelled', kind, otherUserId);
        modal.hide();
      });

      if (verdict.stale) {
        var $reload = $('<button type="button" class="btn submit"/>')
          .text(strings.reload)
          .appendTo($buttons);

        $reload.on('click', function () {
          decided = true;
          self.resolve('reloaded', kind, otherUserId);
          modal.hide();
          self.reload();
        });
      }

      var $save = $('<button type="button" class="btn"/>')
        .addClass(verdict.stale ? '' : 'submit')
        .text(strings.saveAnyway)
        .appendTo($buttons);

      $save.on('click', function () {
        decided = true;
        self.resolve('proceeded', kind, otherUserId);
        modal.hide();
        self.proceed(event, original);
      });

      // Dismissing with escape or a click on the shade is a decision too, and recording it as a
      // cancel is what keeps the history honest about how often the warning is being brushed off.
      modal.on('hide', function () {
        if (!decided) {
          self.resolve('cancelled', kind, otherUserId);
        }

        window.setTimeout(function () {
          $container.remove();
        }, 200);
      });

      modal.show();
      $cancel.trigger('focus');
    },

    resolve: function (outcome, kind, otherUserId) {
      Craft.sendActionRequest('POST', 'stopsign/guard/resolve', {
        params: { dontExtendSession: 1 },
        data: this.payload({
          outcome: outcome,
          kind: kind || 'concurrent',
          otherUserId: otherUserId || '',
        }),
      }).catch(function () {
        // History is a nicety; never let it interrupt anybody.
      });
    },

    // ------------------------------------------------------------------------------ the soft lock

    takeOver: function (button) {
      var self = this;

      button.disabled = true;
      button.textContent = this.config.strings.takingOver;

      Craft.sendActionRequest('POST', 'stopsign/locks/take-over', {
        data: this.payload(),
      })
        .then(function () {
          Craft.cp.displayNotice(self.config.strings.takenOver);
          self.reload();
        })
        .catch(function () {
          button.disabled = false;
          button.textContent = self.config.strings.takeOver;
        });
    },

    /** Reloads the slideout if that is what we are in, and the page otherwise. */
    reload: function () {
      var slideout = this.editor.slideout;

      if (slideout && typeof slideout.reload === 'function') {
        slideout.reload();
        return;
      }

      window.location.reload();
    },

    // ---------------------------------------------------------------------------------- lifecycle

    /**
     * Notices that somebody has started typing.
     *
     * A listener rather than a comparison against the serialised form: `serializeForm()` walks
     * every input in the document, and doing that on a timer, on an entry with forty fields and
     * a dozen Matrix blocks, is real work to answer a question a single `input` event already
     * answers for free.
     */
    watchForChanges: function () {
      var self = this;
      var container = this.editor.$container[0];

      if (!container) {
        return;
      }

      var markDirty = function () {
        if (self.dirty) {
          return;
        }

        self.dirty = true;

        // Going from viewing to editing is the transition that matters most to everyone else in
        // the element, so it is reported at once rather than at the next scheduled beat.
        self.restart();
      };

      container.addEventListener('input', markDirty, true);
      container.addEventListener('change', markDirty, true);

      // Craft's lightswitch writes to a hidden input from script, and setting `.value` in
      // JavaScript fires no native `change` event — so a lightswitch toggle is invisible to the
      // listeners above.
      $(container).on('click', '.lightswitch', markDirty);

      this.editor.on('createProvisionalDraft', markDirty);
      this.editor.on('afterSaveDraft', markDirty);

      this.editor.on('afterSubmit', function () {
        self.dirty = false;
        self.knownUpdatedTimestamp = Math.floor(Date.now() / 1000);
        self.restart();
      });
    },

    watchVisibility: function () {
      var self = this;

      this.visibilityHandler = function () {
        if (!document.hidden) {
          self.restart();
        }
      };

      document.addEventListener('visibilitychange', this.visibilityHandler);
    },

    watchUnload: function () {
      var self = this;

      this.unloadHandler = function () {
        self.release();
      };

      // `pagehide` as well as `beforeunload`: on mobile Safari a tab that is swiped away never
      // fires `beforeunload` at all, and the presence row would then sit there until it timed
      // out, telling a colleague somebody is in an entry that nobody has open.
      window.addEventListener('pagehide', this.unloadHandler);
      window.addEventListener('beforeunload', this.unloadHandler);
    },

    release: function () {
      if (this.destroyed) {
        return;
      }

      if (!navigator.sendBeacon) {
        return;
      }

      var form = new FormData();
      var data = this.payload();

      for (var key in data) {
        if (Object.prototype.hasOwnProperty.call(data, key)) {
          form.append(key, data[key]);
        }
      }

      // `sendBeacon` sends no `X-CSRF-Token` header, so the token goes in the body — which is
      // where Yii looks anyway, and the only shape that survives a page that is already closing.
      form.append(Craft.csrfTokenName, Craft.csrfTokenValue);
      form.append('dontExtendSession', '1');

      navigator.sendBeacon(Craft.getActionUrl('stopsign/presence/release'), form);
    },

    restart: function () {
      window.clearTimeout(this.timer);
      this.beat();
    },

    destroy: function () {
      this.destroyed = true;
      window.clearTimeout(this.timer);
      this.removeBanner();

      document.removeEventListener('visibilitychange', this.visibilityHandler);
      window.removeEventListener('pagehide', this.unloadHandler);
      window.removeEventListener('beforeunload', this.unloadHandler);
    },
  };
})();
