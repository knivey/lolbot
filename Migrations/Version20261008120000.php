<?php

declare(strict_types=1);

namespace lolbot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the channel_settings and user_settings tables (settings-registry
 * persistence: scoped key/value rows for channels and users, with the
 * network tier expressed as a NULL channel_id).
 *
 * Uses the portable schema-comparator technique (mirroring
 * Version20260928120000.php) so the generated CREATE statements work on
 * both SQLite (tests/dev) and Postgres (prod).
 */
final class Version20261008120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add channel_settings and user_settings tables';
    }

    public function up(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();
        $comp = $sm->createComparator();
        $newSchema = clone $schema;

        $settings = $newSchema->createTable("channel_settings");
        $settings->addColumn("id", Types::INTEGER)->setNotnull(true)->setAutoincrement(true);
        $settings->setPrimaryKey(["id"]);
        $settings->addColumn("network_id", Types::INTEGER)->setNotnull(true);
        $settings->addForeignKeyConstraint("Networks", ["network_id"], ["id"], ["onDelete" => "CASCADE"]);
        // NULL channel_id = network-tier setting.
        $settings->addColumn("channel_id", Types::INTEGER)->setNotnull(false);
        $settings->addForeignKeyConstraint("Channels", ["channel_id"], ["id"], ["onDelete" => "CASCADE"]);
        $settings->addColumn("setting_key", Types::STRING)->setNotnull(true);
        $settings->addColumn("value", Types::JSON)->setNotnull(true);
        $settings->addColumn("updated", Types::DATETIME_IMMUTABLE)->setNotnull(false);
        // One value per scope (network, optional channel, key).
        $settings->addUniqueIndex(["network_id", "channel_id", "setting_key"], "channel_settings_scope_uniq");

        $userSettings = $newSchema->createTable("user_settings");
        $userSettings->addColumn("id", Types::INTEGER)->setNotnull(true)->setAutoincrement(true);
        $userSettings->setPrimaryKey(["id"]);
        $userSettings->addColumn("user_id", Types::INTEGER)->setNotnull(true);
        $userSettings->addForeignKeyConstraint("users", ["user_id"], ["id"], ["onDelete" => "CASCADE"]);
        $userSettings->addColumn("setting_key", Types::STRING)->setNotnull(true);
        $userSettings->addColumn("value", Types::JSON)->setNotnull(true);
        $userSettings->addColumn("updated", Types::DATETIME_IMMUTABLE)->setNotnull(false);
        // One value per (user, key).
        $userSettings->addUniqueIndex(["user_id", "setting_key"], "user_settings_scope_uniq");

        $diff = $comp->compareSchemas($schema, $newSchema);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();
        $comp = $sm->createComparator();
        $newSchema = clone $schema;

        $newSchema->dropTable("channel_settings");
        $newSchema->dropTable("user_settings");

        $diff = $comp->compareSchemas($schema, $newSchema);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql) {
            $this->addSql($sql);
        }
    }
}
