<?php

declare(strict_types=1);

namespace lolbot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240121075114 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'add servers and channels';
    }

    public function up(Schema $schema): void
    {
        $newSchemaManager = $this->connection->createSchemaManager();
        $comp = $newSchemaManager->createComparator();
        $newSchema = clone $schema;

        $bots = $newSchema->getTable("Bots");
        $bots->addColumn("trigger", Types::TEXT)->setNotnull(false);
        $bots->addColumn("trigger_re", Types::TEXT)->setNotnull(false);
        $bots->addColumn("bindIp", Types::TEXT)->setDefault("0");
        $bots->addColumn("onConnect", Types::TEXT)->setDefault("");
        
        $servers = $newSchema->createTable("Servers");
        $servers->addColumn("id", Types::INTEGER)->setNotnull(true)->setAutoincrement(true);
        $servers->setPrimaryKey(["id"]);
        $servers->addColumn("address", Types::TEXT);
        $servers->addColumn("port", Types::INTEGER);
        $servers->addColumn("ssl", Types::BOOLEAN);
        $servers->addColumn("throttle", Types::BOOLEAN);
        $servers->addColumn("network_id", Types::INTEGER);
        $servers->addForeignKeyConstraint("Networks", ["network_id"], ["id"], ["onDelete" => "CASCADE"]);

        $channels = $newSchema->createTable("Channels");
        $channels->addColumn("id", Types::INTEGER)->setNotnull(true)->setAutoincrement(true);
        $channels->setPrimaryKey(["id"]);
        $channels->addColumn("name", Types::TEXT);
        $channels->addColumn("bot_id", Types::INTEGER);
        $channels->addForeignKeyConstraint("Bots", ["bot_id"], ["id"], ["onDelete" => "CASCADE"]);

        $diff = $comp->compareSchemas($schema, $newSchema);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql)
            $this->addSql($sql);
    }

    public function down(Schema $schema): void
    {
        $newSchemaManager = $this->connection->createSchemaManager();
        $comp = $newSchemaManager->createComparator();
        $newSchema = clone $schema;

        $bots = $newSchema->getTable("Bots");
        $bots->dropColumn("trigger")
             ->dropColumn("trigger_re")
             ->dropColumn("bindIp")
             ->dropColumn("onConnect");

        $newSchema->dropTable("Servers");
        $newSchema->dropTable("Channels");

        $diff = $comp->compareSchemas($schema, $newSchema);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql)
            $this->addSql($sql);
    }
}
