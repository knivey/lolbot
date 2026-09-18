<?php

declare(strict_types=1);

namespace lolbot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260918120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add api_keys table (scoped API keys for bot REST endpoints)';
    }

    public function up(Schema $schema): void
    {
        $t = $schema->createTable("api_keys");
        $t->addColumn("id", Types::INTEGER)->setNotnull(true)->setAutoincrement(true);
        $t->setPrimaryKey(["id"]);
        $t->addColumn("key", Types::STRING)->setLength(64)->setNotnull(true);
        $t->addUniqueIndex(["key"]);
        $t->addColumn("label", Types::STRING)->setLength(64)->setNotnull(false);
        $t->addColumn("scopes", Types::JSON)->setNotnull(true);
        $t->addColumn("created", Types::DATETIME_IMMUTABLE)->setNotnull(true);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable("api_keys");
    }
}
