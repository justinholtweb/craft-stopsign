<?php

namespace justinholtweb\stopsign\controllers;

use Craft;
use justinholtweb\stopsign\Plugin;
use justinholtweb\stopsign\records\CollisionRecord;
use justinholtweb\stopsign\records\PresenceRecord;
use Throwable;
use yii\web\Response;

/**
 * The heartbeat.
 *
 * One request every `heartbeatSeconds` from every open editor, so it does exactly two things: one
 * upsert and one indexed read. Anything heavier belongs somewhere a person is waiting for it.
 */
class PresenceController extends BaseController
{
    protected array|int|bool $allowAnonymous = false;

    /**
     * Records that this tab is here, and reports what it should be worried about.
     *
     * The browser must send `dontExtendSession` with this. Craft extends the session on any
     * authenticated request unless that param is present, so a heartbeat without it would keep a
     * control panel session alive for as long as a tab was left open — the plugin would quietly
     * defeat `userSessionDuration` on every site that installed it. Craft’s own activity poll
     * passes the same flag for the same reason.
     */
    public function actionPing(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $plugin = $this->plugin();

        if (!$plugin->getSettings()->enabled) {
            return $this->asJson(['enabled' => false]);
        }

        $element = $this->resolveElement();
        $user = $this->signedInUser();
        $sessionToken = $this->sessionToken();

        if (!$plugin->scope->watches($element)) {
            return $this->asJson(['watched' => false]);
        }

        $dirty = (bool)$this->request->getBodyParam('dirty', false);
        $intent = $dirty ? PresenceRecord::INTENT_EDITING : (string)$this->request->getBodyParam('intent', PresenceRecord::INTENT_VIEWING);

        $plugin->presence->beat($element, $user, $sessionToken, $intent, $dirty);

        // Claiming happens on the heartbeat rather than on page load so that a lock is only ever
        // held by a tab that is still beating. A lock claimed at load and released at unload is a
        // lock that survives a crashed browser forever.
        //
        // Only somebody who could save it may hold it: a reader who got there first would
        // otherwise turn every editor behind them read-only. If the permission check itself
        // fails, no lock is claimed — the open direction for a lock is “nobody holds it”.
        try {
            if ($plugin->locks->canEdit($element, $user)) {
                $plugin->locks->claimOrRenew($element, $user, $sessionToken);
            }
        } catch (Throwable $e) {
            Craft::warning('Could not claim the lock: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
        }

        $known = $this->request->getBodyParam('knownUpdatedTimestamp');
        $verdict = $plugin->verdicts->build(
            $element,
            $user,
            $sessionToken,
            $known !== null && $known !== '' ? (int)$known : null,
        );

        // History is written when the situation *first* becomes worth warning about, not on every
        // beat — otherwise a two-person edit session writes a row every ten seconds and the
        // history is unreadable exactly when somebody needs to read it.
        if ((bool)$this->request->getBodyParam('firstWarning', false) && !$verdict->isClear()) {
            $plugin->collisions->record(
                $element,
                $user,
                $verdict->others[0]->userId ?? null,
                $verdict->stale ? CollisionRecord::KIND_STALE : CollisionRecord::KIND_CONCURRENT,
                CollisionRecord::OUTCOME_WARNED,
            );
        }

        return $this->asJson(['watched' => true, 'enabled' => true] + $verdict->toArray());
    }

    /** Drops this tab’s presence and any lock it holds. Sent by `navigator.sendBeacon` on unload. */
    public function actionRelease(): Response
    {
        $this->requirePostRequest();

        $plugin = $this->plugin();
        $element = $this->resolveElement();
        $user = $this->signedInUser();
        $sessionToken = $this->sessionToken();

        $plugin->presence->release($element, $user, $sessionToken);
        $plugin->locks->release($element, $user, $sessionToken);

        // `sendBeacon` cannot read a response and does not set an `Accept` header, so this
        // deliberately answers with a bare 200 rather than `asJson()` — `requireAcceptsJson()`
        // would reject the very request shape the browser uses when a tab is closing.
        return $this->response->setStatusCode(204);
    }
}
