<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Tests\Unit;

use MSDev\DoctrineFMDataAPIDriver\Exception\AuthenticationException;
use MSDev\DoctrineFMDataAPIDriver\Exception\FMException;
use MSDev\DoctrineFMDataAPIDriver\Tests\Support\MockFileMaker;
use PHPUnit\Framework\TestCase;

/**
 * Characterises how the driver talks to the Data API: login, token reuse,
 * the various ways FileMaker signals "token expired", and error mapping.
 */
final class FMRequestTest extends TestCase
{
    private MockFileMaker $fm;

    protected function setUp(): void
    {
        $this->fm = new MockFileMaker();
    }

    protected function tearDown(): void
    {
        MockFileMaker::clearTokenCache();
    }

    public function testLogsInWithBasicAuthAgainstSessionsEndpoint(): void
    {
        $this->fm->queueLogin();
        $this->fm->request();

        $login = $this->fm->requests(true)[0];
        self::assertSame('POST', $login['method']);
        self::assertSame('sessions', $login['uri']);
        self::assertSame('Basic ' . base64_encode('tester:secret'), $login['headers']['Authorization']);
    }

    public function testBaseUriIsBuiltFromHostPortAndDatabase(): void
    {
        $this->fm->queueLogin();
        $this->fm->request(['host' => 'fm.example.test', 'port' => 8443]);

        self::assertStringStartsWith('https://fm.example.test:8443/fmi/data/v1/databases/TestDB/', $this->fm->requests(true)[0]['url']);
    }

    public function testSubsequentRequestsCarryTheBearerToken(): void
    {
        $this->fm->queueLogin('abc123')->queueRecords([['recordId' => '1', 'modId' => '0', 'fieldData' => []]]);
        $this->fm->request()->performFMRequest('GET', 'layouts/Foo/records', []);

        self::assertSame('Bearer abc123', $this->fm->lastRequest()['headers']['Authorization']);
    }

    public function testReturnsTheDataMemberAndExposesDataInfoAsMetadata(): void
    {
        $record = ['recordId' => '7', 'modId' => '2', 'fieldData' => ['name' => 'x']];
        $this->fm->queueLogin()->queueRecords([$record], ['foundCount' => 1, 'returnedCount' => 1, 'totalRecordCount' => 9]);

        $request = $this->fm->request();
        $result = $request->performFMRequest('GET', 'layouts/Foo/records', []);

        self::assertSame([$record], $result);
        self::assertSame(9, $request->getMetadata()['totalRecordCount']);
    }

    public function testResponsesWithoutDataReturnTheWholeResponseMember(): void
    {
        // e.g. the result of creating a record: {"recordId": "12", "modId": "0"}
        $this->fm->queueLogin()->queueResponse(['recordId' => '12', 'modId' => '0']);

        $result = $this->fm->request()->performFMRequest('POST', 'layouts/Foo/records', []);

        self::assertSame(['recordId' => '12', 'modId' => '0'], $result);
    }

    public function testScriptResultIsReturnedWhenRequested(): void
    {
        $this->fm->queueLogin()->queueResponse(['scriptError' => '0', 'scriptResult' => 'done']);

        $result = $this->fm->request()->performFMRequest('GET', 'layouts/Foo/script/Bar', [], true);

        self::assertSame(['error' => '0', 'result' => 'done'], $result);
    }

    public function testNoRecordsFoundIsAnEmptyResultNotAnError(): void
    {
        $this->fm->queueLogin()->queueError(401, 'No records match the request', 404);

        self::assertSame([], $this->fm->request()->performFMRequest('POST', 'layouts/Foo/_find', []));
    }

    public function testOnPremExpiredTokenIsRefreshedAndTheRequestRetried(): void
    {
        $this->fm->queueLogin('old')
            ->queueError(952, 'Invalid FileMaker Data API token (*)', 500)
            ->queueLogin('new')
            ->queueRecords([['recordId' => '1', 'modId' => '0', 'fieldData' => ['a' => 'b']]]);

        $result = $this->fm->request()->performFMRequest('GET', 'layouts/Foo/records', []);

        self::assertCount(1, $result);
        $sent = $this->fm->requests(true);
        self::assertSame(['sessions', 'layouts/Foo/records', 'sessions', 'layouts/Foo/records'], array_column($sent, 'uri'));
        self::assertSame('Bearer new', $sent[3]['headers']['Authorization']);
    }

    public function testFmCloudHttp401TriggersARefresh(): void
    {
        // FileMaker Cloud answers an expired token with HTTP 401 and no FM error body
        $this->fm->queueLogin('old')
            ->queueHttp(401)
            ->queueLogin('new')
            ->queueRecords([]);

        $this->fm->request()->performFMRequest('GET', 'layouts/Foo/records', []);

        self::assertSame(['sessions', 'layouts/Foo/records', 'sessions', 'layouts/Foo/records'], array_column($this->fm->requests(true), 'uri'));
    }

    public function testMissingLayoutErrorIsRetriedOnceWithAFreshToken(): void
    {
        // FileMaker sometimes reports a stale token as 105 "Layout is missing"
        $this->fm->queueLogin()
            ->queueError(105, 'Layout is missing', 500)
            ->queueLogin()
            ->queueRecords([]);

        $this->fm->request()->performFMRequest('GET', 'layouts/Foo/records', []);

        self::assertSame(0, $this->fm->remainingResponses());
    }

    public function testGenuineMissingLayoutErrorIsThrownAfterTheRetry(): void
    {
        $this->fm->queueLogin()
            ->queueError(105, 'Layout is missing', 500)
            ->queueLogin()
            ->queueError(105, 'Layout is missing', 500);

        $this->expectException(FMException::class);
        $this->expectExceptionMessage('Layout is missing');
        $this->expectExceptionCode(105);

        $this->fm->request()->performFMRequest('GET', 'layouts/Nope/records', []);
    }

    public function testOtherFileMakerErrorsSurfaceAsFMException(): void
    {
        $this->fm->queueLogin()->queueError(102, 'Field is missing', 500);

        try {
            $this->fm->request()->performFMRequest('POST', 'layouts/Foo/_find', []);
            self::fail('Expected an FMException');
        } catch (FMException $e) {
            self::assertSame('Field is missing', $e->getMessage());
            self::assertSame(102, (int) $e->getCode());
        }
    }

    public function testNonJsonErrorBodyFallsBackToTheHttpReasonPhrase(): void
    {
        $this->fm->queueLogin()->queueHttp(502, 'not json', 'Bad Gateway');

        $this->expectException(FMException::class);
        $this->expectExceptionMessage('Bad Gateway');

        $this->fm->request()->performFMRequest('GET', 'layouts/Foo/records', []);
    }

    public function testBadCredentialsRaiseAnAuthenticationException(): void
    {
        $this->fm->queueError(212, 'Invalid user account and/or password; Please try again', 401);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid user account');

        $this->fm->request();
    }

    public function testMissingDatabaseOnLoginIsAnAuthenticationException(): void
    {
        $this->fm->queueHttp(404, '', 'Not Found');

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Not Found');

        $this->fm->request();
    }

    public function testCachedTokenIsReusedWithoutLoggingInAgain(): void
    {
        $this->fm->queueLogin('cached');
        $this->fm->request();

        // A second connection (e.g. the next PHP request) should pick up the token from disk
        $second = new MockFileMaker();
        file_put_contents(MockFileMaker::tokenCacheFile(), 'cached');
        $second->queueRecords([]);
        $second->request()->performFMRequest('GET', 'layouts/Foo/records', []);

        self::assertSame([], array_filter($second->requests(true), static fn ($r) => 'sessions' === $r['uri']));
        self::assertSame('Bearer cached', $second->lastRequest()['headers']['Authorization']);
    }

    public function testEachRequestGetsItsOwnTokenRefresh(): void
    {
        $this->fm->queueLogin('t1')
            ->queueError(952, 'Invalid FileMaker Data API token (*)')->queueLogin('t2')->queueRecords([])
            ->queueError(952, 'Invalid FileMaker Data API token (*)')->queueLogin('t3')->queueRecords([]);

        $request = $this->fm->request();
        $request->performFMRequest('GET', 'layouts/Foo/records', []);
        $request->performFMRequest('GET', 'layouts/Foo/records', []);

        self::assertSame(0, $this->fm->remainingResponses());
        self::assertSame('t3', $request->getToken());
    }

    public function testScriptResultIsStillReturnedAfterATokenRefresh(): void
    {
        $this->fm->queueLogin()
            ->queueError(952, 'Invalid FileMaker Data API token (*)')
            ->queueLogin()
            ->queueResponse(['scriptError' => '0', 'scriptResult' => 'done']);

        $result = $this->fm->request()->performFMRequest('GET', 'layouts/Foo/script/Bar', [], true);

        self::assertSame(['error' => '0', 'result' => 'done'], $result);
    }

    public function testNetworkFailureIsAnFMException(): void
    {
        $this->fm->queueLogin()->queueTransportError('Connection refused');

        $this->expectException(FMException::class);
        $this->expectExceptionMessage('Connection refused');

        $this->fm->request()->performFMRequest('GET', 'layouts/Foo/records', []);
    }

    public function testNetworkFailureOnLoginIsAnAuthenticationException(): void
    {
        $this->fm->queueTransportError('Could not resolve host');

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Could not resolve host');

        $this->fm->request();
    }

    public function testCallerHeadersAreMergedCaseInsensitivelyOverTheDefaults(): void
    {
        $this->fm->queueLogin()->queueRecords([]);

        $this->fm->request()->performFMRequest('GET', 'layouts/Foo/records', ['headers' => ['content-type' => 'text/plain', 'X-Extra' => '1']]);

        $headers = $this->fm->lastRequest()['headers'];
        self::assertSame('text/plain', $headers['Content-Type'] ?? $headers['content-type']);
        self::assertSame('1', $headers['X-Extra']);
        self::assertCount(1, array_filter(array_keys($headers), static fn ($h) => 'content-type' === strtolower($h)));
    }

    public function testUploadsAreSentAsMultipartFormData(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'fmup');
        file_put_contents($file, 'hello');
        $this->fm->queueLogin()->queueResponse(['modId' => '1']);

        try {
            $result = $this->fm->request()->performUpload('layouts/Foo/records/9/containers/Photo/1', $file);
        } finally {
            unlink($file);
        }

        $req = $this->fm->lastRequest();
        self::assertSame(['modId' => '1'], $result);
        self::assertSame('POST', $req['method']);
        self::assertSame('layouts/Foo/records/9/containers/Photo/1', $req['uri']);
        self::assertStringStartsWith('multipart/form-data; boundary=', $req['headers']['Content-Type']);
    }

    public function testUploadsSurviveATokenRefresh(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'fmup');
        file_put_contents($file, 'hello');
        $this->fm->queueLogin()
            ->queueError(952, 'Invalid FileMaker Data API token (*)')
            ->queueLogin()
            ->queueResponse(['modId' => '1']);

        try {
            $result = $this->fm->request()->performUpload('layouts/Foo/records/9/containers/Photo/1', $file);
        } finally {
            unlink($file);
        }

        self::assertSame(['modId' => '1'], $result);
        self::assertSame(0, $this->fm->remainingResponses());
    }
}
