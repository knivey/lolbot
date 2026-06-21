<?php

declare(strict_types=1);

namespace lolbot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Makes linktitles_settings.enabled / ai_vision_disabled nullable so a null
 * value means "inherit the next tier" under the global-defaults cascade, and
 * drops the ai_service_config reasoning columns now that vision config lives
 * entirely under linktitles.
 *
 * Uses the portable schema-comparator technique (mirroring
 * Version20260619120000.php / Version20260530120000.php) so the generated
 * ALTER statements work on both SQLite (tests) and Postgres (prod).
 */
final class Version20260621120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make linktitles bool columns nullable (inherit semantics); drop ai_service_config reasoning columns';
    }

    public function up(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();
        $comp = $sm->createComparator();
        $newSchema = clone $schema;

        $lt = $newSchema->getTable("linktitles_settings");
        $lt->getColumn("enabled")->setNotnull(false)->setDefault(null);
        $lt->getColumn("ai_vision_disabled")->setNotnull(false)->setDefault(null);

        $ai = $newSchema->getTable("ai_service_config");
        $ai->dropColumn("reasoning_effort");
        $ai->dropColumn("reasoning");

        $diff = $comp->compareSchemas($schema, $newSchema);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        // Backfill any NULL (inherited) rows before re-adding NOT NULL, or the
        // ALTER ... SET NOT NULL would fail on Postgres/SQLite if nulls exist.
        $this->addSql("UPDATE linktitles_settings SET enabled = FALSE WHERE enabled IS NULL");
        $this->addSql("UPDATE linktitles_settings SET ai_vision_disabled = FALSE WHERE ai_vision_disabled IS NULL");

        $sm = $this->connection->createSchemaManager();
        $comp = $sm->createComparator();
        $newSchema = clone $schema;

        $lt = $newSchema->getTable("linktitles_settings");
        $lt->getColumn("enabled")->setNotnull(true)->setDefault(false);
        $lt->getColumn("ai_vision_disabled")->setNotnull(true)->setDefault(false);

        $ai = $newSchema->getTable("ai_service_config");
        $ai->addColumn("reasoning_effort", Types::STRING)->setLength(32)->setNotnull(false);
        $ai->addColumn("reasoning", Types::JSON)->setNotnull(false);

        $diff = $comp->compareSchemas($schema, $newSchema);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql) {
            $this->addSql($sql);
        }
    }
}
