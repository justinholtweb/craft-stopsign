<?php

namespace justinholtweb\stopsign\twig;

use craft\base\ElementInterface;
use craft\elements\User;
use Craft;
use justinholtweb\stopsign\models\Verdict;
use justinholtweb\stopsign\Plugin;

/**
 * `craft.stopsign` — for control panel templates and for anybody building their own dashboard.
 */
class StopSignVariable
{
    /** Everything currently open, newest first. */
    public function board(): array
    {
        return Plugin::getInstance()->presence->board();
    }

    /** Whether anybody at all is inside a given element right now. */
    public function isOccupied(ElementInterface|int $element): bool
    {
        $id = $element instanceof ElementInterface ? $element->getCanonicalId() : (int)$element;

        return $id !== null && Plugin::getInstance()->presence->isOccupied($id);
    }

    /** Live locks across the whole site. */
    public function locks(): array
    {
        return Plugin::getInstance()->locks->all();
    }

    /** Recent collisions, optionally for one element. */
    public function history(int $limit = 50, ?int $elementId = null): array
    {
        return Plugin::getInstance()->collisions->recent($limit, $elementId);
    }

    /**
     * The verdict for an element, as some user would see it.
     *
     * The session token is deliberately required: without one this would report the caller’s own
     * tab back to them as a collision.
     */
    public function verdict(ElementInterface $element, string $sessionToken, ?User $user = null): Verdict
    {
        $user ??= Craft::$app->getUser()->getIdentity();

        // A console request or a signed-out front-end template has no viewer to build a verdict
        // for, and an empty one is the honest answer — there is nobody for anybody to collide with.
        if ($user === null) {
            return new Verdict();
        }

        return Plugin::getInstance()->verdicts->build($element, $user, $sessionToken);
    }
}
