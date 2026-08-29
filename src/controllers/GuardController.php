<?php

namespace justinholtweb\stopsign\controllers;

use justinholtweb\stopsign\records\CollisionRecord;
use yii\web\Response;

/**
 * The check that runs between clicking Save and actually saving.
 *
 * Craft has nothing here at all. Its activity poll can tell you a colleague is present and its
 * reload prompt can tell you the element changed, but neither of them is in the way of the save
 * button, so an author who has not looked at the avatars saves straight over the top and nobody
 * finds out until the work is missing.
 */
class GuardController extends BaseController
{
    protected array|int|bool $allowAnonymous = false;

    /**
     * A fresh verdict, taken at the moment of saving rather than up to a heartbeat ago.
     *
     * Deliberately not the cached banner state: ten seconds is long enough for a colleague to
     * arrive, and a guard that waves through a collision it could have seen is worse than no
     * guard, because the author now trusts it.
     */
    public function actionCheck(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $plugin = $this->plugin();
        $settings = $plugin->getSettings();

        if (!$settings->enabled) {
            return $this->asJson(['clear' => true, 'needsConfirmation' => false]);
        }

        $element = $this->resolveElement();
        $user = $this->signedInUser();
        $sessionToken = $this->sessionToken();

        if (!$plugin->scope->watches($element)) {
            return $this->asJson(['clear' => true, 'needsConfirmation' => false]);
        }

        $known = $this->request->getBodyParam('knownUpdatedTimestamp');
        $verdict = $plugin->verdicts->build(
            $element,
            $user,
            $sessionToken,
            $known !== null && $known !== '' ? (int)$known : null,
        );

        return $this->asJson($verdict->toArray());
    }

    /**
     * Records what the author decided.
     *
     * The interesting number on the history screen is not how many collisions happened, it is how
     * many of them somebody clicked through — that is the one that says whether the warning is
     * doing any good or has become wallpaper.
     */
    public function actionResolve(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $element = $this->resolveElement();
        $user = $this->signedInUser();

        $outcome = (string)$this->request->getRequiredBodyParam('outcome');
        $allowed = [
            CollisionRecord::OUTCOME_PROCEEDED,
            CollisionRecord::OUTCOME_CANCELLED,
            CollisionRecord::OUTCOME_RELOADED,
        ];

        if (!in_array($outcome, $allowed, true)) {
            return $this->asFailure('Unknown outcome.');
        }

        $kind = (string)$this->request->getBodyParam('kind', CollisionRecord::KIND_CONCURRENT);
        $allowedKinds = [
            CollisionRecord::KIND_CONCURRENT,
            CollisionRecord::KIND_STALE,
            CollisionRecord::KIND_LOCKED,
        ];

        $otherUserId = $this->request->getBodyParam('otherUserId');

        $this->plugin()->collisions->record(
            $element,
            $user,
            $otherUserId !== null && $otherUserId !== '' ? (int)$otherUserId : null,
            in_array($kind, $allowedKinds, true) ? $kind : CollisionRecord::KIND_CONCURRENT,
            $outcome,
        );

        return $this->asSuccess();
    }
}
