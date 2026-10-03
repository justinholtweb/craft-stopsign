<?php

namespace justinholtweb\stopsign\services;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use DateTime;
use DateTimeZone;
use justinholtweb\stopsign\records\CollisionRecord;
use justinholtweb\stopsign\records\SaveRecord;
use justinholtweb\stopsign\Plugin;
use yii\base\Component;

/**
 * The record of who nearly stood on whom, and the ledger of who last saved what.
 *
 * Both exist because Craft cannot answer the questions. `elementactivity` is garbage-collected at
 * sixty seconds, and nothing on a canonical element row says who last wrote it.
 */
class Collisions extends Component
{
    /** Notes a collision and what the author did about it. */
    public function record(
        ElementInterface $element,
        User $user,
        ?int $otherUserId,
        string $kind,
        string $outcome,
    ): void {
        if (!Plugin::getInstance()->getSettings()->recordHistory) {
            return;
        }

        $elementId = $element->getCanonicalId();

        if ($elementId === null) {
            return;
        }

        // The browser only asks for a "warned" row on the first warning of a stretch, but the
        // browser is not to be trusted with the size of the audit table. One warned row per
        // pairing per ten minutes is the same history for an honest client and a ceiling for a
        // scripted one.
        if ($outcome === CollisionRecord::OUTCOME_WARNED) {
            $recent = (new Query())
                ->from(CollisionRecord::TABLE)
                ->where([
                    'elementId' => $elementId,
                    'siteId' => $element->siteId,
                    'userId' => $user->id,
                    'otherUserId' => $otherUserId,
                    'kind' => $kind,
                    'outcome' => CollisionRecord::OUTCOME_WARNED,
                ])
                ->andWhere(['>', 'dateCreated', Db::prepareDateForDb(new DateTime('-10 minutes', new DateTimeZone('UTC')))])
                ->exists();

            if ($recent) {
                return;
            }
        }

        Db::insert(CollisionRecord::TABLE, [
            'elementId' => $elementId,
            'siteId' => $element->siteId,
            'elementType' => $element::class,
            'userId' => $user->id,

            // A user who no longer exists still collided with somebody. The foreign key is
            // `SET NULL` rather than `CASCADE` for the same reason.
            'otherUserId' => $otherUserId,
            'kind' => $kind,
            'outcome' => $outcome,
        ]);
    }

    /**
     * Notes who saved the canonical element.
     *
     * One row per element per site, overwritten. This is not a history of saves — Craft has
     * revisions for that — it is the single fact the staleness warning needs in order to say
     * “Dana saved this” rather than the useless “this was updated”.
     */
    public function recordSave(ElementInterface $element, User $user): void
    {
        $elementId = $element->getCanonicalId();

        if ($elementId === null) {
            return;
        }

        $now = Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')));

        Db::upsert(SaveRecord::TABLE, [
            'elementId' => $elementId,
            'siteId' => $element->siteId,
            'userId' => $user->id,
            'savedAt' => $now,
        ], [
            'userId' => $user->id,
            'savedAt' => $now,
        ]);
    }

    /**
     * Who last saved this element, if Stop Sign was watching when they did.
     *
     * @return array{userId: int, name: string, savedAt: DateTime}|null
     */
    public function lastSave(ElementInterface $element): ?array
    {
        $elementId = $element->getCanonicalId();

        if ($elementId === null) {
            return null;
        }

        // Deliberately not scoped to the viewer's site. `elements.dateUpdated` — the column the
        // staleness check compares against — is global, so a save made in one site marks the
        // element stale in *every* site. Asking only for this site's row would then leave a
        // perfectly correct warning unable to name anybody on a multi-site install, which is
        // most of the installs where two people editing at once is a daily event.
        $rows = (new Query())
            ->select(['userId', 'savedAt', 'siteId'])
            ->from(SaveRecord::TABLE)
            ->where(['elementId' => $elementId])
            ->orderBy(['savedAt' => SORT_DESC])
            ->all();

        if ($rows === []) {
            return null;
        }

        $row = $rows[0];

        // The viewer's own site wins over a newer save elsewhere: “Dana saved this” is more use
        // when it refers to the version on the screen.
        foreach ($rows as $candidate) {
            if ((int)$candidate['siteId'] === $element->siteId) {
                $row = $candidate;
                break;
            }
        }

        $user = Craft::$app->getUsers()->getUserById((int)$row['userId']);

        if (!$user) {
            return null;
        }

        return [
            'userId' => $user->id,
            'name' => $user->getName(),
            'savedAt' => new DateTime($row['savedAt'], new DateTimeZone('UTC')),
        ];
    }

    /**
     * Recent collisions, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 100, ?int $elementId = null): array
    {
        $query = (new Query())
            ->select(['id', 'elementId', 'siteId', 'elementType', 'userId', 'otherUserId', 'kind', 'outcome', 'dateCreated'])
            ->from(CollisionRecord::TABLE)
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit($limit);

        if ($elementId !== null) {
            $query->where(['elementId' => $elementId]);
        }

        $rows = $query->all();

        if ($rows === []) {
            return [];
        }

        $userIds = array_filter(array_merge(
            array_column($rows, 'userId'),
            array_column($rows, 'otherUserId'),
        ));

        $users = $userIds === []
            ? []
            : User::find()->id(array_unique($userIds))->status(null)->indexBy('id')->all();

        $utc = new DateTimeZone('UTC');
        $unknown = Craft::t('stopsign', 'Deleted user');

        return array_map(fn(array $row) => [
            'id' => (int)$row['id'],
            'elementId' => (int)$row['elementId'],
            'siteId' => (int)$row['siteId'],
            'elementType' => (string)$row['elementType'],
            'userId' => (int)$row['userId'],
            'userName' => $users[(int)$row['userId']]?->getName() ?? $unknown,
            'otherUserId' => $row['otherUserId'] !== null ? (int)$row['otherUserId'] : null,
            'otherUserName' => $row['otherUserId'] !== null
                ? ($users[(int)$row['otherUserId']]?->getName() ?? $unknown)
                : null,
            'kind' => (string)$row['kind'],
            'outcome' => (string)$row['outcome'],
            'dateCreated' => new DateTime($row['dateCreated'], $utc),
        ], $rows);
    }

    /** How many collisions of each outcome in the last N days. For the utility summary. */
    public function summary(int $days = 30): array
    {
        $rows = (new Query())
            ->select(['outcome', 'total' => 'COUNT(*)'])
            ->from(CollisionRecord::TABLE)
            ->where(['>', 'dateCreated', Db::prepareDateForDb(
                (new DateTime('now', new DateTimeZone('UTC')))->modify("-$days days"),
            )])
            ->groupBy(['outcome'])
            ->all();

        $summary = array_fill_keys([
            CollisionRecord::OUTCOME_WARNED,
            CollisionRecord::OUTCOME_PROCEEDED,
            CollisionRecord::OUTCOME_CANCELLED,
            CollisionRecord::OUTCOME_RELOADED,
            CollisionRecord::OUTCOME_TAKEN_OVER,
        ], 0);

        foreach ($rows as $row) {
            // `COUNT(*)` comes back from PDO as a *string*, and a string in a count reads fine
            // right up to the point something adds two of them together.
            $summary[$row['outcome']] = (int)$row['total'];
        }

        return $summary;
    }

    /** Drops history past the retention window. Zero days means keep everything. */
    public function prune(): int
    {
        $days = Plugin::getInstance()->getSettings()->historyRetentionDays;

        if ($days <= 0) {
            return 0;
        }

        return Db::delete(CollisionRecord::TABLE, ['<', 'dateCreated', Db::prepareDateForDb(
            (new DateTime('now', new DateTimeZone('UTC')))->modify("-$days days"),
        )]);
    }

    /** Drops save-ledger rows for elements nobody has touched in a long time. */
    public function pruneSaves(int $days = 180): int
    {
        return Db::delete(SaveRecord::TABLE, ['<', 'savedAt', Db::prepareDateForDb(
            (new DateTime('now', new DateTimeZone('UTC')))->modify("-$days days"),
        )]);
    }
}
