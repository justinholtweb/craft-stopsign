<?php

namespace justinholtweb\stopsign\services;

use craft\base\ElementInterface;
use craft\elements\User;
use justinholtweb\stopsign\models\Verdict;
use justinholtweb\stopsign\Plugin;
use yii\base\Component;

/**
 * Assembles the one verdict everything else renders.
 *
 * The banner, the save guard, the read-only state and the history row all come from here, so a
 * warning cannot say one thing in the banner and something else in the modal.
 */
class Verdicts extends Component
{
    /**
     * @param ElementInterface $element The element as the viewer has it open — draft or canonical.
     * @param string $sessionToken This tab, so its own heartbeat is not reported back to it.
     * @param int|null $knownUpdatedTimestamp The canonical `dateUpdated` the browser last saw.
     */
    public function build(
        ElementInterface $element,
        User $viewer,
        string $sessionToken,
        ?int $knownUpdatedTimestamp = null,
    ): Verdict {
        $plugin = Plugin::getInstance();
        $verdict = new Verdict();

        if (!$plugin->scope->watches($element)) {
            return $verdict;
        }

        $settings = $plugin->getSettings();
        $occupants = $plugin->presence->occupants($element, $viewer, $sessionToken);

        $verdict->others = $settings->warnAboutViewers
            ? $occupants['others']
            : array_values(array_filter(
                $occupants['others'],
                fn($occupant) => $occupant->dirty || $occupant->intent === 'editing',
            ));

        $verdict->ownTabs = $settings->warnOnOwnOtherTabs ? $occupants['ownTabs'] : [];
        $verdict->lock = $plugin->locks->state($element, $viewer);

        $this->applyStaleness($element, $viewer, $verdict, $knownUpdatedTimestamp);

        return $verdict;
    }

    /**
     * Whether the canonical element moved under the viewer’s feet.
     *
     * Craft does this comparison too, but only on full-page editors — the reload prompt in
     * `Craft.ElementEditor` is inside an `isFullPage` branch, so a slideout gives no signal at
     * all. It also cannot name the person, because nothing on the canonical row records who wrote
     * it. Both gaps are closed here.
     */
    private function applyStaleness(
        ElementInterface $element,
        User $viewer,
        Verdict $verdict,
        ?int $knownUpdatedTimestamp,
    ): void {
        $canonical = $element->getIsCanonical() ? $element : $element->getCanonical(true);
        $updatedAt = $canonical->dateUpdated?->getTimestamp();

        $verdict->canonicalUpdatedTimestamp = $updatedAt;

        if ($knownUpdatedTimestamp === null || $updatedAt === null || $updatedAt <= $knownUpdatedTimestamp) {
            return;
        }

        $lastSave = Plugin::getInstance()->collisions->lastSave($element);

        // Your own save is not somebody else moving the element under you. Without this the guard
        // fires on the author’s own second save of the session, which is both wrong and the
        // fastest possible way to teach somebody to click straight through the warning.
        if ($lastSave !== null && $lastSave['userId'] === $viewer->id) {
            return;
        }

        $verdict->stale = true;
        $verdict->staleBy = $lastSave['name'] ?? null;
        $verdict->staleSeconds = max(0, time() - $updatedAt);
    }
}
