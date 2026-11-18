<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Tests\Functional;

use Doctrine\DBAL\DriverManager;
use MSDev\DoctrineFMDataAPIDriver\Exception\AuthenticationException;
use MSDev\DoctrineFMDataAPIDriver\Exception\FMException;
use MSDev\DoctrineFMDataAPIDriver\Tests\Support\MockFileMaker;

class ConnectionTest extends FunctionalTestCase
{
    public function testLogsInAndHoldsAToken(): void
    {
        self::assertNotEmpty($this->request()->getToken());
    }

    public function testBadPasswordIsAnAuthenticationException(): void
    {
        // A valid token from an earlier test is cached on disk and would be reused
        MockFileMaker::clearTokenCache();

        try {
            DriverManager::getConnection($this->connectionParams([
                'user' => 'nobody',
                'password' => 'definitely-not-the-password',
            ]))->getNativeConnection();
            self::fail('Expected an AuthenticationException');
        } catch (AuthenticationException $e) {
            self::assertSame(212, $e->getCode(), 'FileMaker 212: invalid account or password');
        } finally {
            MockFileMaker::clearTokenCache();
        }
    }

    public function testUnknownLayoutIsAnFMExceptionWithFileMakersCode(): void
    {
        try {
            $this->request()->performFMRequest('GET', 'layouts/NoSuchLayout/records', []);
            self::fail('Expected an FMException');
        } catch (FMException $e) {
            self::assertSame(105, $e->getCode(), 'FileMaker 105: layout is missing');
        }
    }

    public function testAStaleTokenIsRefreshedTransparently(): void
    {
        $file = MockFileMaker::tokenCacheFile();
        // Make the on-disk (and so the next connection's) token garbage, then use it
        file_put_contents($file, 'stale-token');
        $this->em->getConnection()->close();

        $records = $this->request()->performFMRequest('GET', 'layouts/API_Keyword/records?_limit=1', []);

        self::assertIsArray($records);
        self::assertNotSame('stale-token', $this->request()->getToken());
    }
}
