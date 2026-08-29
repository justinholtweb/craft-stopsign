<?php

namespace justinholtweb\stopsign\records;

use craft\db\ActiveRecord;
use DateTime;

/**
 * Who last saved the canonical element, and when.
 *
 * Craft cannot answer this. `elements.dateUpdated` says *that* it changed; nothing on the
 * canonical row says by whom, and revisions — the only other record of it — can be switched off
 * per section. Without this table the staleness warning reads “this entry was updated”, which
 * tells an author nothing they can act on. With it, it names a colleague to go and talk to.
 *
 * One row per element per site, upserted.
 *
 * @property int $id
 * @property int $elementId
 * @property int $siteId
 * @property int $userId
 * @property DateTime $savedAt
 */
class SaveRecord extends ActiveRecord
{
    public const TABLE = '{{%stopsign_saves}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
