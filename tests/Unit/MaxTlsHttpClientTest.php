<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Tests\Unit;

use InvalidArgumentException;
use MSDev\DoctrineFMDataAPIDriver\Utility\HttpClientFactory;
use MSDev\DoctrineFMDataAPIDriver\Utility\MaxTlsHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Exercises the client over plain HTTP against PHP's built-in server. That the TLS version really is capped can only be
 * seen against a server which cares: see the functional tests (FM_MAX_TLS_VERSION).
 */
class MaxTlsHttpClientTest extends TestCase
{
    /** @var resource|null */
    private static $server;

    private static string $base;

    public static function setUpBeforeClass(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        self::$base = 'http://127.0.0.1:' . $port;
        self::$server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/../Support/echo-router.php'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes
        );

        for ($i = 0; $i < 50; $i++) {
            if (@fsockopen('127.0.0.1', $port)) {
                return;
            }
            usleep(100_000);
        }
        self::fail('The test server did not start');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    private function client(): MaxTlsHttpClient
    {
        return new MaxTlsHttpClient('1.2');
    }

    public function testSendsMethodHeadersAndJsonBody(): void
    {
        $response = $this->client()->request('POST', self::$base . '/echo?a=1', [
            'headers' => ['Content-Type' => 'application/json', 'X-Custom' => 'yes'],
            'body' => '{"query":[{"name":"=="}]}',
        ]);

        self::assertSame(200, $response->getStatusCode());
        $echo = $response->toArray();
        self::assertSame('POST', $echo['method']);
        self::assertSame('/echo?a=1', $echo['uri']);
        self::assertSame('application/json', $echo['headers']['content-type']);
        self::assertSame('yes', $echo['headers']['x-custom']);
        self::assertSame('{"query":[{"name":"=="}]}', $echo['body']);
        self::assertStringContainsString('application/json', $response->getHeaders()['content-type'][0]);
    }

    public function testBasicAndBearerAuthAreSent(): void
    {
        $basic = $this->client()->request('GET', self::$base . '/echo', ['auth_basic' => ['user', 'pass']])->toArray();
        $bearer = $this->client()->request('GET', self::$base . '/echo', ['auth_bearer' => 'tok'])->toArray();

        self::assertSame('Basic ' . base64_encode('user:pass'), $basic['headers']['authorization']);
        self::assertSame('Bearer tok', $bearer['headers']['authorization']);
    }

    public function testEmptyPostIsStillAPostWithAZeroLength(): void
    {
        $echo = $this->client()->request('POST', self::$base . '/echo', ['headers' => ['Content-Type' => 'application/json']])->toArray();

        self::assertSame('POST', $echo['method']);
        self::assertSame('0', $echo['headers']['content-length']);
    }

    public function testStreamedMultipartBodyIsSent(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'tls') . '.txt';
        file_put_contents($file, 'hello container');
        try {
            $form = new FormDataPart(['upload' => DataPart::fromPath($file)]);
            $echo = $this->client()->request('POST', self::$base . '/echo', [
                'headers' => $form->getPreparedHeaders()->toArray(),
                'body' => $form->bodyToIterable(),
            ])->toArray();
        } finally {
            @unlink($file);
        }

        self::assertStringStartsWith('multipart/form-data', $echo['headers']['content-type']);
        self::assertSame('hello container', $echo['files']['upload']);
    }

    public function testErrorStatusesAreReturnedNotThrown(): void
    {
        $response = $this->client()->request('GET', self::$base . '/status/404');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('{"status":404}', $response->getContent(false));
        self::assertContains('HTTP/1.1 404 Not Found', $response->getInfo('response_headers'));
    }

    public function testRedirectsAreFollowedUnlessDisabled(): void
    {
        self::assertSame('/echo', $this->client()->request('GET', self::$base . '/redirect')->toArray()['uri']);

        $response = $this->client()->request('GET', self::$base . '/redirect', ['max_redirects' => 0]);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/echo', $response->getHeaders(false)['location'][0]);
    }

    public function testConnectionFailuresAreTransportExceptions(): void
    {
        $this->expectException(TransportExceptionInterface::class);

        $this->client()->request('GET', 'http://127.0.0.1:1/', ['timeout' => 2])->getStatusCode();
    }

    public function testDefaultOptionsApply(): void
    {
        $client = new MaxTlsHttpClient('1.2', ['headers' => ['X-Default' => 'on']]);

        self::assertSame('on', $client->request('GET', self::$base . '/echo')->toArray()['headers']['x-default']);
        self::assertSame('on', $client->withOptions([])->request('GET', self::$base . '/echo')->toArray()['headers']['x-default']);
    }

    public function testRejectsAnUnknownVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MaxTlsHttpClient('1.4');
    }

    public function testFactoryCapsTheTlsVersionAt12ByDefault(): void
    {
        self::assertInstanceOf(MaxTlsHttpClient::class, HttpClientFactory::create([]));
        self::assertInstanceOf(MaxTlsHttpClient::class, HttpClientFactory::create(['driverOptions' => ['http_client_options' => ['verify_peer' => false]]]));
        self::assertInstanceOf(MaxTlsHttpClient::class, HttpClientFactory::create(['driverOptions' => ['max_tls_version' => '1.3']]));
    }

    #[DataProvider('capDisabled')]
    public function testFactoryUsesSymfonysClientWhenTheCapIsDisabled(mixed $value): void
    {
        self::assertNotInstanceOf(MaxTlsHttpClient::class, HttpClientFactory::create(['driverOptions' => ['max_tls_version' => $value]]));
    }

    public static function capDisabled(): iterable
    {
        yield 'null' => [null];
        yield 'false' => [false];
        yield 'none' => ['none'];
        yield 'NONE' => ['NONE'];
    }
}
