<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Tests\Integration;

use MSDev\DoctrineFMDataAPIDriver\Exception\FMException;
use MSDev\DoctrineFMDataAPIDriver\Tests\Support\OrmTestCase;
use MSDev\DoctrineFMDataAPIDriver\Utility\ContainerAccess;
use MSDev\DoctrineFMDataAPIDriver\Utility\ScriptAccess;
use MSDev\DoctrineFMDataAPIDriver\Utility\SetGlobals;

/**
 * The helper classes applications call directly (usually via services defined in
 * the Symfony bundle). Their constructors, method names and signatures are the
 * public API which has to stay stable across the DBAL 4 port.
 */
final class HelperApiTest extends OrmTestCase
{
    private function scripts(): ScriptAccess
    {
        return new ScriptAccess($this->em->getConnection());
    }

    private function containers(): ContainerAccess
    {
        return new ContainerAccess($this->em->getConnection(), $this->scripts(), $this->fm->client());
    }

    public function testCreatingTheHelpersCostsNothing(): void
    {
        $scripts = $this->scripts();
        new SetGlobals($this->em->getConnection());
        new ContainerAccess($this->em->getConnection(), $scripts);

        self::assertCount(0, $this->fm->requests(true), 'no login, no requests');
    }

    // ScriptAccess

    public function testExecuteScriptRunsAScriptOnALayoutAndReturnsItsResult(): void
    {
        $this->fm->queueResponse(['scriptError' => '0', 'scriptResult' => 'hello']);

        $result = $this->scripts()->executeScript('Keyword', 'Greet', 'world');

        self::assertSame(['error' => '0', 'result' => 'hello'], $result);
        $req = $this->fm->lastRequest();
        self::assertSame('GET', $req['method']);
        self::assertSame('layouts/Keyword/script/Greet', $req['uri']);
        self::assertSame(['script.param' => 'world'], $req['query']);
    }

    public function testPerformScriptOnARecordCanReturnTheScriptResult(): void
    {
        $this->fm->queueResponse(['scriptError' => '0', 'scriptResult' => 'ok']);

        $result = $this->scripts()->performScript('Keyword', 9, 'Touch', 'p', true);

        self::assertSame(['error' => '0', 'result' => 'ok'], $result);
        $req = $this->fm->lastRequest();
        self::assertSame('layouts/Keyword/records/9', ltrim($req['uri'], '/'));
        self::assertSame(['script' => 'Touch', 'script.param' => 'p'], $req['query']);
    }

    public function testPerformScriptOtherwiseReturnsTheUpdatedRecordsFieldData(): void
    {
        $this->fm->queueResponse(['scriptError' => '0']);
        $this->fm->queueRecords([self::record(9, ['name' => 'after'])]);

        $result = $this->scripts()->performScript('Keyword', 9, 'Touch', 'p');

        self::assertSame(['name' => 'after'], $result);
        self::assertSame('layouts/Keyword/records/9', ltrim($this->fm->lastRequest()['uri'], '/'));
    }

    public function testScriptFailuresAreReportedAsFMException(): void
    {
        $this->fm->queueError(104, 'Script is missing');

        $this->expectException(FMException::class);
        $this->expectExceptionMessage('Script is missing');

        $this->scripts()->executeScript('Keyword', 'Nope');
    }

    // SetGlobals

    public function testSetGlobalsPatchesTheGlobalsEndpoint(): void
    {
        $this->fm->queueResponse([]);

        (new SetGlobals($this->em->getConnection()))->setGlobals(['Keyword::gMode' => 'on']);

        $req = $this->fm->lastRequest();
        self::assertSame('PATCH', $req['method']);
        self::assertSame('globals', $req['uri']);
        self::assertSame(['globalFields' => ['Keyword::gMode' => 'on']], $req['body']);
    }

    // ContainerAccess

    public function testContainerUploadsGoToTheRecordsContainerEndpoint(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'fmup');
        file_put_contents($file, 'pixels');
        $this->fm->queueResponse(['modId' => '1']);

        try {
            $result = $this->containers()->performContainerInsert('Keyword', 9, 'Photo', $file, 2);
        } finally {
            unlink($file);
        }

        self::assertSame(['modId' => '1'], $result);
        $req = $this->fm->lastRequest();
        self::assertSame('POST', $req['method']);
        self::assertSame('layouts/Keyword/records/9/containers/Photo/2', $req['uri']);
        self::assertStringStartsWith('multipart/form-data', $req['headers']['Content-Type']);
    }

    public function testExternalContainerContentIsFetchedFromTheHostWithBasicAuth(): void
    {
        // Fetching container content never touches the Data API session, so no login is needed
        $this->fm->clearQueue();
        $this->fm->queueHttp(200, 'JPEGDATA');

        $content = $this->containers()->getExternalContainerContent('/Streaming_SSL/MainDB/photo.jpg?a=1&amp;b=2');

        self::assertSame('JPEGDATA', $content);
        $req = $this->fm->lastRequest();
        self::assertSame('GET', $req['method']);
        self::assertSame(['a' => '1', 'b' => '2'], $req['query']);
        self::assertSame('Basic ' . base64_encode('tester:secret'), $req['headers']['Authorization']);
        self::assertStringContainsString('fm.example.test', $req['url']);
        self::assertStringEndsWith('/Streaming_SSL/MainDB/photo.jpg?a=1&b=2', $req['url']);
    }

    public function testExternalContainerFailuresAreFMExceptions(): void
    {
        // Fetching container content never touches the Data API session, so no login is needed
        $this->fm->clearQueue();
        $this->fm->queueHttp(404);

        $this->expectException(FMException::class);

        $this->containers()->getExternalContainerContent('/missing.jpg');
    }

    public function testStreamedContainerContentCarriesTheSessionCookieAcrossRedirects(): void
    {
        // Fetching container content never touches the Data API session, so no login is needed
        $this->fm->clearQueue();
        $this->fm->queueHttp(302, '', null, ['Location: https://fm.example.test/stream/real', 'Set-Cookie: X-FM-Data-Access-Token=abc; Path=/; HttpOnly'])
            ->queueHttp(200, 'BINARY');

        $content = $this->containers()->getStreamedContainerContent('https://fm.example.test/stream/start');

        self::assertSame('BINARY', $content);
        $requests = $this->fm->requests();
        self::assertCount(2, $requests);
        self::assertSame('https://fm.example.test/stream/real', $requests[1]['url']);
        self::assertSame('X-FM-Data-Access-Token=abc', $requests[1]['headers']['Cookie']);
    }

    public function testStreamedContainerContentWithoutRedirectsIsReturnedDirectly(): void
    {
        // Fetching container content never touches the Data API session, so no login is needed
        $this->fm->clearQueue();
        $this->fm->queueHttp(200, 'BINARY');

        self::assertSame('BINARY', $this->containers()->getStreamedContainerContent('https://fm.example.test/stream/start'));
    }
}
