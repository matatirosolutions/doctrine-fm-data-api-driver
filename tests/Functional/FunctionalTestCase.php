<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use MSDev\DoctrineFMDataAPIDriver\Driver\FMDriver;
use MSDev\DoctrineFMDataAPIDriver\Types\FMDateTimeType;
use MSDev\DoctrineFMDataAPIDriver\Types\FMDateType;
use MSDev\DoctrineFMDataAPIDriver\Types\FMTimeType;
use MSDev\DoctrineFMDataAPIDriver\Utility\FMRequest;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Base for tests which talk to a real FileMaker Server over the Data API.
 *
 * They are skipped unless FM_HOST and FM_PASSWORD are set (see tests/Functional/Database/README.md),
 * so the suite is safe to run anywhere. Every test works in its own `Category` marker, and removes
 * whatever carries it afterwards, so a failed run does not pollute the next one.
 */
abstract class FunctionalTestCase extends TestCase
{
    protected EntityManager $em;

    /** Unique to this test: put it in Keyword::category (or Note text) so tearDown can find the records */
    protected string $marker;

    /** The layouts tearDown sweeps, with the field holding the marker */
    private const SWEEP = [
        'API_Keyword' => 'Category',
        'API_Note' => 'Text',
    ];

    public static function setUpBeforeClass(): void
    {
        self::loadEnvFile(__DIR__ . '/.env.local');

        foreach (['fmdate' => FMDateType::class, 'fmtime' => FMTimeType::class, 'fmdatetime' => FMDateTimeType::class] as $name => $class) {
            Type::hasType($name) ? Type::overrideType($name, $class) : Type::addType($name, $class);
        }
    }

    protected function setUp(): void
    {
        if (!getenv('FM_HOST') || false === getenv('FM_PASSWORD')) {
            $this->markTestSkipped('Set FM_HOST and FM_PASSWORD (see tests/Functional/Database/README.md) to run the functional tests.');
        }

        $this->marker = sprintf('phpunit-%s-%s', date('His'), bin2hex(random_bytes(4)));

        $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/Entity'], true);
        if (PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }

        $connection = DriverManager::getConnection($this->connectionParams(), $config);
        $this->em = new EntityManager($connection, $config);
    }

    protected function tearDown(): void
    {
        if (!isset($this->em)) {
            return;
        }

        try {
            $this->sweep();
        } catch (Throwable $e) {
            fwrite(STDERR, sprintf("\nCould not clean up '%s': %s\n", $this->marker, $e->getMessage()));
        }
        $this->em->close();
    }

    /** Reads KEY=value lines into the environment, without overriding anything already exported */
    private static function loadEnvFile(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if (false === getenv($key)) {
                putenv($key . '=' . trim($value, " \t\"'"));
            }
        }
    }

    /** @return array<string, mixed> The connection parameters built from the environment */
    protected function connectionParams(array $overrides = []): array
    {
        return $overrides + [
                'driverClass' => FMDriver::class,
                'host' => getenv('FM_HOST'),
                'port' => getenv('FM_PORT') ?: 443,
                'dbname' => getenv('FM_DBNAME') ?: 'DoctrineFMTest',
                'user' => getenv('FM_USER') ?: 'api_test',
                'password' => (string) getenv('FM_PASSWORD'),
                'serverVersion' => getenv('FM_SERVER_VERSION') ?: 'FMS',
                'driverOptions' => array_filter([
                    // FM_VERIFY_TLS=0 for a local server with a self-signed certificate
                    'http_client_options' => '0' === getenv('FM_VERIFY_TLS') ? ['verify_peer' => false, 'verify_host' => false] : null,
                    // FM_MAX_TLS_VERSION=1.2 for a server which can't handle the TLS 1.3 handshake of recent OpenSSL
                    'max_tls_version' => getenv('FM_MAX_TLS_VERSION') ?: null,
                ]),
            ];
    }

    protected function connection(): Connection
    {
        return $this->em->getConnection();
    }

    protected function request(): FMRequest
    {
        return $this->connection()->getNativeConnection();
    }

    /** @return list<array<string, mixed>> The raw Data API records on a layout whose marker field matches */
    protected function rawRecords(string $layout): array
    {
        $field = self::SWEEP[$layout];

        return $this->request()->performFMRequest('POST', sprintf('layouts/%s/_find', $layout), [
            'body' => json_encode(['query' => [[$field => '==' . $this->marker]], 'limit' => 500]),
        ]);
    }

    private function sweep(): void
    {
        foreach (array_keys(self::SWEEP) as $layout) {
            foreach ($this->rawRecords($layout) as $record) {
                $this->request()->performFMRequest('DELETE', sprintf('layouts/%s/records/%s', $layout, $record['recordId']), []);
            }
        }
    }
}
