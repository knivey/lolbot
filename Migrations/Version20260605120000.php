<?php

declare(strict_types=1);

namespace lolbot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260605120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create user_timezones table for per-user timezone storage';
    }

    public function up(Schema $schema): void
    {
        $t = $schema->createTable("user_timezones");
        $t->addColumn("id", Types::INTEGER)->setNotnull(true)->setAutoincrement(true);
        $t->setPrimaryKey(["id"]);
        $t->addColumn("nick", Types::STRING)->setNotnull(true);
        $t->addColumn("timezone", Types::STRING)->setNotnull(true);
        $t->addColumn("network_id", Types::INTEGER)->setNotnull(true);
        $t->addUniqueConstraint(["nick", "network_id"]);
        $t->addForeignKeyConstraint("Networks", ["network_id"], ["id"], ["onDelete" => "CASCADE"]);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable("user_timezones");
    }
}
