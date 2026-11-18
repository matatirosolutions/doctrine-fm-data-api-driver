<?php

namespace MSDev\DoctrineFMDataAPIDriver\Utility;

use JsonException;
use MSDev\DoctrineFMDataAPIDriver\Exception\AuthenticationException;
use MSDev\DoctrineFMDataAPIDriver\Exception\FMException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class FMRequest
{
    private const SERVER_VERSION_CLOUD = 'FMCloud';

    /** FileMaker error codes which can mean "your token is stale" */
    private const TOKEN_ERROR_CODES = [105, 952];

    /** Used when the HTTP client can't tell us the reason phrase (e.g. HTTP/2) */
    private const REASON_PHRASES = [
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        408 => 'Request Timeout',
        413 => 'Payload Too Large',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
    ];

    private HttpClientInterface $client;

    private array $metadata = [];

    private ?string $token = null;

    private ?string $baseURI = null;

    /**
     * @throws AuthenticationException
     */
    public function __construct(
        private readonly array $params,
        ?HttpClientInterface $client = null,
    ) {
        $this->client = $client ?? HttpClient::create();
        $this->setBaseURL();
        $this->fetchToken();
    }

    /**
     * @param array $options Symfony HttpClient request options. `headers` are merged over the defaults.
     *
     * @throws FMException
     * @throws AuthenticationException
     */
    public function performFMRequest(string $method, string $uri, array $options, bool $returnScriptResult = false): array
    {
        return $this->execute($method, $uri, static fn(): array => $options, $returnScriptResult);
    }

    /**
     * Upload a file into a container field.
     *
     * @throws FMException
     * @throws AuthenticationException
     */
    public function performUpload(string $uri, string $filePath, string $fieldName = 'upload'): array
    {
        // Built per attempt as the body is a stream which can only be read once
        $options = static function () use ($filePath, $fieldName): array {
            $form = new FormDataPart([$fieldName => DataPart::fromPath($filePath)]);

            return [
                'headers' => $form->getPreparedHeaders()->toArray(),
                'body' => $form->bodyToIterable(),
            ];
        };

        return $this->execute('POST', $uri, $options, false);
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function getParameters(): array
    {
        return $this->params;
    }

    /**
     * @param callable(): array $options
     */
    private function execute(string $method, string $uri, callable $options, bool $returnScriptResult): array
    {
        $refreshed = false;

        while (true) {
            [$response, $status, $body] = $this->send($method, $uri, $options());

            if ($status >= 200 && $status < 300) {
                try {
                    $content = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    throw new FMException('Unreadable response from FileMaker: ' . $e->getMessage(), 0, $e);
                }

                $this->metadata = $content['response']['dataInfo'] ?? [];
                if ($returnScriptResult) {
                    return [
                        'error' => $content['response']['scriptError'],
                        'result' => $content['response']['scriptResult'] ?? '',
                    ];
                }

                return $content['response']['data'] ?? $content['response'];
            }

            // With FMCloud if the token has expired then we get a status code of 401 (not to be confused with
            // FileMaker's 401, no records) rather than a 200 status code and error 952 as we get on-prem.
            if (401 === $status) {
                if ($refreshed) {
                    throw new FMException($this->reasonPhrase($response), $status);
                }
                $refreshed = true;
                $this->forceTokenRefresh();
                continue;
            }

            // We should be able to decode data from the response body
            $content = json_decode($body, false);
            // But not always
            if (!is_object($content) || empty($content->messages[0])) {
                throw new FMException($this->reasonPhrase($response), $status);
            }

            $fmCode = (int)$content->messages[0]->code;
            if (401 === $fmCode) {
                // no records found
                return [];
            }

            // if the token has expired or is invalid then in theory 952 will come back,
            // but sometimes you get 105 missing layout (go figure), so try a token refresh
            if (!$refreshed && in_array($fmCode, self::TOKEN_ERROR_CODES, true)) {
                $refreshed = true;
                $this->forceTokenRefresh();
                continue;
            }

            throw new FMException($content->messages[0]->message, $content->messages[0]->code);
        }
    }

    /**
     * @return array{0: ResponseInterface, 1: int, 2: string} The response, its HTTP status and body
     *
     * @throws FMException
     */
    private function send(string $method, string $uri, array $options): array
    {
        $options['headers'] = $this->mergeHeaders(['Content-Type' => 'application/json'], $options['headers'] ?? []);
        $options['auth_bearer'] = $this->token;

        try {
            $response = $this->client->request($method, $this->baseURI . $uri, $options);

            // Responses are lazy, so this is where the request really happens
            return [$response, $response->getStatusCode(), $response->getContent(false)];
        } catch (TransportExceptionInterface $e) {
            throw new FMException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Merge header sets, case-insensitively, later sets winning. Headers may be given as
     * [name => value] or as a list of 'Name: value' strings.
     *
     * @return array<string, string>
     */
    private function mergeHeaders(array ...$sets): array
    {
        $merged = [];
        foreach ($sets as $set) {
            foreach ($set as $name => $value) {
                if (is_int($name)) {
                    [$name, $value] = array_pad(explode(':', (string)$value, 2), 2, '');
                }
                $merged[strtolower(trim($name))] = [trim($name), is_array($value) ? implode(', ', $value) : trim((string)$value)];
            }
        }

        return array_column($merged, 1, 0);
    }

    private function reasonPhrase(ResponseInterface $response): string
    {
        foreach ($response->getInfo('response_headers') ?? [] as $line) {
            if (preg_match('#^HTTP/\S+ \d{3} (.+)$#', $line, $matches)) {
                return trim($matches[1]);
            }
        }

        $status = $response->getInfo('http_code');

        return self::REASON_PHRASES[$status] ?? sprintf('HTTP %s', $status);
    }

    private function setBaseURL(): void
    {
        $this->baseURI =
            ('http' === substr($this->params['host'], 4) ? $this->params['host'] : 'https://' . $this->params['host']) .
            (in_array((int)$this->params['port'], [80, 443], true) ? '' : ':'.$this->params['port']) .
            ('/' === substr($this->params['host'], -1) ? '' : '/') .
            'fmi/data/v1/databases/' .
            $this->params['dbname'] . '/';
    }

    /**
     * @throws AuthenticationException
     */
    private function fetchToken(): void
    {
        if($token = $this->readTokenFromDisk()) {
            $this->token = $token;
            return;
        }

        if(isset($this->params['serverVersion']) && $this->params['serverVersion'] === self::SERVER_VERSION_CLOUD) {
            $this->fetchCloudToken();
            return;
        }

        try {
            $response = $this->client->request('POST', $this->baseURI . 'sessions', [
                'headers' => ['Content-Type' => 'application/json'],
                'auth_basic' => [$this->params['user'], $this->params['password']],
            ]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new AuthenticationException($e->getMessage(), $e->getCode(), $e);
        }

        if ($status >= 400) {
            if (404 === $status) {
                throw new AuthenticationException($this->reasonPhrase($response), $status);
            }

            $content = json_decode($body, false);
            if (!is_object($content) || empty($content->messages[0])) {
                throw new AuthenticationException($this->reasonPhrase($response), $status);
            }

            throw new AuthenticationException($content->messages[0]->message, (int)$content->messages[0]->code);
        }

        $content = json_decode($body, false);
        if (!is_object($content) || empty($content->response->token)) {
            throw new AuthenticationException('Unknown error', -1);
        }

        $this->token = $content->response->token;
        $this->writeTokenToDisk();
    }

    /**
     * @throws AuthenticationException
     * @noinspection PhpUndefinedNamespaceInspection
     * @noinspection PhpFullyQualifiedNameUsageInspection
     */
    private function fetchCloudToken(): void
    {
        if(!class_exists('\MSDev\FMCloudAuthenticator\Authenticate')) {
            throw new AuthenticationException('You must include matatirosoln/fm-cloud-authentication when using FileMaker Cloud.', -1);
        }

        /** @noinspection PhpUndefinedClassInspection */
        $credentials = new \MSDev\FMCloudAuthenticator\Credentials(
            $this->params['host'],
            $this->params['user'],
            $this->params['password'],
            \MSDev\FMCloudAuthenticator\Credentials::DAPI,
            $this->params['dbname']
        );

        $authenticator = new \MSDev\FMCloudAuthenticator\Authenticate();
        $this->token = $authenticator->fetchToken($credentials);
        $this->writeTokenToDisk();
    }


    /**
     * @throws AuthenticationException
     */
    private function forceTokenRefresh(): void
    {
        $file = $this->getTokenDiskLocation();
        file_put_contents($file, '');

        $this->fetchToken();
    }

    /**
     * @return boolean|string
     */
    private function readTokenFromDisk()
    {
        $file = $this->getTokenDiskLocation();
        if(!file_exists($file)) {
            return false;
        }

        $content = file_get_contents($file);
        if(empty($content)) {
            return false;
        }

        return $content;
    }

    /**
     * Write the Data API token to disk for later access
     */
    private function writeTokenToDisk(): void
    {
        $file = $this->getTokenDiskLocation();
        file_put_contents($file, $this->token);
    }

    /**
     * Determine where to save the Data API token
     */
    private function getTokenDiskLocation(): string
    {
        $salt = md5(__DIR__);
        return sprintf('%s%sfmp-token-%s.txt', sys_get_temp_dir(), DIRECTORY_SEPARATOR, $salt);
    }

}
