<?php

declare(strict_types=1);

namespace lolbot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260606120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create linktitles_settings table for per-network/channel AI vision toggle';
    }

    public function up(Schema $schema): void
    {
        $t = $schema->createTable("linktitles_settings");
        $t->addColumn("id", Types::INTEGER)->setNotnull(true)->setAutoincrement(true);
        $t->setPrimaryKey(["id"]);
        $t->addColumn("network_id", Types::INTEGER)->setNotnull(false);
        $t->addColumn("channel_id", Types::INTEGER)->setNotnull(false);
        $t->addColumn("ai_vision_disabled", Types::BOOLEAN)->setNotnull(true)->setDefault(false);
        $t->addUniqueConstraint(["network_id", "channel_id"], "scope_unique");
        $t->addForeignKeyConstraint("Networks", ["network_id"], ["id"], ["onDelete" => "CASCADE"]);
        $t->addForeignKeyConstraint("Channels", ["channel_id"], ["id"], ["onDelete" => "CASCADE"]);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable("linktitles_settings");
    }
}
