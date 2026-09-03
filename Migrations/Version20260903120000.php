<?php

declare(strict_types=1);

namespace lolbot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds Bots.disabled / Networks.disabled (NOT NULL, default false) so bots
 * can be taken offline per-bot or per-network without deleting them.
 *
 * Uses the portable schema-comparator technique (mirroring
 * Version20260621120000.php) so the generated ALTER statements work on both
 * SQLite (tests) and Postgres (prod).
 */
final class Version20260903120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add disabled bool columns to Bots and Networks';
    }

    public function up(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();
        $comp = $sm->createComparator();
        $newSchema = clone $schema;

        $bots = $newSchema->getTable("Bots");
        $bots->addColumn("disabled", Types::BOOLEAN)->setNotnull(true)->setDefault(false);

        $nets = $newSchema->getTable("Networks");
        $nets->addColumn("disabled", Types::BOOLEAN)->setNotnull(true)->setDefault(false);

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

        $bots = $newSchema->getTable("Bots");
        $bots->dropColumn("disabled");

        $nets = $newSchema->getTable("Networks");
        $nets->dropColumn("disabled");

        $diff = $comp->compareSchemas($schema, $newSchema);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql) {
            $this->addSql($sql);
        }
    }
}
