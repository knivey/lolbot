<?php

declare(strict_types=1);

namespace lolbot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240120061736 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'add weather locations db';
    }

    public function up(Schema $schema): void
    {
        $newSchemaManager = $this->connection->createSchemaManager();
        $comp = $newSchemaManager->createComparator();
        $newSchema = clone $schema;

        $location = $newSchema->createTable("weather_locations");
        $location->addColumn("id", Types::INTEGER)->setNotnull(true)->setAutoincrement(true);
        $location->setPrimaryKey(["id"]);
        $location->addColumn("si", Types::BOOLEAN)->setNotnull(true);
        $location->addColumn("name", Types::TEXT);
        $location->addColumn("lat", Types::STRING);
        $location->addColumn("long", Types::STRING);
        $location->addColumn("nick", Types::STRING);
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

        $newSchema->dropTable("weather_locations");

        $diff = $comp->compareSchemas($schema, $newSchema);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql)
            $this->addSql($sql);
    }
}
