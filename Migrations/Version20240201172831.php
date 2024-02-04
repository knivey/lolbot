<?php

declare(strict_types=1);

namespace lolbot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240201172831 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add more config for servers networks etc';
    }

    public function up(Schema $schema): void
    {
        $newSchemaManager = $this->connection->createSchemaManager();
        $comp = $newSchemaManager->createComparator();
        $newSchema = clone $schema;

        $servers = $newSchema->getTable("Servers");
        $servers->addColumn("password", Types::TEXT)->setNotnull(false);

        $bots = $newSchema->getTable("Bots");
        $bots->addColumn("sasl_user", Types::TEXT)->setNotnull(false);
        $bots->addColumn("sasl_pass", Types::TEXT)->setNotnull(false);


        $diff = $comp->compareSchemas($schema, $newSchema);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql)
            $this->addSql($sql);
    }

    public function down(Schema $schema): void
    {
        $newSchemaManager = $this->connection->createSchemaManager();
        $comp = $newSchemaManager->createComparator();
        $newSchema = clone $schema;

        $servers = $newSchema->getTable("Servers");
        $servers->dropColumn("password");

        $bots = $newSchema->getTable("Bots");
        $bots->dropColumn("sasl_user");
        $bots->dropColumn("sasl_pass");

        $diff = $comp->compareSchemas($schema, $newSchema);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql)
            $this->addSql($sql);
    }
}
