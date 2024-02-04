<?php

declare(strict_types=1);

namespace lolbot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240121033728 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'add alias db';
    }

    public function up(Schema $schema): void
    {
        $newSchemaManager = $this->connection->createSchemaManager();
        $comp = $newSchemaManager->createComparator();
        $newSchema = clone $schema;

        $location = $newSchema->createTable("alias_aliases");
        $location->addColumn("id", Types::INTEGER)->setNotnull(true)->setAutoincrement(true);
        $location->setPrimaryKey(["id"]);
        $location->addColumn("name", Types::TEXT);
        $location->addColumn("nameLowered", Types::TEXT);
        $location->addColumn("value", Types::TEXT);
        $location->addColumn("chan", Types::TEXT);
        $location->addColumn("chanLowered", Types::TEXT);
        $location->addColumn("fullhost", Types::TEXT);
        $location->addColumn("act", Types::BOOLEAN);
        $location->addColumn("cmd", Types::TEXT)->setNotnull(false);
        $location->addColumn("network_id", Types::INTEGER);
        $location->addForeignKeyConstraint("Networks", ["network_id"], ["id"], ["onDelete" => "CASCADE"]);

        $diff = $comp->compareSchemas($schema, $newSchema);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql)
            $this->addSql($sql);
    }

    public function down(Schema $schema): void
    {
        $newSchemaManager = $this->connection->createSchemaManager();
        $comp = $newSchemaManager->createComparator();
        $newSchema = clone $schema;

        $newSchema->dropTable("alias_aliases");

        $diff = $comp->compareSchemas($schema, $newSchema);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql)
            $this->addSql($sql);
    }
}
