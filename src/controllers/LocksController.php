<?php

namespace justinholtweb\stopsign\controllers;

use Craft;
use justinholtweb\stopsign\records\CollisionRecord;
use yii\web\Response;

class LocksController extends BaseController
{
    protected array|int|bool $allowAnonymous = false;

    /**
     * Takes a lock away from whoever has it.
     *
     * Always recorded against the taker’s name. A lock you can take silently is a lock nobody
     * trusts, and one you cannot take at all is a lock that eventually needs a database console.
     */
    public function actionTakeOver(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $plugin = $this->plugin();
        $element = $this->resolveElement();
        $user = $this->signedInUser();
        $sessionToken = $this->sessionToken();

        $before = $plugin->locks->state($element, $user);

        if (!$plugin->locks->takeOver($element, $user, $sessionToken)) {
            return $this->asFailure(Craft::t('stopsign', 'You are not permitted to take over this lock.'));
        }

        $plugin->collisions->record(
            $element,
            $user,
            $before->holderId,
            CollisionRecord::KIND_TAKEOVER,
            CollisionRecord::OUTCOME_TAKEN_OVER,
        );

        return $this->asJson([
            'success' => true,
            'lock' => $plugin->locks->state($element, $user)->toArray(),
        ]);
    }

    /** Gives up a lock we hold, without closing the tab. */
    public function actionRelease(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $element = $this->resolveElement();
        $user = $this->signedInUser();

        $this->plugin()->locks->release($element, $user, $this->sessionToken());

        return $this->asSuccess();
    }
}
