<?php

declare(strict_types=1);

namespace lolbot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the users + user_hostmasks tables and the Networks.auth_engines /
 * Networks.admin_hostmask_auth columns.
 *
 * Uses the portable schema-comparator technique (mirroring
 * Version20260903120000.php) so the generated CREATE/ALTER statements work on
 * both SQLite (tests/dev) and Postgres (prod). users is created before
 * user_hostmasks so the FK between them resolves in emitted-SQL order.
 */
final class Version20260927120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add users + user_hostmasks tables, Networks.auth_engines and admin_hostmask_auth';
    }

    public function up(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();
        $comp = $sm->createComparator();
        $newSchema = clone $schema;

        $users = $newSchema->createTable("users");
        $users->addColumn("id", Types::INTEGER)->setNotnull(true)->setAutoincrement(true);
        $users->setPrimaryKey(["id"]);
        $users->addColumn("network_id", Types::INTEGER)->setNotnull(true);
        $users->addForeignKeyConstraint("Networks", ["network_id"], ["id"], ["onDelete" => "CASCADE"]);
        $users->addColumn("name", Types::STRING)->setNotnull(true);
        $users->addColumn("nameLowered", Types::STRING)->setNotnull(true);
        $users->addColumn("pass_hash", Types::STRING)->setNotnull(false);
        $users->addColumn("flags", Types::JSON)->setNotnull(true)->setDefault("[]");
        $users->addColumn("paranoid", Types::BOOLEAN)->setNotnull(true)->setDefault(false);
        $users->addColumn("created", Types::DATETIME_IMMUTABLE)->setNotnull(true);
        // One account per name per network; the nameLowered form is what
        // lookups use.
        $users->addUniqueIndex(["network_id", "nameLowered"], "users_network_name_uniq");

        $hostmasks = $newSchema->createTable("user_hostmasks");
        $hostmasks->addColumn("id", Types::INTEGER)->setNotnull(true)->setAutoincrement(true);
        $hostmasks->setPrimaryKey(["id"]);
        $hostmasks->addColumn("user_id", Types::INTEGER)->setNotnull(true);
        $hostmasks->addForeignKeyConstraint("users", ["user_id"], ["id"], ["onDelete" => "CASCADE"]);
        $hostmasks->addColumn("mask", Types::STRING)->setNotnull(true);
        $hostmasks->addColumn("added_by", Types::STRING)->setNotnull(true);
        $hostmasks->addColumn("created", Types::DATETIME_IMMUTABLE)->setNotnull(true);
        // A mask can only be linked to a user once.
        $hostmasks->addUniqueIndex(["user_id", "mask"], "user_hostmasks_user_mask_uniq");

        $nets = $newSchema->getTable("Networks");
        // Owner note 2026-09-27: the admins-never-store-hostmasks rule is
        // relaxed on networks known not to abuse faked hosts.
        $nets->addColumn("auth_engines", Types::JSON)->setNotnull(false);
        $nets->addColumn("admin_hostmask_auth", Types::BOOLEAN)->setNotnull(true)->setDefault(false);

        $diff = $comp->compareSchemas($schema, $newSchema);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();
        $comp = $sm->createComparator();
        $newSchema = clone $schema;

        // user_hostmasks first so its FK to users is gone before users drops.
        $newSchema->dropTable("user_hostmasks");
        $newSchema->dropTable("users");

        $nets = $newSchema->getTable("Networks");
        $nets->dropColumn("auth_engines");
        $nets->dropColumn("admin_hostmask_auth");

        $diff = $comp->compareSchemas($schema, $newSchema);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql) {
            $this->addSql($sql);
        }
    }
}
