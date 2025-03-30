<?php

namespace MSDev\DoctrineFMDataAPIDriver\Utility;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Exception\MalformedUriException;
use MSDev\DoctrineFMDataAPIDriver\Exception\AuthenticationException;
use MSDev\DoctrineFMDataAPIDriver\Exception\FMException;

class FMRequest
{
    private const SERVER_VERSION_CLOUD = 'FMCloud';

    private bool $retried = false;
    private array $metadata = [];

    private ?string $token = null;

    private ?string $baseURI = null;

    /**
     * @throws AuthenticationException
     */
    public function __construct(
        private readonly array $params
    ) {
        $this->setBaseURL();
        $this->fetchToken();
    }

    public function performFMRequest(string $method, string $uri, array $options, bool $returnScriptResult = false): array
    {
        $client = new Client();
        $headers = [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => sprintf('Bearer %s', $this->token),
                'Accept-Encoding' => 'gzip, deflate, br',
            ]
        ];

        try {
            $response = $client->request($method, $this->baseURI.$uri, array_merge($headers, $options));
            $content = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
            $this->metadata = $content['response']['dataInfo'] ?? [];

            if($returnScriptResult) {
                return [
                    'error' => $content['response']['scriptError'],
                    'result' => $content['response']['scriptResult'] ?? '',
                ];
            }

            return $content['response']['data'] ?? $content['response'];
        } catch (ConnectException | MalformedUriException $e) {
            throw new FMException($e->getMessage(), $e->getCode(), $e);
        } catch (ClientException | ServerException $e) {
            if(null === $e->getResponse()) {
                throw new FMException($e->getMessage(), $e->getCode(), $e);
            }

            // With FMCloud if the token has expired, then we get a status code of 401 (not to be confused with
            // FileMaker's 401, no records) rather than a 200 status code and error 952 as we get on-prem.
            if(401 === $e->getResponse()->getStatusCode()) {
                if($this->retried) {
                    throw new FMException($e->getMessage(), $e->getCode(), $e);
                }

                $this->retried = true;
                $this->forceTokenRefresh();
                return $this->performFMRequest($method, $uri, $options);
            }

            // We should be able to decode data from the response body
            $content = json_decode($e->getResponse()->getBody()->getContents(), false);
            // But not always
            if(null === $content) {
                throw new FMException($e->getResponse()->getReasonPhrase(), $e->getResponse()->getStatusCode());
            }

            if(401 === (int)$content->messages[0]->code) {
                // no records found
                return [];
            }
            // if the token has expired or is invalid then in theory 952 will come back,
            // but sometimes you get 105 missing layout (go figure), so try a token refresh
            if(!$this->retried && in_array((int)$content->messages[0]->code, [105, 952], true)) {
                $this->retried = true;
                $this->forceTokenRefresh();
                return $this->performFMRequest($method, $uri, $options);
            }


            throw new FMException($content->messages[0]->message, $content->messages[0]->code);
        } catch(GuzzleException $e) {
            throw new AuthenticationException('Unknown error', -1);
        }
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getToken(): ?string
    {
        return $this->token;
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

        $client = new Client();
        try {
            $response = $client->request('POST', $this->baseURI . 'sessions', [
                'headers' => [
                    'Content-Type' => 'application/json'
                ],
                'auth' => [
                    $this->params['user'], $this->params['password']
                ]
            ]);

            $content = json_decode($response->getBody()->getContents(), false);
            $this->token = $content->response->token;
            $this->writeTokenToDisk();
        } catch (ConnectException | MalformedUriException $e) {
            throw new AuthenticationException($e->getMessage(), $e->getCode(), $e);
        } catch (Exception $e) {
            /** @var ClientException $e */
            if(null === $e->getResponse()) {
                throw new AuthenticationException($e->getMessage(), $e->getCode(), $e);
            }

            if(404 === $e->getResponse()->getStatusCode()) {
                throw new AuthenticationException($e->getResponse()->getReasonPhrase(), $e->getResponse()->getStatusCode());
            }

            $content = json_decode($e->getResponse()->getBody()->getContents(), false);
            throw new AuthenticationException($content->messages[0]->message, $content->messages[0]->code);
        } catch(GuzzleException $e) {
            throw new AuthenticationException('Unknown error', -1);
        }
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
