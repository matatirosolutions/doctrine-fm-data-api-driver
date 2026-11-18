<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Tests\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use MSDev\DoctrineFMDataAPIDriver\FMConnection;
use MSDev\DoctrineFMDataAPIDriver\FMDriver;
use MSDev\DoctrineFMDataAPIDriver\Utility\FMRequest;
use Psr\Http\Message\RequestInterface;
use ReflectionClass;

/**
 * A stand-in for a FileMaker Server Data API.
 *
 * Responses are queued up front; every request the driver makes is recorded so
 * tests can assert on exactly what was sent over the wire. This is the seam the
 * whole driver is tested through: SQL in -> HTTP request out -> JSON in -> rows out.
 */
final class MockFileMaker
{
    public const PARAMS = [
        'host' => 'fm.example.test',
        'port' => 443,
        'dbname' => 'TestDB',
        'user' => 'tester',
        'password' => 'secret',
    ];

    private MockHandler $handler;

    /** @var array<int, array{request: RequestInterface, response: ?Response}> */
    private array $history = [];

    private Client $client;

    public function __construct()
    {
        $this->handler = new MockHandler();
        $stack = HandlerStack::create($this->handler);
        $stack->push(Middleware::history($this->history));
        $this->client = new Client(['handler' => $stack]);

        // The driver caches its Data API token in the system temp dir; make sure
        // a stale one never short-circuits the login request in a test.
        self::clearTokenCache();
    }

    public static function tokenCacheFile(): string
    {
        $dir = dirname((new ReflectionClass(FMRequest::class))->getFileName());

        return sprintf('%s%sfmp-token-%s.txt', sys_get_temp_dir(), DIRECTORY_SEPARATOR, md5($dir));
    }

    public static function clearTokenCache(): void
    {
        @unlink(self::tokenCacheFile());
    }

    public function client(): Client
    {
        return $this->client;
    }

    /** Queue the response to the POST /sessions login call. */
    public function queueLogin(string $token = 'test-token'): self
    {
        return $this->queueJson(['response' => ['token' => $token], 'messages' => [['code' => '0', 'message' => 'OK']]]);
    }

    /** Queue a successful response whose `response` member is $response. */
    public function queueResponse(array $response, int $status = 200): self
    {
        return $this->queueJson([
            'response' => $response,
            'messages' => [['code' => '0', 'message' => 'OK']],
        ], $status);
    }

    /** Queue a successful response containing the given records (FileMaker shape). */
    public function queueRecords(array $records, ?array $dataInfo = null): self
    {
        return $this->queueResponse([
            'dataInfo' => $dataInfo ?? [
                'database' => 'TestDB',
                'layout' => 'Layout',
                'table' => 'Table',
                'totalRecordCount' => count($records),
                'foundCount' => count($records),
                'returnedCount' => count($records),
            ],
            'data' => $records,
        ]);
    }

    /** Queue a FileMaker error. $status is the HTTP status FileMaker would use. */
    public function queueError(int $fmCode, string $message, int $status = 500): self
    {
        return $this->queueJson(['messages' => [['code' => (string) $fmCode, 'message' => $message]]], $status);
    }

    public function queueRaw(Response $response): self
    {
        $this->handler->append($response);

        return $this;
    }

    public function queueJson(array $body, int $status = 200): self
    {
        $this->handler->append(new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR)));

        return $this;
    }

    public function request(array $params = []): FMRequest
    {
        return new FMRequest(array_merge(self::PARAMS, $params), $this->client);
    }

    public function connection(array $params = []): FMConnection
    {
        $connection = (new ReflectionClass(FMConnection::class))->newInstanceWithoutConstructor();
        $set = static function (string $prop, mixed $value) use ($connection): void {
            $r = new \ReflectionProperty(FMConnection::class, $prop);
            $r->setValue($connection, $value);
        };
        $set('params', array_merge(self::PARAMS, $params));
        $set('connection', $this->request($params));
        $set('metadata', null);

        return $connection;
    }

    /**
     * Everything sent after the login call.
     *
     * @return list<array{method: string, uri: string, query: array, body: mixed, headers: array}>
     */
    public function requests(bool $includeLogin = false): array
    {
        $out = [];
        foreach ($this->history as $entry) {
            /** @var RequestInterface $req */
            $req = $entry['request'];
            if (!$includeLogin && str_ends_with($req->getUri()->getPath(), '/sessions')) {
                continue;
            }
            parse_str($req->getUri()->getQuery(), $query);
            $raw = (string) $req->getBody();
            $out[] = [
                'method' => $req->getMethod(),
                'uri' => ltrim(substr($req->getUri()->getPath(), strpos($req->getUri()->getPath(), '/databases/' . self::PARAMS['dbname'] . '/') + strlen('/databases/' . self::PARAMS['dbname'] . '/')), '/'),
                'query' => $query,
                'body' => $raw === '' ? null : json_decode($raw, true),
                'headers' => $req->getHeaders(),
            ];
        }

        return $out;
    }

    public function lastRequest(): array
    {
        $all = $this->requests();

        return end($all) ?: throw new \LogicException('No requests were made');
    }

    public function remainingResponses(): int
    {
        return $this->handler->count();
    }
}
