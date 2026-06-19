<?php

declare(strict_types=1);

namespace lolbot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260619120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ai_service_config and paste_service_config tables; expand linktitles_settings';
    }

    public function up(Schema $schema): void
    {
        $ai = $schema->createTable("ai_service_config");
        $ai->addColumn("id", Types::INTEGER)->setNotnull(true)->setAutoincrement(true);
        $ai->setPrimaryKey(["id"]);
        $ai->addColumn("api_key", Types::STRING)->setLength(512)->setNotnull(false);
        $ai->addColumn("base_url", Types::STRING)->setNotnull(false);
        $ai->addColumn("max_dim", Types::INTEGER)->setNotnull(true)->setDefault(1024);
        $ai->addColumn("jpg_quality", Types::INTEGER)->setNotnull(true)->setDefault(85);
        $ai->addColumn("timeout", Types::INTEGER)->setNotnull(true)->setDefault(10);
        $ai->addColumn("reasoning_effort", Types::STRING)->setLength(32)->setNotnull(false);
        $ai->addColumn("reasoning", Types::JSON)->setNotnull(false);

        $paste = $schema->createTable("paste_service_config");
        $paste->addColumn("id", Types::INTEGER)->setNotnull(true)->setAutoincrement(true);
        $paste->setPrimaryKey(["id"]);
        $paste->addColumn("host", Types::STRING)->setNotnull(false);
        $paste->addColumn("key", Types::STRING)->setNotnull(false);

        // Expand linktitles_settings via comparator (portable ALTER), mirroring
        // Migrations/Version20260530120000.php.
        $sm = $this->connection->createSchemaManager();
        $comp = $sm->createComparator();
        $newSchema = clone $schema;

        $t = $newSchema->getTable("linktitles_settings");
        $t->addColumn("enabled", Types::BOOLEAN)->setNotnull(true)->setDefault(false);
        $t->addColumn("url_log_chan", Types::STRING)->setNotnull(false);
        $t->addColumn("ai_vision_model", Types::STRING)->setLength(64)->setNotnull(false);
        $t->addColumn("ai_vision_prompt", Types::TEXT)->setNotnull(false);
        $t->addColumn("ai_vision_reasoning_effort", Types::STRING)->setLength(32)->setNotnull(false);
        $t->addColumn("ai_vision_reasoning", Types::JSON)->setNotnull(false);

        $diff = $comp->compareSchemas($schema, $newSchema);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable("ai_service_config");
        $schema->dropTable("paste_service_config");

        $sm = $this->connection->createSchemaManager();
        $comp = $sm->createComparator();
        $newSchema = clone $schema;

        $t = $newSchema->getTable("linktitles_settings");
        foreach (["enabled", "url_log_chan", "ai_vision_model", "ai_vision_prompt", "ai_vision_reasoning_effort", "ai_vision_reasoning"] as $col) {
            $t->dropColumn($col);
        }

        $diff = $comp->compareSchemas($schema, $newSchema);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql) {
            $this->addSql($sql);
        }
    }
}
