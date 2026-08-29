<?php

namespace justinholtweb\stopsign\records;

use craft\db\ActiveRecord;
use DateTime;

/**
 * A collision that actually happened, and what the author did about it.
 *
 * Craft’s `elementactivity` table is garbage-collected at **sixty seconds**, so core keeps no
 * history whatsoever — the question “did anyone overwrite anyone last Tuesday?” has no answer on
 * a stock install. This is that answer.
 *
 * @property int $id
 * @property int $elementId
 * @property int $siteId
 * @property string $elementType
 * @property int $userId The author who was warned.
 * @property int|null $otherUserId The colleague they collided with, where there was one.
 * @property string $kind
 * @property string $outcome
 * @property DateTime $dateCreated
 */
class CollisionRecord extends ActiveRecord
{
    public const TABLE = '{{%stopsign_collisions}}';

    /** Somebody else was in the element at the same time. */
    public const KIND_CONCURRENT = 'concurrent';
    /** The canonical element had changed since this author loaded it. */
    public const KIND_STALE = 'stale';
    /** The author held no lock and one was held by someone else. */
    public const KIND_LOCKED = 'locked';
    /** The author took a lock away from its holder. */
    public const KIND_TAKEOVER = 'takeover';

    /** Warned, and no decision recorded yet — they are still sitting on the screen. */
    public const OUTCOME_WARNED = 'warned';
    /** Warned at save time and saved anyway. */
    public const OUTCOME_PROCEEDED = 'proceeded';
    /** Warned at save time and backed out. */
    public const OUTCOME_CANCELLED = 'cancelled';
    /** Warned about staleness and reloaded to pick up the other change. */
    public const OUTCOME_RELOADED = 'reloaded';
    /** A lock changed hands. */
    public const OUTCOME_TAKEN_OVER = 'takenOver';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
