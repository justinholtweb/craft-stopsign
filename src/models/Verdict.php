<?php

namespace justinholtweb\stopsign\models;

use Craft;
use DateTime;

/**
 * Everything Stop Sign has to say about one person, in one element, right now.
 *
 * There is exactly one of these and everything reads from it: the banner, the save guard, the
 * read-only state, the history row and the console output. A collision warning that says one
 * thing in the banner and a different thing in the modal is worse than no warning at all — the
 * author stops believing either of them — so the wording is decided once, on the server, and the
 * browser only renders it.
 */
class Verdict
{
    public const LEVEL_CLEAR = 'clear';
    /** Your own account, in another tab. */
    public const LEVEL_NOTICE = 'notice';
    /** Somebody else has it open but has not typed. */
    public const LEVEL_WARNING = 'warning';
    /** Somebody else is typing, or the element moved under you. */
    public const LEVEL_DANGER = 'danger';
    /** Somebody else holds the lock. */
    public const LEVEL_LOCKED = 'locked';

    /** @var Occupant[] */
    public array $others = [];

    /** @var Occupant[] */
    public array $ownTabs = [];

    public bool $stale = false;
    public ?string $staleBy = null;
    public ?int $staleSeconds = null;
    public ?int $canonicalUpdatedTimestamp = null;

    public LockState $lock;

    public function __construct()
    {
        $this->lock = LockState::notApplicable();
    }

    /** The strongest thing true of this situation. */
    public function level(): string
    {
        if ($this->lock->isHeldByAnotherUser()) {
            return self::LEVEL_LOCKED;
        }

        if ($this->stale || $this->hasEditors()) {
            return self::LEVEL_DANGER;
        }

        if ($this->others !== []) {
            return self::LEVEL_WARNING;
        }

        if ($this->ownTabs !== []) {
            return self::LEVEL_NOTICE;
        }

        return self::LEVEL_CLEAR;
    }

    public function isClear(): bool
    {
        return $this->level() === self::LEVEL_CLEAR;
    }

    public function hasEditors(): bool
    {
        foreach ($this->others as $occupant) {
            if ($occupant->dirty || $occupant->intent === 'editing') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether saving should stop and ask.
     *
     * Deliberately narrower than “is there anything to say”. A modal in front of every save
     * because a colleague has the entry open in a background tab is a modal people learn to
     * dismiss without reading, and then it is not there when it matters.
     */
    public function needsConfirmation(bool $guardSaves, bool $guardStale, bool $onlyWhenEditing): bool
    {
        if ($guardStale && $this->stale) {
            return true;
        }

        if (!$guardSaves) {
            return false;
        }

        if ($onlyWhenEditing) {
            return $this->hasEditors();
        }

        return $this->others !== [];
    }

    /** @return Occupant[] Everyone worth naming, own tabs last. */
    public function occupants(): array
    {
        return array_merge($this->others, $this->ownTabs);
    }

    public function headline(): string
    {
        if ($this->lock->isHeldByAnotherUser()) {
            return Craft::t('stopsign', '{name} has this locked.', ['name' => $this->lock->holderName]);
        }

        if ($this->stale && $this->staleBy !== null) {
            return Craft::t('stopsign', '{name} saved this while you had it open.', ['name' => $this->staleBy]);
        }

        if ($this->stale) {
            return Craft::t('stopsign', 'This was saved by someone else while you had it open.');
        }

        $editors = array_values(array_filter($this->others, fn(Occupant $o) => $o->dirty || $o->intent === 'editing'));

        if ($editors !== []) {
            return count($editors) === 1
                ? Craft::t('stopsign', '{name} is editing this right now.', ['name' => $editors[0]->name])
                : Craft::t('stopsign', '{count} people are editing this right now.', ['count' => count($editors)]);
        }

        if ($this->others !== []) {
            return count($this->others) === 1
                ? Craft::t('stopsign', '{name} has this open.', ['name' => $this->others[0]->name])
                : Craft::t('stopsign', '{count} people have this open.', ['count' => count($this->others)]);
        }

        if ($this->ownTabs !== []) {
            return Craft::t('stopsign', 'You have this open in another tab.');
        }

        return '';
    }

    public function detail(): string
    {
        if ($this->lock->isHeldByAnotherUser()) {
            return $this->lock->canTakeOver
                ? Craft::t('stopsign', 'You can read it, but not save it. Take over if they have finished.')
                : Craft::t('stopsign', 'You can read it, but not save it, until they are done.');
        }

        if ($this->stale) {
            return Craft::t('stopsign', 'What you are looking at is out of date. Saving now would put the old version back.');
        }

        if ($this->hasEditors()) {
            return Craft::t('stopsign', 'If you both save, one of you loses the work.');
        }

        if ($this->others !== []) {
            return Craft::t('stopsign', 'Nobody has typed yet, but check before you make big changes.');
        }

        if ($this->ownTabs !== []) {
            return Craft::t('stopsign', 'The two tabs will overwrite each other. Close one.');
        }

        return '';
    }

    public function toArray(): array
    {
        $settings = \justinholtweb\stopsign\Plugin::getInstance()->getSettings();

        return [
            'level' => $this->level(),
            'clear' => $this->isClear(),
            'headline' => $this->headline(),
            'detail' => $this->detail(),
            'others' => array_map(fn(Occupant $o) => $o->toArray(), $this->others),
            'ownTabs' => array_map(fn(Occupant $o) => $o->toArray(), $this->ownTabs),
            'stale' => $this->stale,
            'staleBy' => $this->staleBy,
            'staleSeconds' => $this->staleSeconds,
            'canonicalUpdatedTimestamp' => $this->canonicalUpdatedTimestamp,
            'lock' => $this->lock->toArray(),
            'needsConfirmation' => $this->needsConfirmation(
                $settings->guardSaves,
                $settings->guardStaleSaves,
                $settings->guardOnlyWhenOtherIsEditing,
            ),
        ];
    }
}
