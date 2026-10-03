<?php

namespace justinholtweb\stopsign\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\elements\User;
use craft\web\Controller;
use justinholtweb\stopsign\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;

/**
 * Shared plumbing for the four action controllers.
 *
 * Craft’s own `ElementsController::_element()` does this job and is private, so the resolution is
 * rebuilt here — deliberately narrower, because every one of these actions is about an element
 * that already exists and is already open on somebody’s screen.
 */
abstract class BaseController extends Controller
{
    /**
     * Every action here belongs to an editor open in the control panel.
     *
     * Craft only demands `accessCp` of control panel requests, so without this a front-end member
     * account could post to `/actions/stopsign/presence/ping` and read the names of the staff
     * editing any entry it can view — and claim locks on them.
     */
    public function beforeAction($action): bool
    {
        $this->requireCpRequest();

        return parent::beforeAction($action);
    }

    /**
     * The element this request is about, as the caller has it open.
     *
     * @throws BadRequestHttpException if the request does not identify an element.
     * @throws ForbiddenHttpException if the caller cannot even see it.
     */
    protected function resolveElement(): ElementInterface
    {
        $request = $this->request;
        $elementType = (string)$request->getRequiredBodyParam('elementType');
        $elementId = (int)$request->getRequiredBodyParam('elementId');
        $draftId = $request->getBodyParam('draftId');
        $siteId = (int)($request->getBodyParam('siteId') ?: Craft::$app->getSites()->getCurrentSite()->id);

        if (!is_subclass_of($elementType, ElementInterface::class)) {
            throw new BadRequestHttpException('Invalid element type.');
        }

        /** @var string|ElementInterface $elementType */
        $query = $elementType::find()
            ->siteId($siteId)
            ->status(null)
            ->trashed(null);

        if ($draftId) {
            // A draft carries its own id, so asking for both would find nothing.
            $query->draftId((int)$draftId)->provisionalDrafts(null);
        } else {
            $query->id($elementId)->drafts(null);
        }

        $element = $query->one();

        if (!$element) {
            throw new BadRequestHttpException('No element was identified by the request.');
        }

        // Presence is only ever recorded against elements the caller is allowed to look at.
        // Without this the heartbeat is a way to ask “does entry 4210 exist” from any account
        // that can reach the control panel at all.
        if (!Craft::$app->getElements()->canView($element, $this->signedInUser())) {
            throw new ForbiddenHttpException('You are not permitted to view this element.');
        }

        return $element;
    }

    /**
     * The tab this request comes from.
     *
     * Generated in the browser and never trusted for anything but distinguishing one of this
     * user’s tabs from another — it is scoped to the user id in every query it appears in, so a
     * forged token can only ever confuse the forger.
     */
    protected function sessionToken(): string
    {
        $token = (string)$this->request->getBodyParam('sessionToken', '');

        if (!preg_match('/\A[a-f0-9]{32}\z/', $token)) {
            throw new BadRequestHttpException('Invalid session token.');
        }

        return $token;
    }

    /**
     * The signed-in user, guaranteed.
     *
     * Named `signedInUser()` rather than the obvious `currentUser()`, because `craft\web\Controller`
     * already declares a **static** `currentUser()` — and redeclaring a static method as an instance
     * one is a PHP *compile* error, which means every action in the controller answers 500 and none
     * of them reach a line of this plugin's code. Same family as `Component::load()` and
     * `Component::getBehavior()`: the collision is invisible until something actually loads the
     * class, so a console test suite never sees it.
     */
    protected function signedInUser(): User
    {
        $user = static::currentUser();

        if (!$user) {
            throw new ForbiddenHttpException('Not signed in.');
        }

        return $user;
    }

    protected function plugin(): Plugin
    {
        return Plugin::getInstance();
    }
}
