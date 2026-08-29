<?php

namespace justinholtweb\stopsign\services;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use DateTime;
use DateTimeZone;
use justinholtweb\stopsign\models\LockState;
use justinholtweb\stopsign\records\LockRecord;
use justinholtweb\stopsign\Plugin;
use yii\base\Component;
use yii\db\Exception as DbException;

/**
 * Soft locks: who has the conch.
 *
 * Three rules hold this together, and each of them exists because the failure it prevents is the
 * failure that makes people uninstall a locking plugin:
 *
 * - **A lock always expires.** It is renewed by the holder’s heartbeat, so closing the laptop
 *   releases it in `lockTtlSeconds`, not never.
 * - **A lock can always be taken over** (unless an admin has deliberately turned that off), and
 *   taking one over is recorded against the taker’s name.
 * - **A lock is claimed by the database, not by PHP.** Two people clicking into the same entry in
 *   the same second is the exact case this plugin exists for; a read-then-write would hand the
 *   lock to both of them.
 */
class Locks extends Component
{
    /** @var array<string, array|null> Live lock rows by `elementId:siteId`, or null for none. */
    private array $memo = [];

    /**
     * Claims the lock if it is going spare, and renews it if it is already ours.
     *
     * Returns the state either way — the caller wants to know who holds it, not whether this
     * particular call is what put them there.
     */
    public function claimOrRenew(ElementInterface $element, User $user, string $sessionToken): LockState
    {
        if (!Plugin::getInstance()->scope->locks($element)) {
            return LockState::notApplicable();
        }

        $elementId = $element->getCanonicalId();

        // An element that has never been saved has no id to lock, and two people cannot be in one
        // anyway — each “new entry” screen is its own unsaved element.
        if ($elementId === null) {
            return LockState::notApplicable();
        }
        $siteId = $element->siteId;
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $expiry = (clone $now)->modify(sprintf('+%d seconds', $this->ttl()));

        $existing = $this->row($elementId, $siteId);

        if ($existing !== null && (int)$existing['userId'] === $user->id && $existing['sessionToken'] === $sessionToken) {
            Db::update(LockRecord::TABLE, ['expiryDate' => Db::prepareDateForDb($expiry)], ['id' => $existing['id']]);
            $this->forget($elementId, $siteId);

            return $this->state($element, $user, $user->id, $user->getName(), $expiry);
        }

        if ($existing !== null) {
            // Held by somebody, and it has not expired — the row() call already filtered expired
            // rows out — so leave it alone and report who has it.
            return $this->stateForHolder($element, $user, $existing);
        }

        try {
            Db::insert(LockRecord::TABLE, [
                'elementId' => $elementId,
                'siteId' => $siteId,
                'userId' => $user->id,
                'sessionToken' => $sessionToken,
                'expiryDate' => Db::prepareDateForDb($expiry),
            ]);
        } catch (DbException) {
            // Somebody claimed it between the read and the insert. That is the unique index doing
            // its job, and the correct response is to accept their claim and re-read — not to
            // retry, and certainly not to delete theirs and insert ours.
            $this->forget($elementId, $siteId);
            $winner = $this->row($elementId, $siteId);

            return $winner !== null
                ? $this->stateForHolder($element, $user, $winner)
                : LockState::notApplicable();
        }

        $this->forget($elementId, $siteId);

        return $this->state($element, $user, $user->id, $user->getName(), $expiry);
    }

    /** The current state without claiming anything. Used by the save guard and by `canSave()`. */
    public function state(
        ElementInterface $element,
        User $viewer,
        ?int $holderId = null,
        ?string $holderName = null,
        ?DateTime $expiry = null,
    ): LockState {
        if (!Plugin::getInstance()->scope->locks($element)) {
            return LockState::notApplicable();
        }

        if ($holderId === null) {
            $elementId = $element->getCanonicalId();

            if ($elementId === null) {
                return LockState::notApplicable();
            }

            $row = $this->row($elementId, $element->siteId);

            if ($row === null) {
                return new LockState(
                    applicable: true,
                    enforced: Plugin::getInstance()->getSettings()->enforceLocks,
                );
            }

            return $this->stateForHolder($element, $viewer, $row);
        }

        return new LockState(
            applicable: true,
            holderId: $holderId,
            holderName: $holderName,
            isMine: $holderId === $viewer->id,
            expiryDate: $expiry,
            canTakeOver: false,
            enforced: Plugin::getInstance()->getSettings()->enforceLocks,
        );
    }

    /**
     * Hands the lock to somebody else, deliberately.
     *
     * @return bool Whether the caller now holds it.
     */
    public function takeOver(ElementInterface $element, User $user, string $sessionToken): bool
    {
        if (!$this->canTakeOver($element, $user)) {
            return false;
        }

        $elementId = $element->getCanonicalId();
        $siteId = $element->siteId;
        $expiry = (new DateTime('now', new DateTimeZone('UTC')))->modify(sprintf('+%d seconds', $this->ttl()));

        Db::delete(LockRecord::TABLE, ['elementId' => $elementId, 'siteId' => $siteId]);

        try {
            Db::insert(LockRecord::TABLE, [
                'elementId' => $elementId,
                'siteId' => $siteId,
                'userId' => $user->id,
                'sessionToken' => $sessionToken,
                'expiryDate' => Db::prepareDateForDb($expiry),
            ]);
        } catch (DbException) {
            $this->forget($elementId, $siteId);

            return false;
        }

        $this->forget($elementId, $siteId);

        return true;
    }

    /** Gives up a lock we hold. A no-op if we do not hold it — releasing is not a way to steal. */
    public function release(ElementInterface $element, User $user, string $sessionToken): void
    {
        Db::delete(LockRecord::TABLE, [
            'elementId' => $element->getCanonicalId(),
            'siteId' => $element->siteId,
            'userId' => $user->id,
            'sessionToken' => $sessionToken,
        ]);

        $this->forget($element->getCanonicalId(), $element->siteId);
    }

    /**
     * Whether this user may take a lock away from whoever has it.
     *
     * Admins are exempt from the group restriction when `adminsBypassLocks` is on, which is the
     * setting that keeps a misconfigured group list from locking a site’s owner out of their own
     * content at the worst possible moment.
     */
    public function canTakeOver(ElementInterface $element, User $user): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!Plugin::getInstance()->scope->locks($element)) {
            return false;
        }

        if ($user->admin && $settings->adminsBypassLocks) {
            return true;
        }

        if (!$settings->allowTakeOver) {
            return false;
        }

        if ($settings->takeOverGroups === []) {
            return true;
        }

        foreach ($settings->takeOverGroups as $handle) {
            if ($user->isInGroup($handle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this user is shut out of saving this element.
     *
     * The one question `canSave()` asks, so it has to be cheap and it has to be memoised — Craft
     * calls `canSave()` once per element on every index screen.
     */
    public function blocksSave(ElementInterface $element, User $user): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->enforceLocks) {
            return false;
        }

        if ($user->admin && $settings->adminsBypassLocks) {
            return false;
        }

        $state = $this->state($element, $user);

        return $state->isHeldByAnotherUser();
    }

    /** Drops every lock on the site. The kill switch; also the console command’s whole job. */
    public function releaseAll(): int
    {
        $this->memo = [];

        return Db::delete(LockRecord::TABLE, []);
    }

    /** Drops locks whose holder stopped beating. Run from garbage collection. */
    public function prune(): int
    {
        $this->memo = [];

        return Db::delete(LockRecord::TABLE, ['<', 'expiryDate', Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')))]);
    }

    /** @return array<int, array{elementId: int, siteId: int, userId: int, userName: string, expiryDate: DateTime}> */
    public function all(): array
    {
        $rows = (new Query())
            ->select(['elementId', 'siteId', 'userId', 'expiryDate'])
            ->from(LockRecord::TABLE)
            ->where(['>', 'expiryDate', Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')))])
            ->orderBy(['expiryDate' => SORT_DESC])
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

        return array_map(fn(array $row) => [
            'elementId' => (int)$row['elementId'],
            'siteId' => (int)$row['siteId'],
            'userId' => (int)$row['userId'],
            'userName' => $users[(int)$row['userId']]?->getName() ?? Craft::t('stopsign', 'Deleted user'),
            'expiryDate' => new DateTime($row['expiryDate'], $utc),
        ], $rows);
    }

    private function stateForHolder(ElementInterface $element, User $viewer, array $row): LockState
    {
        $holderId = (int)$row['userId'];
        $holder = $holderId === $viewer->id ? $viewer : Craft::$app->getUsers()->getUserById($holderId);

        return new LockState(
            applicable: true,
            holderId: $holderId,
            holderName: $holder?->getName() ?? Craft::t('stopsign', 'Deleted user'),
            isMine: $holderId === $viewer->id,
            expiryDate: new DateTime($row['expiryDate'], new DateTimeZone('UTC')),
            canTakeOver: $holderId !== $viewer->id && $this->canTakeOver($element, $viewer),
            enforced: Plugin::getInstance()->getSettings()->enforceLocks,
        );
    }

    /** The live lock row for an element, or null. Expired rows are not live. */
    private function row(int $elementId, int $siteId): ?array
    {
        $key = "$elementId:$siteId";

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        return $this->memo[$key] = (new Query())
            ->select(['id', 'userId', 'sessionToken', 'expiryDate'])
            ->from(LockRecord::TABLE)
            ->where(['elementId' => $elementId, 'siteId' => $siteId])
            ->andWhere(['>', 'expiryDate', Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')))])
            ->one() ?: null;
    }

    private function forget(int $elementId, int $siteId): void
    {
        unset($this->memo["$elementId:$siteId"]);
    }

    private function ttl(): int
    {
        return max(30, Plugin::getInstance()->getSettings()->lockTtlSeconds);
    }
}
