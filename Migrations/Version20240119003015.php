<?php

declare(strict_types=1);

namespace lolbot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240119003015 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'add linktitles db';
    }

    public function up(Schema $schema): void
    {
        $newSchemaManager = $this->connection->createSchemaManager();
        $comp = $newSchemaManager->createComparator();
        $newSchema = clone $schema;

        $linktitles_ignores = $newSchema->createTable("linktitles_ignores");
        $linktitles_ignores->addColumn("id", Types::INTEGER)->setNotnull(true)->setAutoincrement(true);
        $linktitles_ignores->setPrimaryKey(["id"]);
        $linktitles_ignores->addColumn("regex", Types::STRING)->setNotnull(true)->setLength(512);
        $linktitles_ignores->addColumn("type", Types::INTEGER)->setNotnull(true);
        $linktitles_ignores->addColumn("created", Types::DATETIME_IMMUTABLE);
        $linktitles_ignores->addColumn("network_id", Types::INTEGER)->setNotnull(false);
        $linktitles_ignores->addForeignKeyConstraint("Networks", ["network_id"], ["id"], ["onDelete" => "CASCADE"]);
        $linktitles_ignores->addColumn("bot_id", Types::INTEGER)->setNotnull(false);
        $linktitles_ignores->addForeignKeyConstraint("Bots", ["bot_id"], ["id"], ["onDelete" => "CASCADE"]);

        $linktitles_hostignores = $newSchema->createTable("linktitles_hostignores");
        $linktitles_hostignores->addColumn("id", Types::INTEGER)->setNotnull(true)->setAutoincrement(true);
        $linktitles_hostignores->setPrimaryKey(["id"]);
        $linktitles_hostignores->addColumn("hostmask", Types::STRING)->setNotnull(true)->setLength(512);
        $linktitles_hostignores->addColumn("type", Types::INTEGER)->setNotnull(true);
        $linktitles_hostignores->addColumn("created", Types::DATETIME_IMMUTABLE);
        $linktitles_hostignores->addColumn("network_id", Types::INTEGER)->setNotnull(false);;
        $linktitles_hostignores->addForeignKeyConstraint("Networks", ["network_id"], ["id"], ["onDelete" => "CASCADE"]);
        $linktitles_hostignores->addColumn("bot_id", Types::INTEGER)->setNotnull(false);;
        $linktitles_hostignores->addForeignKeyConstraint("Bots", ["bot_id"], ["id"], ["onDelete" => "CASCADE"]);

        $diff = $comp->compareSchemas($schema, $newSchema);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql)
            $this->addSql($sql);
    }

    public function down(Schema $schema): void
    {
        $newSchemaManager = $this->connection->createSchemaManager();
        $comp = $newSchemaManager->createComparator();
        $newSchema = clone $schema;

        $newSchema->dropTable("linktitles_ignores");
        $newSchema->dropTable("linktitles_hostignores");

        $diff = $comp->compareSchemas($schema, $newSchema);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql)
            $this->addSql($sql);
    }
}
