<?php

namespace justinholtweb\stopsign\conditions;

use Craft;
use craft\base\conditions\BaseLightswitchConditionRule;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Db;
use DateTime;
use DateTimeZone;
use justinholtweb\stopsign\Plugin;
use justinholtweb\stopsign\records\LockRecord;

/**
 * “Is locked” — so editors can build a “currently locked” source on any element index.
 *
 * Registered on every element condition, whatever the settings say. A rule Craft no longer
 * considers selectable is silently *dropped* from a saved condition rather than refused, so a
 * custom source built on this rule would quietly turn into “everything” the day somebody switched
 * locking off. Always registered, it keeps meaning what it says: with locking off nothing is
 * locked, and the source is empty.
 *
 * Matches the lock on the element's own site — locks are per site — and on the canonical element,
 * so a drafts source finds the drafts of locked entries too. Expired locks do not count, exactly
 * as they do not count anywhere else.
 */
class IsLockedConditionRule extends BaseLightswitchConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('stopsign', 'Is locked');
    }

    public function getExclusiveQueryParams(): array
    {
        return [];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        $locked = (new Query())
            ->from(['stopsign_locks' => LockRecord::TABLE])
            ->where('[[stopsign_locks.elementId]] = COALESCE([[elements.canonicalId]], [[elements.id]])')
            ->andWhere('[[stopsign_locks.siteId]] = [[elements_sites.siteId]]')
            ->andWhere(['>', 'stopsign_locks.expiryDate', Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')))]);

        /** @var \craft\elements\db\ElementQuery $query */
        $query->andWhere([$this->value ? 'exists' : 'not exists', $locked]);
    }

    public function matchElement(ElementInterface $element): bool
    {
        $canonicalId = $element->getCanonicalId();
        $locked = $canonicalId !== null
            && Plugin::getInstance()->locks->holderOf($canonicalId, (int)$element->siteId) !== null;

        return $this->matchValue($locked);
    }
}
