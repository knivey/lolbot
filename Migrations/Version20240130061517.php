<?php

declare(strict_types=1);

namespace lolbot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240130061517 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'add reminder db';
    }

    public function up(Schema $schema): void
    {
        $newSchemaManager = $this->connection->createSchemaManager();
        $comp = $newSchemaManager->createComparator();
        $newSchema = clone $schema;

        $reminders = $newSchema->createTable("remindme_reminders");
        $reminders->addColumn("id", Types::INTEGER)->setNotnull(true)->setAutoincrement(true);
        $reminders->setPrimaryKey(["id"]);
        $reminders->addColumn("nick", Types::TEXT);
        $reminders->addColumn("chan", Types::TEXT);
        $reminders->addColumn("at", Types::INTEGER);
        $reminders->addColumn("sent", Types::BOOLEAN);
        $reminders->addColumn("msg", Types::TEXT);
        $reminders->addColumn("network_id", Types::INTEGER);
        $reminders->addForeignKeyConstraint("Networks", ["network_id"], ["id"], ["onDelete" => "CASCADE"]);

        $diff = $comp->compareSchemas($schema, $newSchema);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql)
            $this->addSql($sql);
    }

    public function down(Schema $schema): void
    {
        $newSchemaManager = $this->connection->createSchemaManager();
        $comp = $newSchemaManager->createComparator();
        $newSchema = clone $schema;

        $newSchema->dropTable("remindme_reminders");

        $diff = $comp->compareSchemas($schema, $newSchema);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql)
            $this->addSql($sql);
    }
}
