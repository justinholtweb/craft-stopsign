<?php

namespace justinholtweb\stopsign\records;

use craft\db\ActiveRecord;
use DateTime;

/**
 * A soft lock on one element, in one site.
 *
 * Soft because it always expires and can always be taken over. A lock that outlives the browser
 * tab that claimed it is how “locked entries” plugins end up needing a database console at 6pm,
 * so `expiryDate` is short and renewed by the holder’s heartbeat rather than by their intent.
 *
 * @property int $id
 * @property int $elementId
 * @property int $siteId
 * @property int $userId
 * @property string $sessionToken
 * @property DateTime $expiryDate
 * @property DateTime $dateCreated
 */
class LockRecord extends ActiveRecord
{
    public const TABLE = '{{%stopsign_locks}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
