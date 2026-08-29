<?php

namespace justinholtweb\stopsign\services;

use craft\base\ElementInterface;
use craft\elements\Entry;
use justinholtweb\stopsign\models\Settings;
use justinholtweb\stopsign\Plugin;
use yii\base\Component;

/**
 * Which elements Stop Sign has an opinion about.
 *
 * Consulted on every heartbeat, on every element chip an index renders, and — via the lock check
 * — on every `canSave()` call Craft makes, which on a busy element index is hundreds. Everything
 * here is memoised per request and answers from data already in memory; nothing in this class
 * touches the database.
 */
class Scope extends Component
{
    /** @var array<string, bool> */
    private array $watchMemo = [];

    /** @var array<string, bool> */
    private array $lockMemo = [];

    public function watches(ElementInterface $element): bool
    {
        $settings = $this->settings();

        if (!$settings->enabled) {
            return false;
        }

        $key = $this->key($element);

        return $this->watchMemo[$key] ??= $this->computeWatches($element, $settings);
    }

    public function locks(ElementInterface $element): bool
    {
        $settings = $this->settings();

        if ($settings->lockMode === Settings::LOCK_MODE_OFF) {
            return false;
        }

        $key = $this->key($element);

        return $this->lockMemo[$key] ??= $this->computeLocks($element, $settings);
    }

    /** Forgets everything memoised. Test seam; also called when settings change mid-request. */
    public function reset(): void
    {
        $this->watchMemo = [];
        $this->lockMemo = [];
    }

    private function computeWatches(ElementInterface $element, Settings $settings): bool
    {
        // Revisions are read-only by definition, so two people “colliding” in one is not a
        // collision. Craft draws the same line in its own activity tracking.
        if ($element->getIsRevision()) {
            return false;
        }

        if (!$settings->watchAllElementTypes && !in_array($element::class, $settings->watchedElementTypes, true)) {
            return false;
        }

        $section = $this->sectionHandle($element);

        if ($section !== null && in_array($section, $settings->excludedSections, true)) {
            return false;
        }

        return true;
    }

    private function computeLocks(ElementInterface $element, Settings $settings): bool
    {
        if (!$this->watches($element)) {
            return false;
        }

        if ($settings->lockMode === Settings::LOCK_MODE_ALL) {
            return true;
        }

        $section = $this->sectionHandle($element);

        return $section !== null && in_array($section, $settings->lockedSections, true);
    }

    /**
     * The section a handle-based rule can match on, or null.
     *
     * Null for every element type but entries — and also for *nested* entries, which in Craft 5
     * live inside a Matrix or CKEditor field and have no section at all. They still get watched
     * (they have their own slideout editor, so two people really can be in one) but a
     * section-handle rule cannot address them, and `getSection()` returning null rather than
     * throwing is the only reason this reads as simply as it does.
     */
    private function sectionHandle(ElementInterface $element): ?string
    {
        if (!$element instanceof Entry) {
            return null;
        }

        return $element->getSection()?->handle;
    }

    private function key(ElementInterface $element): string
    {
        return $element::class . ':' . ($element->getCanonicalId() ?? 0);
    }

    private function settings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }
}
