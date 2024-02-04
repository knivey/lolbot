<?php

declare(strict_types=1);

namespace lolbot\Migrations;

use _PHPStan_39fe102d2\Nette\Utils\Type;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\BigIntType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240204093244 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make reminder dates BIGINT';
    }

    public function up(Schema $schema): void
    {
        $newSchemaManager = $this->connection->createSchemaManager();
        $comp = $newSchemaManager->createComparator();
        $newSchema = clone $schema;

        $t = $newSchema->getTable("remindme_reminders");
        $t->getColumn("at")->setType(new BigIntType());

        $diff = $comp->compareSchemas($schema, $newSchema);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql)
            $this->addSql($sql);
    }

    public function down(Schema $schema): void
    {
        $newSchemaManager = $this->connection->createSchemaManager();
        $comp = $newSchemaManager->createComparator();
        $newSchema = clone $schema;

        $t = $newSchema->getTable("remindme_reminders");
        $t->getColumn("at")->setType(new IntegerType());

        $diff = $comp->compareSchemas($schema, $newSchema);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql)
            $this->addSql($sql);
    }
}
