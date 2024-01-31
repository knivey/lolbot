<?php

declare(strict_types=1);

namespace lolbot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240131035656 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'add tells db';
    }

    public function up(Schema $schema): void
    {
        $newSchemaManager = $this->connection->createSchemaManager();
        $comp = $newSchemaManager->createComparator();
        $newSchema = clone $schema;

        $tells = $newSchema->createTable("tell_tells");
        $tells->addColumn("id", Types::INTEGER)->setNotnull(true)->setAutoincrement(true);
        $tells->setPrimaryKey(["id"]);
        $tells->addColumn("created", Types::DATETIME_MUTABLE);
        $tells->addColumn("sender", Types::TEXT);
        $tells->addColumn("msg", Types::TEXT);
        $tells->addColumn("target", Types::TEXT);
        $tells->addColumn("sent", Types::BOOLEAN);
        $tells->addColumn("chan", Types::TEXT);
        $tells->addColumn("network_id", Types::INTEGER);
        $tells->addColumn("global", Types::BOOLEAN);
        $tells->addForeignKeyConstraint("Networks", ["network_id"], ["id"], ["onDelete" => "CASCADE"]);

        $diff = $comp->compareSchemas($schema, $newSchema);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql)
            $this->addSql($sql);
    }

    public function down(Schema $schema): void
    {
        $newSchemaManager = $this->connection->createSchemaManager();
        $comp = $newSchemaManager->createComparator();
        $newSchema = clone $schema;

        $newSchema->dropTable("tell_tells");

        $diff = $comp->compareSchemas($schema, $newSchema);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql)
            $this->addSql($sql);
    }
}
