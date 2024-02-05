<?php

declare(strict_types=1);

namespace lolbot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240201012155 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'add seendb';
    }

    public function up(Schema $schema): void
    {
        $newSchemaManager = $this->connection->createSchemaManager();
        $comp = $newSchemaManager->createComparator();
        $newSchema = clone $schema;

        $tells = $newSchema->createTable("seen_seens");
        $tells->addColumn("id", Types::INTEGER)->setNotnull(true)->setAutoincrement(true);
        $tells->setPrimaryKey(["id"]);
        $tells->addColumn("nick", Types::TEXT);
        $tells->addColumn("orig_nick", Types::TEXT);
        $tells->addColumn("chan", Types::TEXT);
        $tells->addColumn("text", Types::BINARY);
        $tells->addColumn("action", Types::TEXT);
        $tells->addColumn("time", Types::DATETIME_MUTABLE);
        $tells->addColumn("network_id", Types::INTEGER);
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

        $newSchema->dropTable("seen_seens");

        $diff = $comp->compareSchemas($schema, $newSchema);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql)
            $this->addSql($sql);
    }
}
