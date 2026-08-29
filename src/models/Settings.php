<?php

namespace justinholtweb\stopsign\models;

use Craft;
use craft\base\Model;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;

/**
 * Stop Sign’s settings.
 *
 * Nothing here is `required`. A rule that is required blocks `savePluginSettings()` wholesale, so
 * a half-configured install cannot save *any* setting until the offending field is filled in.
 * Correctness is validated where a value is present instead.
 */
class Settings extends Model
{
    public const LOCK_MODE_OFF = 'off';
    public const LOCK_MODE_SECTIONS = 'sections';
    public const LOCK_MODE_ALL = 'all';

    public const STYLE_LOUD = 'loud';
    public const STYLE_SUBTLE = 'subtle';

    // ------------------------------------------------------------------ what is watched

    /** The master switch. Off means Stop Sign renders nothing and records nothing. */
    public bool $enabled = true;

    /** Watch every element type that has an editor, rather than a chosen few. */
    public bool $watchAllElementTypes = true;

    /** @var string[] Element type classes to watch when `watchAllElementTypes` is off. */
    public array $watchedElementTypes = [
        Entry::class,
        Category::class,
        Asset::class,
        User::class,
    ];

    /** @var string[] Section handles to leave alone. Entries only; other element types ignore it. */
    public array $excludedSections = [];

    // ------------------------------------------------------------------ presence

    /**
     * Seconds between heartbeats from an open editor.
     *
     * Craft’s own activity poll is hard-coded to 15. Ten is a little tighter without being a
     * meaningful load: the request is one upsert and one indexed read.
     */
    public int $heartbeatSeconds = 10;

    /**
     * Seconds after a tab’s last heartbeat before it stops counting as present.
     *
     * Must be comfortably more than `heartbeatSeconds` or a single dropped request makes a
     * colleague blink out of the banner and back in again.
     */
    public int $presenceTtlSeconds = 45;

    /** Warn when the same account has the element open in another tab. */
    public bool $warnOnOwnOtherTabs = true;

    // ------------------------------------------------------------------ the warning

    /** `loud` gives a full-width banner; `subtle` gives a single line above the content. */
    public string $bannerStyle = self::STYLE_LOUD;

    /** Warn about people who merely have it open, not only those who have started typing. */
    public bool $warnAboutViewers = true;

    /** Badge elements on index screens and in relation fields when somebody is in them. */
    public bool $showIndexBadges = true;

    // ------------------------------------------------------------------ the save guard

    /** Ask for confirmation before saving over somebody who is in the element right now. */
    public bool $guardSaves = true;

    /** Ask for confirmation when the element changed underneath you while you had it open. */
    public bool $guardStaleSaves = true;

    /**
     * Only interrupt when the other party has actually typed something.
     *
     * On a big team a colleague simply *reading* the entry is not worth a modal, and a guard that
     * fires on nothing is a guard people learn to click through.
     */
    public bool $guardOnlyWhenOtherIsEditing = true;

    // ------------------------------------------------------------------ the soft lock

    /** `off`, `sections` (the handles below), or `all`. */
    public string $lockMode = self::LOCK_MODE_OFF;

    /** @var string[] Section handles to lock when `lockMode` is `sections`. */
    public array $lockedSections = [];

    /** Seconds a lock survives without a heartbeat from its holder. */
    public int $lockTtlSeconds = 120;

    /**
     * Refuse canonical saves from anyone but the lock holder.
     *
     * With this off the lock is advisory — the banner still says who holds it, and the editor is
     * still read-only in the browser, but nothing stops a determined save. With it on the refusal
     * is enforced through Craft’s own authorisation check, which is also what makes the second
     * editor render read-only without any JavaScript.
     */
    public bool $enforceLocks = true;

    /** Let a second editor take the lock. Turning this off is the one way to get truly stuck. */
    public bool $allowTakeOver = true;

    /** @var string[] User group handles allowed to take over. Empty means anyone who can edit. */
    public array $takeOverGroups = [];

    /** Admins are never locked out. Leave this on unless you enjoy support calls. */
    public bool $adminsBypassLocks = true;

    // ------------------------------------------------------------------ history

    /** Keep a record of collisions and what authors did about them. */
    public bool $recordHistory = true;

    /** Days of collision history to keep. Zero keeps it forever. */
    public int $historyRetentionDays = 30;

    public function defineRules(): array
    {
        return [
            [['heartbeatSeconds'], 'integer', 'min' => 3, 'max' => 120],
            [['presenceTtlSeconds'], 'integer', 'min' => 10, 'max' => 900],
            [['lockTtlSeconds'], 'integer', 'min' => 30, 'max' => 3600],
            [['historyRetentionDays'], 'integer', 'min' => 0, 'max' => 3650],
            [['lockMode'], 'in', 'range' => [self::LOCK_MODE_OFF, self::LOCK_MODE_SECTIONS, self::LOCK_MODE_ALL]],
            [['bannerStyle'], 'in', 'range' => [self::STYLE_LOUD, self::STYLE_SUBTLE]],
            [['presenceTtlSeconds'], 'validateTtlOutlivesHeartbeat'],
            [['lockTtlSeconds'], 'validateLockOutlivesHeartbeat'],
        ];
    }

    /**
     * A presence window shorter than two heartbeats makes colleagues flicker in and out of the
     * banner, which reads as a broken plugin rather than a misconfigured one.
     */
    public function validateTtlOutlivesHeartbeat(): void
    {
        if ($this->presenceTtlSeconds < $this->heartbeatSeconds * 2) {
            $this->addError('presenceTtlSeconds', Craft::t('stopsign', 'The presence window must be at least twice the heartbeat interval, or editors will flicker in and out of the warning.'));
        }
    }

    public function validateLockOutlivesHeartbeat(): void
    {
        if ($this->lockTtlSeconds < $this->heartbeatSeconds * 3) {
            $this->addError('lockTtlSeconds', Craft::t('stopsign', 'The lock timeout must be at least three times the heartbeat interval, or a lock will expire under its own holder.'));
        }
    }

    public function attributeLabels(): array
    {
        return [
            'heartbeatSeconds' => Craft::t('stopsign', 'Heartbeat interval'),
            'presenceTtlSeconds' => Craft::t('stopsign', 'Presence window'),
            'lockTtlSeconds' => Craft::t('stopsign', 'Lock timeout'),
            'historyRetentionDays' => Craft::t('stopsign', 'History retention'),
        ];
    }

    /** @return array<int, array{label: string, value: string}> */
    public static function lockModeOptions(): array
    {
        return [
            ['label' => Craft::t('stopsign', 'Off — warn only, never lock'), 'value' => self::LOCK_MODE_OFF],
            ['label' => Craft::t('stopsign', 'Chosen sections only'), 'value' => self::LOCK_MODE_SECTIONS],
            ['label' => Craft::t('stopsign', 'Everything Stop Sign watches'), 'value' => self::LOCK_MODE_ALL],
        ];
    }

    /** @return array<int, array{label: string, value: string}> */
    public static function bannerStyleOptions(): array
    {
        return [
            ['label' => Craft::t('stopsign', 'Loud — a full-width banner'), 'value' => self::STYLE_LOUD],
            ['label' => Craft::t('stopsign', 'Subtle — a single line'), 'value' => self::STYLE_SUBTLE],
        ];
    }
}
