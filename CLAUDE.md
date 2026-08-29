# Stop Sign — Craft CMS 5 Plugin

## Project Overview

Stop Sign warns two people that they are about to overwrite each other, asks before it lets one of
them do it, and keeps a record of what they chose. Distributed as `justinholtweb/craft-stopsign`.
**Free, single edition**, everything switched on. It is the replacement for
`marionnewlevant/snitch` (85,324 installs, Craft 3 only, last release 2020-12-24).

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, Yii2, Twig
- No build step, no runtime dependencies, no outbound requests. The control panel runtime is a
  plain classic script in `src/web/assets/cp/dist/`.
- Nothing runs on the front end. No front-end assets, no template hooks, no handlers that fire
  outside a control panel request.

## Architecture

### Namespace & package

- Namespace: `justinholtweb\stopsign`
- Package: `justinholtweb/craft-stopsign`
- Handle: `stopsign`

### The thing to know before touching anything: Craft already half-does this

`craft\services\Elements::trackActivity()` / `getRecentActivity()` back an `elementactivity` table,
and `Craft.ElementEditor._checkActivity()` polls `elements/recent-activity` every 15 seconds to
render a stack of 26px avatars with tooltips. Any change to this plugin has to be argued against
what core already gives you for free. The five gaps, and they are the whole product:

1. It is an avatar tooltip. Nothing is loud, nothing interrupts.
2. **Nothing stands between the save button and the save.** Core has no guard at all.
3. The "someone changed this — Reload" prompt is inside an `isFullPage` branch, so **slideouts get
   no conflict signal**.
4. Craft's garbage collector runs `DELETE FROM elementactivity WHERE timestamp < '1 minute ago'`.
   **There is no history, ever.**
5. `getRecentActivity($element, $excludeUserId)` is always called with the current user, so **your
   own second tab is invisible to it**.

Do not "simplify" this plugin by reading Craft's table. Points 4 and 5 make it impossible.

### One verdict

`services\Verdicts::build()` returns a single `models\Verdict`, and the banner, the save guard, the
read-only state, the history row and the console output all read from it. A warning that says one
thing in the banner and another in the modal is worse than no warning — the author stops believing
either. Wording lives in `Verdict::headline()` / `detail()`, on the server, and the browser only
renders it. An integration check pins the payload to the verdict for exactly this reason.

Levels, strongest first: `locked`, `danger`, `warning`, `notice`, `clear`.

### Presence is keyed per *tab*

`{{%stopsign_presence}}` is unique on `(elementId, siteId, userId, sessionToken)`. The token is 32
hex characters generated per page load in the browser and is never trusted for anything but telling
one of a user's tabs from another — every query it appears in is already scoped to the user id, so
a forged token can only confuse its forger.

### Data model

- `{{%stopsign_presence}}` — one row per open tab, upserted on every heartbeat.
- `{{%stopsign_locks}}` — unique on `(elementId, siteId)`. **The unique index is what settles the
  race**, not PHP: two people clicking into the same entry in the same second is the exact case
  this plugin exists for, and a read-then-write hands the lock to both of them.
- `{{%stopsign_saves}}` — one row per element per site, who last saved it. Exists because nothing
  on a canonical element row records this and revisions can be switched off per section.
- `{{%stopsign_collisions}}` — the history. Deliberately **no foreign key to the element**:
  deleting an entry must not erase the record that two people fought over it.

### Services

- `scope` — which elements are watched and which are lockable. Memoised, touches no database.
- `presence` — heartbeat upsert, occupancy, the board, pruning.
- `locks` — claim/renew/take-over/release, and `blocksSave()`.
- `collisions` — history plus the save ledger.
- `verdicts` — assembles the one verdict.

## Traps found while building this

- **`craft\web\Controller::currentUser()` is `static`.** A `protected function currentUser()` on a
  subclass is a **PHP compile error**, so every action in the controller answers 500 without
  reaching a line of plugin code — and a console test suite never loads a web controller, so 77
  green checks said nothing about it. Ours is `signedInUser()`. Same family as the
  `Component::load()` and `Component::getBehavior()` collisions in `[[craft-plugin-gotchas]]`.
- **`AuthorizationCheckEvent::$authorized` arrives as `null`, and `null` means “no opinion”.**
  `Elements::_authCheck()` returns it straight into `?? $element->canSave($user)`. Setting it to
  `true` grants permission Craft was about to refuse for its own reasons; the handler must leave it
  alone unless it is actively denying. Merely attaching a handler is what makes Craft consult the
  event at all, so the null default is load-bearing rather than tidy.
- **`EVENT_AUTHORIZE_SAVE` is the *right* place to enforce a lock, not `EVENT_BEFORE_SAVE_ELEMENT`.**
  `canSave()` is what Craft asks when it builds the editor, so answering it renders the second
  editor read-only natively — save button gone, fields disabled, autosave stopped — instead of
  leaving a live form that only fails at the end.
- **`Craft.ElementEditorSlideout.handleSubmit()` calls `this.elementEditor.handleSubmit(event)`.**
  That is why wrapping one method on the `ElementEditor` *instance* covers full-page saves,
  slideout saves and ⌘S in both, with nothing global patched. Do not go looking for a `submit()`
  method — there isn't one.
- **Craft overwrites `settings.canonicalUpdatedTimestamp` on every activity poll.** Reading it at
  heartbeat time compares the server's answer against itself, and staleness silently never fires
  after the first fifteen seconds. Snapshot it once, at attach.
- **The heartbeat must send `dontExtendSession`.** `craft\web\User` extends the session on any
  authenticated request without it, so an open editor would keep a control panel session alive
  indefinitely and the plugin would quietly defeat `userSessionDuration` on every site that
  installed it. Craft's own poll passes the same flag.
- **`Db::upsert()`'s second argument is the whole row and the third is what to change.** Leaving
  the third as `true` copies the insert half across, which rewrites `firstSeen` on every beat —
  resetting “has been here twelve minutes” to zero ten times a minute, silently, in a way no screen
  ever shows as wrong because the number is always plausible.
- **`elements.dateUpdated` is global, but a naive save ledger is per-site.** A save in one site
  marks the element stale in every site, so a site-scoped `lastSave()` leaves a correct warning
  unable to name anybody on multi-site installs. `lastSave()` reads every row for the element and
  prefers the viewer's site.
- **The author's own save must not trigger the stale guard.** Without that check the modal fires on
  an author's second save of the session, which is the fastest possible way to teach somebody to
  click straight through it.
- **`Craft.ElementEditor` sets `data('elementEditor')` before the `data-element-editor` attribute**,
  and on a full-page editor the form is already in the DOM so only the *attribute* changes. A
  `childList`-only MutationObserver sees nothing at all on the commonest screen there is —
  `attributeFilter` is required, plus an initial scan for editors that beat us to DOM ready.
- **Craft's lightswitch writes to a hidden input from script**, which fires no native `change`
  event, so a toggle is invisible to `input`/`change` listeners. Listen for clicks on
  `.lightswitch` too.
- **`sendBeacon` sends no `Accept` header**, so `presence/release` must not call
  `requireAcceptsJson()` — it answers 204 with no body. It also sends no CSRF header, so the token
  goes in the multipart body, which is where Yii looks anyway.
- **Entries authored by an inactive user cannot be re-saved.** Craft re-validates `authorIds` on
  every save, so a fixture entry owned by a freshly created (and therefore inactive) test user
  saves once and never again — which presents exactly as “the save ledger records nothing”.

See also `[[craft-plugin-gotchas]]` in the shared memory for family-wide traps.

## The icon

`src/icon.svg` is a regular octagon in `#C8102E` with one white bar. A raised hand or the word STOP
is a smudge at 18px, which is where a control panel icon is judged; the octagon reads on its own
and the bar keeps it from looking like a plain polygon.

`src/icon-mask.svg` is **one path with the bar knocked out by `fill-rule="evenodd"`**, not a white
bar painted over a black octagon. The control panel tints a mask, so a painted bar disappears the
moment Craft recolours the mark — which it does on hover, and again in dark mode.

`#C8102E` is Pantone 186-ish signal red, kept distinct from Blaster's brick `#C62D25` and RedPen's
crimson `#A80E35`.

## Testing

No local PHP on this Mac. Everything runs in the plugin-testing container, and use `docker exec`
rather than `ddev exec` — see `[[plugin-testing-harness]]` for why.

```sh
docker exec -w /var/www/html ddev-plugin-testing-web \
    php /var/www/craft-stopsign/tests/integration/checks.php          # 80 checks
docker exec ddev-plugin-testing-web bash -c \
    'find /var/www/craft-stopsign/src -name "*.php" -print0 | xargs -0 -n1 php -l'
node --check src/web/assets/cp/dist/stopsign-cp.js
```

The checks are idempotent and self-cleaning; settings are swapped in memory only, never into
project config, which on this shared harness fights the queue runner for the lock.

**The console suite cannot catch a web-controller compile error** — that is how the
`currentUser()` collision survived 77 green checks. Exercise the endpoints over HTTP as well: log
in with curl, re-read the CSRF token from an authenticated page (it rotates on login), and post to
`/admin/actions/...` rather than `/index.php`.

`docker exec ... php craft clear-caches/cp-resources` after editing anything under
`src/web/assets/*/dist`, or Craft keeps serving the published copy.

## Coding conventions

- `Craft::t('stopsign', '…')` for user-facing strings
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template — post secondary actions with `Craft.sendActionRequest`
- Never mark plugin settings `required`
- Fail **open**: every lock check, every guard, every badge is wrapped so that a failure lets the
  author through. A collision warning is a courtesy; an outage is not.
