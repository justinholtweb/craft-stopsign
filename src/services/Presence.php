<?php

namespace justinholtweb\stopsign\services;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use DateTime;
use DateTimeZone;
use justinholtweb\stopsign\models\Occupant;
use justinholtweb\stopsign\records\PresenceRecord;
use justinholtweb\stopsign\Plugin;
use yii\base\Component;

/**
 * Who is in what, right now.
 *
 * Craft has a table for this already — `elementactivity` — and Stop Sign deliberately does not
 * use it. Two reasons, both fatal:
 *
 * 1. Craft’s garbage collector deletes every row older than **sixty seconds**, so the table can
 *    never answer anything but “in the last minute”, and the window is not configurable.
 * 2. It is keyed per user, so it cannot see the same account in two tabs — and
 *    `getRecentActivity()` excludes the current user outright.
 *
 * Writing our own rows also means the presence window, the heartbeat rate and the pruning are all
 * ours to set, and that an index screen can ask “which of these 100 entries has somebody in it”
 * in one query rather than a hundred.
 */
class Presence extends Component
{
    /** @var array<string, bool>|null Element ids with somebody in them, for the current request. */
    private ?array $activeIdMemo = null;

    /** @var array<int, array<int, array{name: string, dirty: bool}>>|null The index column's answer, for the current request. */
    private ?array $occupantMemo = null;

    /**
     * Records a heartbeat from one tab.
     *
     * An upsert against the unique `(elementId, siteId, userId, sessionToken)` index rather than a
     * read-then-write: two tabs beating in the same millisecond is routine, and the index is the
     * only place that race can be settled correctly.
     */
    public function beat(
        ElementInterface $element,
        User $user,
        string $sessionToken,
        string $intent,
        bool $dirty,
    ): void {
        $now = Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')));

        $intent = $intent === PresenceRecord::INTENT_EDITING
            ? PresenceRecord::INTENT_EDITING
            : PresenceRecord::INTENT_VIEWING;

        $key = [
            'elementId' => $element->getCanonicalId(),
            'siteId' => $element->siteId,
            'userId' => $user->id,
            'sessionToken' => $sessionToken,
        ];

        $moving = [
            'elementType' => $element::class,
            'draftId' => $element->getIsDraft() ? $element->draftId : null,
            'provisional' => $element->isProvisionalDraft,
            'intent' => $intent,
            'dirty' => $dirty,
            'lastSeen' => $now,
        ];

        // The update half is spelled out rather than left to `true`, which would copy the whole
        // insert half across. `firstSeen` is the difference: rewriting it on every beat would
        // reset “has been here twelve minutes” to zero ten times a minute — silently, and in a
        // way no screen would ever show as wrong, because the number is always plausible.
        Db::upsert(PresenceRecord::TABLE, $key + $moving + ['firstSeen' => $now], $moving);

        $this->forgetMemos();
    }

    /** Drops one tab’s row. Called on unload, and when a tab goes to the background. */
    public function release(ElementInterface $element, User $user, string $sessionToken): void
    {
        Db::delete(PresenceRecord::TABLE, [
            'elementId' => $element->getCanonicalId(),
            'siteId' => $element->siteId,
            'userId' => $user->id,
            'sessionToken' => $sessionToken,
        ]);

        $this->forgetMemos();
    }

    /**
     * Everyone else in this element, split into other people and the viewer’s own other tabs.
     *
     * @return array{others: Occupant[], ownTabs: Occupant[]}
     */
    public function occupants(ElementInterface $element, User $viewer, string $sessionToken): array
    {
        $rows = (new Query())
            ->select(['userId', 'intent', 'dirty', 'draftId', 'provisional', 'firstSeen', 'lastSeen', 'sessionToken'])
            ->from(PresenceRecord::TABLE)
            ->where([
                'elementId' => $element->getCanonicalId(),
                'siteId' => $element->siteId,
            ])
            ->andWhere(['>', 'lastSeen', $this->horizon()])
            ->andWhere(['not', ['sessionToken' => $sessionToken]])
            ->orderBy(['lastSeen' => SORT_DESC])
            ->all();

        if ($rows === []) {
            return ['others' => [], 'ownTabs' => []];
        }

        $users = User::find()
            ->id(array_unique(array_column($rows, 'userId')))
            ->status(null)
            ->indexBy('id')
            ->all();

        $others = [];
        $ownTabs = [];
        $seenUserIds = [];

        foreach ($rows as $row) {
            $userId = (int)$row['userId'];

            if (!isset($users[$userId])) {
                continue;
            }

            if ($userId === $viewer->id) {
                // Every extra tab of your own is the same warning, so one is enough.
                if ($ownTabs === []) {
                    $ownTabs[] = Occupant::fromUser($users[$userId], $row, true);
                }
                continue;
            }

            // One entry per colleague, not per tab of theirs — “Dana is editing this” twice reads
            // as two Danas. The rows arrive newest-first, and an editing row must win over a
            // viewing one, so a later row only replaces an earlier one when it is more serious.
            if (isset($seenUserIds[$userId])) {
                $existing = $others[$seenUserIds[$userId]];

                if ($existing->dirty || !$row['dirty']) {
                    continue;
                }

                $others[$seenUserIds[$userId]] = Occupant::fromUser($users[$userId], $row, false);
                continue;
            }

            $seenUserIds[$userId] = count($others);
            $others[] = Occupant::fromUser($users[$userId], $row, false);
        }

        return ['others' => $others, 'ownTabs' => $ownTabs];
    }

    /**
     * Whether anybody at all is in a given element, for index badges.
     *
     * One query for the whole request, not one per chip. An element index renders 100 chips and
     * relation fields render more; asking per element would put a query behind every row on the
     * busiest screen in the control panel.
     */
    public function isOccupied(int $canonicalId): bool
    {
        $this->activeIdMemo ??= array_fill_keys(
            (new Query())
                ->select(['elementId'])
                ->distinct()
                ->from(PresenceRecord::TABLE)
                ->where(['>', 'lastSeen', $this->horizon()])
                ->column(),
            true,
        );

        return isset($this->activeIdMemo[$canonicalId]);
    }

    /**
     * Who is in a given element, for the “Being edited by” index column.
     *
     * Same shape of answer as `isOccupied()` and for the same reason: one query (and one user
     * query) for the whole request, never one per row. Across every site, like the chip badge it
     * sits beside — a column that said “nobody” next to a badge that said “somebody” would teach
     * people to trust neither.
     *
     * @return array<int, array{name: string, dirty: bool}> Keyed by user id; people with unsaved
     *     changes first.
     */
    public function occupantsOf(int $canonicalId): array
    {
        if ($this->occupantMemo === null) {
            $rows = (new Query())
                ->select(['elementId', 'userId', 'dirty'])
                ->from(PresenceRecord::TABLE)
                ->where(['>', 'lastSeen', $this->horizon()])
                ->orderBy(['lastSeen' => SORT_DESC])
                ->all();

            $users = $rows === [] ? [] : User::find()
                ->id(array_unique(array_column($rows, 'userId')))
                ->status(null)
                ->indexBy('id')
                ->all();

            $memo = [];

            foreach ($rows as $row) {
                $userId = (int)$row['userId'];

                if (!isset($users[$userId])) {
                    continue;
                }

                // One entry per person however many tabs they have, and a tab with unsaved
                // changes wins over one without.
                $existing = $memo[(int)$row['elementId']][$userId] ?? null;
                $memo[(int)$row['elementId']][$userId] = [
                    'name' => $users[$userId]->getName(),
                    'dirty' => ($existing['dirty'] ?? false) || (bool)$row['dirty'],
                ];
            }

            foreach ($memo as &$occupants) {
                uasort($occupants, fn(array $a, array $b) => $b['dirty'] <=> $a['dirty']);
            }
            unset($occupants);

            $this->occupantMemo = $memo;
        }

        return $this->occupantMemo[$canonicalId] ?? [];
    }

    /**
     * Everything currently open, newest first, for the utility screen.
     *
     * @return array<int, array{userId: int, userName: string, elementId: int, siteId: int, elementType: string, intent: string, dirty: bool, firstSeen: DateTime, lastSeen: DateTime}>
     */
    public function board(): array
    {
        $rows = (new Query())
            ->select(['elementId', 'siteId', 'userId', 'elementType', 'intent', 'dirty', 'firstSeen', 'lastSeen'])
            ->from(PresenceRecord::TABLE)
            ->where(['>', 'lastSeen', $this->horizon()])
            ->orderBy(['lastSeen' => SORT_DESC])
            ->all();

        if ($rows === []) {
            return [];
        }

        $users = User::find()
            ->id(array_unique(array_column($rows, 'userId')))
            ->status(null)
            ->indexBy('id')
            ->all();

        $utc = new DateTimeZone('UTC');

        return array_values(array_map(fn(array $row) => [
            'userId' => (int)$row['userId'],
            'userName' => $users[(int)$row['userId']]?->getName() ?? Craft::t('stopsign', 'Deleted user'),
            'elementId' => (int)$row['elementId'],
            'siteId' => (int)$row['siteId'],
            'elementType' => (string)$row['elementType'],
            'intent' => (string)$row['intent'],
            'dirty' => (bool)$row['dirty'],
            'firstSeen' => new DateTime($row['firstSeen'], $utc),
            'lastSeen' => new DateTime($row['lastSeen'], $utc),
        ], array_filter($rows, fn(array $row) => isset($users[(int)$row['userId']]))));
    }

    /** Deletes rows nobody can be behind any more. Run from garbage collection and the console. */
    public function prune(): int
    {
        $this->forgetMemos();

        // Ten times the presence window, not one: a row that is merely stale is harmless and
        // costs one indexed comparison, whereas deleting on the exact boundary throws away the
        // evidence that would explain a warning somebody is standing in front of asking about.
        $cutoff = Db::prepareDateForDb(
            (new DateTime('now', new DateTimeZone('UTC')))
                ->modify(sprintf('-%d seconds', $this->settingsTtl() * 10)),
        );

        return Db::delete(PresenceRecord::TABLE, ['<', 'lastSeen', $cutoff]);
    }

    private function forgetMemos(): void
    {
        $this->activeIdMemo = null;
        $this->occupantMemo = null;
    }

    /** The moment before which a heartbeat no longer counts as somebody being here. */
    private function horizon(): string
    {
        return Db::prepareDateForDb(
            (new DateTime('now', new DateTimeZone('UTC')))
                ->modify(sprintf('-%d seconds', $this->settingsTtl())),
        );
    }

    private function settingsTtl(): int
    {
        return max(10, Plugin::getInstance()->getSettings()->presenceTtlSeconds);
    }
}
