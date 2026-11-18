<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Tests\Support;

use MSDev\DoctrineFMDataAPIDriver\Driver\FMConnection;
use MSDev\DoctrineFMDataAPIDriver\Utility\FMRequest;
use ReflectionClass;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

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

    private const BASE_PATH = '/fmi/data/v1/databases/' . self::PARAMS['dbname'] . '/';

    /** @var list<MockResponse> */
    private array $queue = [];

    /** @var list<array{method: string, url: string, options: array}> */
    private array $history = [];

    private MockHttpClient $client;

    public function __construct()
    {
        $this->client = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $this->history[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($this->queue) ?? throw new \LogicException(sprintf('No response queued for %s %s', $method, $url));
        });

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

    /** Forget everything queued so far, e.g. the login a test won't need because it never reaches FileMaker. */
    public function clearQueue(): self
    {
        $this->queue = [];

        return $this;
    }

    public function client(): MockHttpClient
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

    /** Queue a bare HTTP response, with no FileMaker JSON envelope. */
    public function queueHttp(int $status, string $body = '', ?string $reason = null, array $headers = []): self
    {
        $responseHeaders = $headers;
        if (null !== $reason) {
            array_unshift($responseHeaders, sprintf('HTTP/1.1 %d %s', $status, $reason));
        }
        $this->queue[] = new MockResponse($body, ['http_code' => $status, 'response_headers' => $responseHeaders]);

        return $this;
    }

    /** Queue a network level failure (connection refused, timeout, ...). */
    public function queueTransportError(string $message = 'Connection refused'): self
    {
        $this->queue[] = new MockResponse('', ['error' => $message]);

        return $this;
    }

    public function queueJson(array $body, int $status = 200): self
    {
        $this->queue[] = new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), [
            'http_code' => $status,
            'response_headers' => ['Content-Type: application/json'],
        ]);

        return $this;
    }

    public function request(array $params = []): FMRequest
    {
        return new FMRequest(array_merge(self::PARAMS, $params), $this->client);
    }

    public function connection(array $params = []): FMConnection
    {
        return new FMConnection(array_merge(self::PARAMS, $params), $this->client);
    }

    /**
     * Every request sent to the Data API, in order. Login calls are left out unless asked for.
     *
     * @return list<array{method: string, url: string, uri: string, query: array, body: mixed, headers: array<string, string>}>
     */
    public function requests(bool $includeLogin = false): array
    {
        $out = [];
        foreach ($this->history as $entry) {
            $url = parse_url($entry['url']);
            $path = $url['path'] ?? '';
            $isDataApi = str_starts_with($path, self::BASE_PATH);
            if ($isDataApi && !$includeLogin && 'sessions' === substr($path, strlen(self::BASE_PATH))) {
                continue;
            }

            $query = self::parseQuery($url['query'] ?? '');
            $headers = [];
            foreach ($entry['options']['normalized_headers'] ?? [] as $lines) {
                foreach ($lines as $line) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[$name] = trim($value);
                }
            }
            $raw = $entry['options']['body'] ?? '';

            $out[] = [
                'method' => $entry['method'],
                'url' => $entry['url'],
                'uri' => $isDataApi ? substr($path, strlen(self::BASE_PATH)) : ltrim($path, '/'),
                'query' => $query,
                'body' => is_string($raw) && '' !== $raw ? (json_decode($raw, true) ?? $raw) : null,
                'headers' => $headers,
            ];
        }

        return $out;
    }

    /** Like parse_str(), but without PHP mangling dots in names (script.param). */
    private static function parseQuery(string $query): array
    {
        $out = [];
        foreach ('' === $query ? [] : explode('&', $query) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $out[urldecode($name)] = urldecode($value);
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
        return count($this->queue);
    }
}
