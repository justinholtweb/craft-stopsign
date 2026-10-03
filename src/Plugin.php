<?php

namespace justinholtweb\stopsign;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\AuthorizationCheckEvent;
use craft\events\DefineElementHtmlEvent;
use craft\events\ElementEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\TemplateEvent;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\services\Elements;
use craft\services\Gc;
use craft\services\Utilities;
use craft\web\twig\variables\CraftVariable;
use craft\web\View;
use justinholtweb\stopsign\console\controllers\StopSignController;
use justinholtweb\stopsign\models\Settings;
use justinholtweb\stopsign\services\Collisions;
use justinholtweb\stopsign\services\Locks;
use justinholtweb\stopsign\services\Presence;
use justinholtweb\stopsign\services\Scope;
use justinholtweb\stopsign\services\Verdicts;
use justinholtweb\stopsign\twig\StopSignVariable;
use justinholtweb\stopsign\utilities\StopSignUtility;
use justinholtweb\stopsign\web\assets\cp\CpAsset;
use Throwable;
use yii\base\Event;

/**
 * Stop Sign — concurrent-editing collision warnings.
 *
 * @property-read Collisions $collisions
 * @property-read Locks $locks
 * @property-read Presence $presence
 * @property-read Scope $scope
 * @property-read Verdicts $verdicts
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const LOG_CATEGORY = 'stopsign';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'collisions' => Collisions::class,
                'locks' => Locks::class,
                'presence' => Presence::class,
                'scope' => Scope::class,
                'verdicts' => Verdicts::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerConsoleCommands();
        $this->registerUtility();
        $this->registerTwig();
        $this->registerGarbageCollection();
        $this->registerSaveLedger();
        $this->registerLockEnforcement();
        $this->registerIndexBadges();
        $this->registerCpAssets();
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('stopsign/settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
            'sections' => Craft::$app->getEntries()->getAllSections(),
            'userGroups' => Craft::$app->getUserGroups()->getAllGroups(),
            'elementTypes' => $this->elementTypeOptions(),
            'lockModes' => Settings::lockModeOptions(),
            'bannerStyles' => Settings::bannerStyleOptions(),
        ]);
    }

    /** @return array<int, array{label: string, value: string}> */
    private function elementTypeOptions(): array
    {
        $options = [];

        foreach (Craft::$app->getElements()->getAllElementTypes() as $type) {
            /** @var string|\craft\base\ElementInterface $type */
            $options[] = ['label' => $type::pluralDisplayName(), 'value' => $type];
        }

        usort($options, fn(array $a, array $b) => strcasecmp($a['label'], $b['label']));

        return $options;
    }

    // ------------------------------------------------------------------ the browser side

    /**
     * Loads Stop Sign into every control panel page.
     *
     * Every page rather than only the element edit screens, because a slideout editor can be
     * opened from an element index, from a relation field, from a Matrix block, and from a
     * dashboard widget — there is no route list that covers them all, and a collision warning
     * that works everywhere but the one screen an editor happens to use is not a warning.
     */
    private function registerCpAssets(): void
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest() || !$request->getIsCpRequest() || $request->getIsAjax()) {
            return;
        }

        Event::on(View::class, View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE, function(TemplateEvent $event) {
            if ($event->templateMode !== View::TEMPLATE_MODE_CP) {
                return;
            }

            $settings = $this->getSettings();

            if (!$settings->enabled) {
                return;
            }

            try {
                $view = Craft::$app->getView();
                $view->registerAssetBundle(CpAsset::class);
                $view->registerJs(sprintf('Craft.StopSign.init(%s);', Json::encode($this->jsConfig())));
            } catch (Throwable $e) {
                // A collision warning is not worth taking the control panel down for.
                Craft::error('Could not register Stop Sign’s assets: ' . $e->getMessage(), self::LOG_CATEGORY);
            }
        });
    }

    /** Everything the browser runtime needs, and nothing about anyone who is not the viewer. */
    private function jsConfig(): array
    {
        $settings = $this->getSettings();

        return [
            'heartbeatSeconds' => max(3, $settings->heartbeatSeconds),
            'bannerStyle' => $settings->bannerStyle,
            'guardSaves' => $settings->guardSaves,
            'guardStaleSaves' => $settings->guardStaleSaves,
            'lockingOn' => $settings->lockMode !== Settings::LOCK_MODE_OFF,
            'strings' => [
                'saveAnyway' => Craft::t('stopsign', 'Save anyway'),
                'cancel' => Craft::t('stopsign', 'Go back'),
                'reload' => Craft::t('stopsign', 'Reload the latest'),
                'takeOver' => Craft::t('stopsign', 'Take over'),
                'takingOver' => Craft::t('stopsign', 'Taking over…'),
                'checking' => Craft::t('stopsign', 'Checking…'),
                'guardTitle' => Craft::t('stopsign', 'Hold on'),
                'lostLock' => Craft::t('stopsign', '{name} took over this element. Your changes are still in your own draft, but you can no longer save them here.'),
                'takenOver' => Craft::t('stopsign', 'You have taken over. Reloading so you get the latest version.'),
                'dismiss' => Craft::t('stopsign', 'Dismiss'),
                'stopSign' => Craft::t('stopsign', 'Stop Sign'),
            ],
        ];
    }

    // ------------------------------------------------------------------ the server side

    /**
     * Records who saved the canonical element.
     *
     * Nothing in Craft stores this. Revisions come closest and can be switched off per section,
     * so a staleness warning built on them silently degrades to “this was updated by nobody” on
     * exactly the sections whose owners turned revisions off to save space.
     */
    private function registerSaveLedger(): void
    {
        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, function(ElementEvent $event) {
            $element = $event->element;

            if ($element->getIsDraft() || $element->getIsRevision() || $element->propagating) {
                return;
            }

            $user = Craft::$app->getUser()->getIdentity();

            if (!$user || !$this->scope->watches($element)) {
                return;
            }

            try {
                $this->collisions->recordSave($element, $user);
            } catch (Throwable $e) {
                // Never let bookkeeping fail a save. The worst case is a staleness warning that
                // says “someone” instead of naming a person.
                Craft::error('Could not record the save: ' . $e->getMessage(), self::LOG_CATEGORY);
            }
        });
    }

    /**
     * Makes a soft lock real.
     *
     * `EVENT_AUTHORIZE_SAVE` rather than `EVENT_BEFORE_SAVE_ELEMENT`, and the difference is the
     * whole feature: `canSave()` is what Craft asks when it builds the editor, so answering it
     * renders the second editor read-only *natively* — the save button goes, the fields disable,
     * the autosave stops — instead of leaving a live form that only fails at the end.
     *
     * Two things about this event are traps. `$event->authorized` is `null` on arrival and `null`
     * means “no opinion”, so the handler must leave it alone unless it is actually denying;
     * writing `true` would grant permission Craft was about to refuse for its own reasons. And
     * merely attaching a handler makes Craft consult the event at all, so the null default is
     * load-bearing rather than tidy.
     */
    private function registerLockEnforcement(): void
    {
        Event::on(Elements::class, Elements::EVENT_AUTHORIZE_SAVE, function(AuthorizationCheckEvent $event) {
            $settings = $this->getSettings();

            if (!$settings->enabled || $settings->lockMode === Settings::LOCK_MODE_OFF || !$settings->enforceLocks) {
                return;
            }

            $element = $event->element;

            if ($element === null) {
                return;
            }

            $request = Craft::$app->getRequest();

            // Console commands, queue jobs and visitors are not editors at keyboards, and a lock
            // that stops `resave/entries` or a member's front-end form is not a collision warning,
            // it is an outage. A control panel user posting to a front-end *action* URL is the
            // exception: the same editor sending the same form to `/actions/elements/save`
            // instead of `/admin/actions/…` would otherwise walk straight past a lock the settings
            // promise is enforced.
            if ($request->getIsConsoleRequest()) {
                return;
            }

            if (!$request->getIsCpRequest() && !($request->getIsActionRequest() && $event->user?->can('accessCp'))) {
                return;
            }

            try {
                if ($this->locks->blocksSave($element, $event->user)) {
                    $event->authorized = false;
                }
            } catch (Throwable $e) {
                // Fail *open*. A lock is a courtesy; a database hiccup that locks a whole team
                // out of their own content is not.
                Craft::error('Could not evaluate the lock: ' . $e->getMessage(), self::LOG_CATEGORY);
            }
        });
    }

    /**
     * Badges element chips that somebody is currently inside.
     *
     * This is the gap that costs the most time in practice: on a stock Craft install you find out
     * that a colleague is in an entry *after* you have opened it and started typing. The badge is
     * a snapshot taken when the index renders — presence is answered for the whole page in one
     * query, not one per chip.
     */
    private function registerIndexBadges(): void
    {
        Event::on(Cp::class, Cp::EVENT_DEFINE_ELEMENT_CHIP_HTML, function(DefineElementHtmlEvent $event) {
            $settings = $this->getSettings();

            if (!$settings->enabled || !$settings->showIndexBadges) {
                return;
            }

            $element = $event->element;
            $canonicalId = $element->getCanonicalId();

            if ($canonicalId === null || !$this->scope->watches($element)) {
                return;
            }

            try {
                if (!$this->presence->isOccupied($canonicalId)) {
                    return;
                }
            } catch (Throwable) {
                return;
            }

            $event->html = Html::modifyTagAttributes($event->html, [
                'class' => ['stopsign-occupied'],
                'data' => ['stopsign-occupied' => '1'],
            ]);
        });
    }

    // ------------------------------------------------------------------ housekeeping

    /**
     * Prunes on Craft’s own garbage collection run.
     *
     * Presence rows are kept for ten times the presence window rather than deleted on the
     * boundary — the row costs one indexed comparison, and throwing it away removes the evidence
     * behind a warning somebody is standing in front of asking about.
     */
    private function registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            try {
                $this->presence->prune();
                $this->locks->prune();
                $this->collisions->prune();
                $this->collisions->pruneSaves();
            } catch (Throwable $e) {
                Craft::error('Could not prune Stop Sign’s tables: ' . $e->getMessage(), self::LOG_CATEGORY);
            }
        });
    }

    private function registerUtility(): void
    {
        Event::on(Utilities::class, Utilities::EVENT_REGISTER_UTILITIES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = StopSignUtility::class;
        });
    }

    private function registerTwig(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            $event->sender->set('stopsign', StopSignVariable::class);
        });
    }

    /**
     * Gives each command its own top-level name: `stopsign/status`, not `stopsign/stopsign/status`.
     *
     * Yii builds a console route from the module id and a *controller* id, so one controller
     * holding four commands would name every one of them after itself. These are verbs, not a
     * resource, so each id maps onto the same class with its own default action.
     */
    private function registerConsoleCommands(): void
    {
        if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
            return;
        }

        foreach (['status', 'unlock', 'prune', 'history'] as $command) {
            $this->controllerMap[$command] = [
                'class' => StopSignController::class,
                'defaultAction' => $command,
            ];
        }
    }
}
