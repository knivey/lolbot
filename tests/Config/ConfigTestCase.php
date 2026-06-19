<?php
namespace Tests\Config;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

abstract class ConfigTestCase extends TestCase
{
    protected EntityManager $em;

    protected function setUp(): void
    {
        // Same entity paths as bootstrap.php so the full metadata set is available.
        $paths = [
            __DIR__ . '/../../entities',
            __DIR__ . '/../../scripts/linktitles/entities',
            __DIR__ . '/../../scripts/weather/entities',
            __DIR__ . '/../../scripts/lastfm/entities',
            __DIR__ . '/../../scripts/remindme/entities',
        ];
        $config = ORMSetup::createAttributeMetadataConfiguration($paths, true);
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->em = new EntityManager($conn, $config);

        $tool = new SchemaTool($this->em);
        $tool->createSchema($this->em->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        $this->em->close();
    }
}
