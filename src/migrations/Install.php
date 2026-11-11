<?php

namespace seomatic\craftai\migrations;

use craft\db\Migration;
use seomatic\craftai\Plugin;
use seomatic\craftai\services\Keys;

/**
 * Creates the plugin's own tables. Everything SEOmatic writes lives here
 * (never in project config, which is committed to git), so uninstalling
 * the plugin removes it cleanly.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();
        $this->insertKey();
        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Plugin::TABLE_REDIRECTS);
        $this->dropTableIfExists(Plugin::TABLE_ROBOTS);
        $this->dropTableIfExists(Plugin::TABLE_SCHEMA);
        $this->dropTableIfExists(Plugin::TABLE_META);
        $this->dropTableIfExists(Plugin::TABLE_SETTINGS);
        return true;
    }

    private function createTables(): void
    {
        // Key/value store for the plugin's own settings (the API key).
        $this->createTable(Plugin::TABLE_SETTINGS, [
            'id' => $this->primaryKey(),
            'name' => $this->string(64)->notNull(),
            'value' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Meta overrides per entry and site (used only when the entry has
        // no nystudio107 SEOmatic "SEO Settings" field).
        $this->createTable(Plugin::TABLE_META, [
            'id' => $this->primaryKey(),
            'entryId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'title' => $this->text(),
            'description' => $this->text(),
            'canonical' => $this->text(),
            'noindex' => $this->boolean()->notNull()->defaultValue(false),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // JSON-LD per entry and site; entryId null = site-wide set.
        $this->createTable(Plugin::TABLE_SCHEMA, [
            'id' => $this->primaryKey(),
            'entryId' => $this->integer(),
            'siteId' => $this->integer()->notNull(),
            'items' => $this->mediumText()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Redirects; siteId null = every site. sourceHash = sha1(normalized
        // source) keeps the index short on MySQL utf8mb4. Uniqueness of
        // (siteId, source) is enforced in code, since SQL unique indexes
        // treat NULLs as distinct.
        $this->createTable(Plugin::TABLE_REDIRECTS, [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer(),
            'source' => $this->text()->notNull(),
            'sourceHash' => $this->char(40)->notNull(),
            'destination' => $this->text(),
            'statusCode' => $this->smallInteger()->notNull()->defaultValue(301),
            'hits' => $this->integer()->notNull()->defaultValue(0),
            'lastHit' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Extra robots.txt lines per site, in order.
        $this->createTable(Plugin::TABLE_ROBOTS, [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull(),
            'line' => $this->text()->notNull(),
            'sortOrder' => $this->smallInteger()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function createIndexes(): void
    {
        $this->createIndex(null, Plugin::TABLE_SETTINGS, ['name'], true);
        $this->createIndex(null, Plugin::TABLE_META, ['entryId', 'siteId'], true);
        $this->createIndex(null, Plugin::TABLE_SCHEMA, ['entryId', 'siteId'], false);
        $this->createIndex(null, Plugin::TABLE_SCHEMA, ['siteId'], false);
        $this->createIndex(null, Plugin::TABLE_REDIRECTS, ['sourceHash', 'siteId'], false);
        $this->createIndex(null, Plugin::TABLE_ROBOTS, ['siteId'], false);
    }

    private function addForeignKeys(): void
    {
        $this->addForeignKey(null, Plugin::TABLE_META, ['entryId'], '{{%elements}}', ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Plugin::TABLE_META, ['siteId'], '{{%sites}}', ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, Plugin::TABLE_SCHEMA, ['entryId'], '{{%elements}}', ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Plugin::TABLE_SCHEMA, ['siteId'], '{{%sites}}', ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, Plugin::TABLE_REDIRECTS, ['siteId'], '{{%sites}}', ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, Plugin::TABLE_ROBOTS, ['siteId'], '{{%sites}}', ['id'], 'CASCADE', 'CASCADE');
    }

    private function insertKey(): void
    {
        $this->insert(Plugin::TABLE_SETTINGS, [
            'name' => Keys::SETTING_NAME,
            'value' => Keys::generate(),
        ]);
    }
}
