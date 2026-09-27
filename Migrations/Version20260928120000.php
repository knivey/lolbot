<?php

declare(strict_types=1);

namespace lolbot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the channel_flags table (per-channel flag grants keyed by the
 * unique (channel_id, user_id) pair).
 *
 * Uses the portable schema-comparator technique (mirroring
 * Version20260927120000.php) so the generated CREATE statements work on
 * both SQLite (tests/dev) and Postgres (prod).
 */
final class Version20260928120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add channel_flags table';
    }

    public function up(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();
        $comp = $sm->createComparator();
        $newSchema = clone $schema;

        $flags = $newSchema->createTable("channel_flags");
        $flags->addColumn("id", Types::INTEGER)->setNotnull(true)->setAutoincrement(true);
        $flags->setPrimaryKey(["id"]);
        $flags->addColumn("channel_id", Types::INTEGER)->setNotnull(true);
        $flags->addForeignKeyConstraint("Channels", ["channel_id"], ["id"], ["onDelete" => "CASCADE"]);
        $flags->addColumn("user_id", Types::INTEGER)->setNotnull(true);
        $flags->addForeignKeyConstraint("users", ["user_id"], ["id"], ["onDelete" => "CASCADE"]);
        $flags->addColumn("flags", Types::JSON)->setNotnull(true);
        $flags->addColumn("added_by", Types::STRING)->setNotnull(true);
        $flags->addColumn("created", Types::DATETIME_IMMUTABLE)->setNotnull(true);
        // One grant row per (channel, user); flags are the list on it.
        $flags->addUniqueIndex(["channel_id", "user_id"], "channel_flags_channel_user_uniq");

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

        $newSchema->dropTable("channel_flags");

        $diff = $comp->compareSchemas($schema, $newSchema);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql) {
            $this->addSql($sql);
        }
    }
}
