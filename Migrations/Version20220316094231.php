<?php

declare(strict_types=1);

namespace lolbot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\DBAL\Types\Types;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20220316094231 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $newSchemaManager = $this->connection->createSchemaManager();
        $comp = $newSchemaManager->createComparator();
        $newSchema = clone $schema;

        $Ignores = $newSchema->createTable("Ignores");
        $Ignores->addColumn("id", Types::INTEGER)->setNotnull(true)->setAutoincrement(true);
        $Ignores->setPrimaryKey(["id"]);
        $Ignores->addColumn("hostmask", Types::STRING)->setNotnull(true)->setLength(512);
        $Ignores->addColumn("reason", Types::STRING)->setNotnull(false)->setDefault(null)->setLength(512);
        $Ignores->addColumn("created", Types::DATETIME_IMMUTABLE);

        $Bots = $newSchema->createTable("Bots");
        $Bots->addColumn("id", Types::INTEGER)->setNotnull(true)->setAutoincrement(true);
        $Bots->setPrimaryKey(["id"]);
        $Bots->addColumn("name", Types::STRING)->setLength(512);
        $Bots->addColumn("created", Types::DATETIME_IMMUTABLE);
        $Bots->addColumn("network_id", Types::INTEGER);
        $Bots->addUniqueIndex(["name", "network_id"]);

        $Networks = $newSchema->createTable("Networks");
        $Networks->addColumn("id", Types::INTEGER)->setNotnull(true)->setAutoincrement(true);
        $Networks->setPrimaryKey(["id"]);
        $Networks->addColumn("name", Types::STRING)->setLength(512);
        $Networks->addUniqueIndex(["name"]);
        $Networks->addColumn("created", Types::DATETIME_IMMUTABLE);

        $Bots->addForeignKeyConstraint($Networks, ["network_id"], ["id"], ["onDelete" => "CASCADE"]);

        $Ignore_Network = $newSchema->createTable("Ignore_Network");
        $Ignore_Network->addColumn("ignore_id", Types::INTEGER)->setNotnull(true);
        $Ignore_Network->addColumn("network_id", Types::INTEGER)->setNotnull(true);
        $Ignore_Network->setPrimaryKey(["ignore_id", "network_id"]);

        $Ignore_Network->addForeignKeyConstraint($Networks, ["network_id"], ["id"], ["onDelete" => "CASCADE"]);
        $Ignore_Network->addForeignKeyConstraint($Ignores, ["ignore_id"], ["id"], ["onDelete" => "CASCADE"]);

        $diff = $comp->compareSchemas($schema, $newSchema);
        echo implode("\n", $this->platform->getAlterSchemaSQL($diff));

        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql)
            $this->addSql($sql);
    }

    public function down(Schema $schema): void
    {
        $newSchemaManager = $this->connection->createSchemaManager();
        $comp = $newSchemaManager->createComparator();
        $newSchema = clone $schema;

        $newSchema->dropTable("Ignore_Network");
        $newSchema->dropTable("Ignores");
        $newSchema->dropTable("Bots");
        $newSchema->dropTable("Networks");

        $diff = $comp->compareSchemas($schema, $newSchema);
        echo implode("\n", $this->platform->getAlterSchemaSQL($diff));

        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql)
            $this->addSql($sql);
    }
}
