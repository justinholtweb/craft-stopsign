<?php

namespace justinholtweb\stopsign\migrations;

use craft\db\Migration;
use craft\db\Table;
use justinholtweb\stopsign\records\CollisionRecord;
use justinholtweb\stopsign\records\LockRecord;
use justinholtweb\stopsign\records\PresenceRecord;
use justinholtweb\stopsign\records\SaveRecord;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(CollisionRecord::TABLE);
        $this->dropTableIfExists(SaveRecord::TABLE);
        $this->dropTableIfExists(LockRecord::TABLE);
        $this->dropTableIfExists(PresenceRecord::TABLE);

        return true;
    }

    private function createTables(): void
    {
        $this->createTable(PresenceRecord::TABLE, [
            'id' => $this->primaryKey(),
            'elementId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'userId' => $this->integer()->notNull(),
            'elementType' => $this->string()->notNull(),
            'draftId' => $this->integer(),
            'provisional' => $this->boolean()->notNull()->defaultValue(false),

            // Per browser tab, not per user. Two tabs of the same account are a genuine way to
            // lose work and are reported as such.
            'sessionToken' => $this->char(32)->notNull(),

            'intent' => $this->string(16)->notNull()->defaultValue('viewing'),
            'dirty' => $this->boolean()->notNull()->defaultValue(false),
            'firstSeen' => $this->dateTime()->notNull(),
            'lastSeen' => $this->dateTime()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(LockRecord::TABLE, [
            'id' => $this->primaryKey(),
            'elementId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'userId' => $this->integer()->notNull(),
            'sessionToken' => $this->char(32)->notNull(),
            'expiryDate' => $this->dateTime()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(SaveRecord::TABLE, [
            'id' => $this->primaryKey(),
            'elementId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'userId' => $this->integer()->notNull(),
            'savedAt' => $this->dateTime()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(CollisionRecord::TABLE, [
            'id' => $this->primaryKey(),
            'elementId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'elementType' => $this->string()->notNull(),
            'userId' => $this->integer()->notNull(),
            'otherUserId' => $this->integer(),
            'kind' => $this->string(16)->notNull(),
            'outcome' => $this->string(16)->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function createIndexes(): void
    {
        // One row per tab. The upsert on every heartbeat depends on this being unique, and
        // `elementId` leading it also serves the “who else is in this element” read.
        $this->createIndex(null, PresenceRecord::TABLE, ['elementId', 'siteId', 'userId', 'sessionToken'], true);
        $this->createIndex(null, PresenceRecord::TABLE, ['lastSeen'], false);
        $this->createIndex(null, PresenceRecord::TABLE, ['userId'], false);

        // One lock per element per site, enforced by the database rather than by a read followed
        // by a write — two people clicking into the same entry in the same second is exactly the
        // case this plugin exists for, so the race has to be lost in the index, not in PHP.
        $this->createIndex(null, LockRecord::TABLE, ['elementId', 'siteId'], true);
        $this->createIndex(null, LockRecord::TABLE, ['expiryDate'], false);

        $this->createIndex(null, SaveRecord::TABLE, ['elementId', 'siteId'], true);

        $this->createIndex(null, CollisionRecord::TABLE, ['elementId', 'siteId'], false);
        $this->createIndex(null, CollisionRecord::TABLE, ['dateCreated'], false);
        $this->createIndex(null, CollisionRecord::TABLE, ['userId'], false);
    }

    private function addForeignKeys(): void
    {
        // Presence, locks and last-save rows are about a live element; when it goes, they go.
        foreach ([PresenceRecord::TABLE, LockRecord::TABLE, SaveRecord::TABLE] as $table) {
            $this->addForeignKey(null, $table, ['elementId'], Table::ELEMENTS, ['id'], 'CASCADE');
            $this->addForeignKey(null, $table, ['siteId'], Table::SITES, ['id'], 'CASCADE');
            $this->addForeignKey(null, $table, ['userId'], Table::USERS, ['id'], 'CASCADE');
        }

        // Collisions are a history, so they deliberately do *not* hang off the element: deleting
        // an entry must not quietly erase the record that two people fought over it. Only the
        // users are linked, and even then a deleted account nulls out rather than taking the row
        // with it.
        $this->addForeignKey(null, CollisionRecord::TABLE, ['userId'], Table::USERS, ['id'], 'CASCADE');
        $this->addForeignKey(null, CollisionRecord::TABLE, ['otherUserId'], Table::USERS, ['id'], 'SET NULL');
    }
}
