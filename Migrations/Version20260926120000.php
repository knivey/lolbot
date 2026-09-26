<?php

declare(strict_types=1);

namespace lolbot\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the alias_history table and backfills every existing alias as its
 * version-1 'save' event.
 *
 * Uses the portable schema-comparator technique (mirroring
 * Version20260903120000.php) so the CREATE TABLE runs before the backfill
 * INSERT..SELECT: in doctrine/migrations 3.x, addSql() statements run ahead of
 * the auto-generated schema SQL, so the ordering must be explicit.
 */
final class Version20260926120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add alias_history table + backfill existing aliases as version 1';
    }

    public function up(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();
        $comp = $sm->createComparator();
        $newSchema = clone $schema;

        $t = $newSchema->createTable("alias_history");
        $t->addColumn("id", Types::INTEGER)->setNotnull(true)->setAutoincrement(true);
        $t->setPrimaryKey(["id"]);
        $t->addColumn("network_id", Types::INTEGER)->setNotnull(true);
        $t->addForeignKeyConstraint("Networks", ["network_id"], ["id"], ["onDelete" => "CASCADE"]);
        $t->addColumn("chan", Types::STRING)->setNotnull(true);
        $t->addColumn("chanLowered", Types::STRING)->setNotnull(true);
        $t->addColumn("name", Types::STRING)->setNotnull(true);
        $t->addColumn("nameLowered", Types::STRING)->setNotnull(true);
        $t->addColumn("value", Types::TEXT)->setNotnull(false);
        $t->addColumn("act", Types::BOOLEAN)->setNotnull(false);
        $t->addColumn("cmd", Types::STRING)->setNotnull(false);
        $t->addColumn("fullhost", Types::STRING)->setNotnull(true);
        $t->addColumn("created", Types::DATETIME_IMMUTABLE)->setNotnull(true);
        $t->addColumn("event", Types::STRING)->setNotnull(true);
        $t->addColumn("note", Types::TEXT)->setNotnull(false);
        // Per-alias timeline lookup: all events for one network+chan+name, newest last
        $t->addIndex(["network_id", "chanLowered", "nameLowered", "id"], "alias_history_timeline_idx");

        $diff = $comp->compareSchemas($schema, $newSchema);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql) {
            $this->addSql($sql);
        }

        // Backfill every existing alias as its version-1 'save' event, with the
        // migration-run timestamp as created. NOW() is postgres-only; sqlite and
        // others get the standard CURRENT_TIMESTAMP.
        $now = $this->platform instanceof PostgreSQLPlatform ? "NOW()" : "CURRENT_TIMESTAMP";
        $this->addSql(
            "INSERT INTO alias_history (network_id, chan, chanLowered, name, nameLowered, value, act, cmd, fullhost, created, event)
             SELECT network_id, chan, chanLowered, name, nameLowered, value, act, cmd, fullhost, {$now}, 'save'
             FROM alias_aliases"
        );
    }

    public function down(Schema $schema): void
    {
        // The backfill is not reversible; dropping the table discards the history.
        $schema->dropTable("alias_history");
    }
}
