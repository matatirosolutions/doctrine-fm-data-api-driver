<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Tests\Support;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use PHPUnit\Framework\TestCase;

/**
 * Base for tests which drive the real Doctrine ORM + DBAL stack on top of the
 * driver, with FileMaker replaced by MockFileMaker.
 */
abstract class OrmTestCase extends TestCase
{
    protected MockFileMaker $fm;

    protected EntityManager $em;

    protected function setUp(): void
    {
        $this->fm = new MockFileMaker();
        $this->fm->queueLogin();
        MockFileMakerDriver::$mock = $this->fm;

        $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/../Fixtures'], true);
        if (PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }
        $connection = DriverManager::getConnection(
            array_merge(MockFileMaker::PARAMS, ['driverClass' => MockFileMakerDriver::class]),
            $config
        );
        $this->em = new EntityManager($connection, $config);
    }

    protected function tearDown(): void
    {
        MockFileMaker::clearTokenCache();
    }

    /** @return array{recordId: string, modId: string, fieldData: array} */
    protected static function record(int $recId, array $fields, int $modId = 0): array
    {
        return ['recordId' => (string) $recId, 'modId' => (string) $modId, 'fieldData' => $fields];
    }
}
