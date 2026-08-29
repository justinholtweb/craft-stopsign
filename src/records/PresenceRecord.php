<?php

namespace justinholtweb\stopsign\records;

use craft\db\ActiveRecord;
use DateTime;

/**
 * One open editor. Not one user — one *tab*.
 *
 * Keying on a per-tab session token rather than on the user is what lets Stop Sign say “you have
 * this open in another tab”, which is a real way to lose work and the one collision Craft’s own
 * activity feature can never report: it excludes your own user id from the results outright.
 *
 * @property int $id
 * @property int $elementId Always the *canonical* id, so a draft and its canonical collide.
 * @property int $siteId
 * @property int $userId
 * @property string $elementType
 * @property int|null $draftId
 * @property bool $provisional
 * @property string $sessionToken
 * @property string $intent `viewing` or `editing`
 * @property bool $dirty Whether the form has unsaved changes.
 * @property DateTime $firstSeen
 * @property DateTime $lastSeen
 */
class PresenceRecord extends ActiveRecord
{
    public const TABLE = '{{%stopsign_presence}}';

    public const INTENT_VIEWING = 'viewing';
    public const INTENT_EDITING = 'editing';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
