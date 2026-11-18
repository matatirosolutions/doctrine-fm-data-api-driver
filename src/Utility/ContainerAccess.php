<?php
/**
 * Created by PhpStorm.
 * User: SteveWinter
 * Date: 10/04/2017
 * Time: 15:10
 */

namespace MSDev\DoctrineFMDataAPIDriver\Utility;

use Doctrine\DBAL\Connection;
use MSDev\DoctrineFMDataAPIDriver\Exception\FMException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;


class ContainerAccess
{

    /**
     * @var FMRequest
     */
    protected $con;

    private HttpClientInterface $client;

    /**
     * @var ScriptAccess
     */
    protected $script;

    public function __construct(Connection $conn, ScriptAccess $script, ?HttpClientInterface $client = null)
    {
        $this->con = $conn->getNativeConnection();
        $this->script = $script;
        $this->client = $client ?? HttpClient::create();
    }

    /**
     * Retrieve externally stored container content
     *
     * @param string $path
     *
     * @return string
     *
     * @throws FMException
     */
    public function getExternalContainerContent($path) {
        [$url, $auth] = $this->generateURL($path);

        try {
            $response = $this->client->request('GET', $url, $auth ? ['auth_basic' => $auth] : []);

            return $this->contentOrFail($response->getStatusCode(), $response->getContent(false));
        } catch (ExceptionInterface $e) {
            throw new FMException($e->getMessage(), $e->getCode());
        }
    }

    /**
     * Retrieve DataAPI streamed content
     *
     * The Data API hands back a URL which relies on a session cookie it sets on the first
     * response, so cookies are carried across any redirects.
     *
     * @param $url
     * @return string
     *
     * @throws FMException
     */
    public function getStreamedContainerContent($url) {
        $cookies = [];

        try {
            for ($hop = 0; $hop < 5; $hop++) {
                $options = ['max_redirects' => 0];
                if ($cookies) {
                    $options['headers'] = ['Cookie' => implode('; ', array_map(
                        static fn($name, $value) => $name . '=' . $value,
                        array_keys($cookies),
                        $cookies
                    ))];
                }

                $response = $this->client->request('GET', $url, $options);
                $status = $response->getStatusCode();
                $headers = $response->getHeaders(false);

                foreach ($headers['set-cookie'] ?? [] as $cookie) {
                    [$pair] = explode(';', $cookie, 2);
                    if (str_contains($pair, '=')) {
                        [$name, $value] = explode('=', $pair, 2);
                        $cookies[trim($name)] = trim($value);
                    }
                }

                if ($status >= 300 && $status < 400 && isset($headers['location'][0])) {
                    $url = $this->resolveLocation($url, $headers['location'][0]);
                    continue;
                }

                return $this->contentOrFail($status, $response->getContent(false));
            }
        } catch (ExceptionInterface $e) {
            throw new FMException($e->getMessage(), $e->getCode());
        }

        throw new FMException('Too many redirects retrieving container content');
    }

    /**
     * Calls a FileMaker script to insert content into a container
     *
     * @param string $layout        The FM layout which the field to insert into is on
     * @param string $idField       The name of the field to locate the record using
     * @param string $uuid          The uuid to locate the record by
     * @param string $field         The name of the field
     * @param string $assetPath     A URL which the asset can be retrieved from
     *                              This either needs to be publically accessible, or include
     *                              the necessary credentials to access the asset using the
     *                              FileMaker InsertFromURL script step
     * @throws FMException
     *
     */
    public function insertIntoContainer($layout, $idField, $uuid, $field, $assetPath)
    {
        $data = json_encode([
            'idField' => $idField,
            'uuid' => $uuid,
            'field' => $field,
            'asset' => $assetPath,
        ]);

        try {
            $this->script->performScript($layout, $uuid,'ImportToContainer', $data);
        } catch(\Exception $e) {
            throw new FMException($e->getMessage(), $e->getCode());
        }
    }

    /**
     * @param string $layout
     * @param int $recId
     * @param $field
     * @param $file
     * @param int $repetition
     *
     * @return array
     *
     * @throws FMException
     */
    public function performContainerInsert($layout, $recId, $field, $file, $repetition = 1)
    {
        $uri = sprintf('layouts/%s/records/%s/containers/%s/%s', $layout, $recId, $field, $repetition);

        try {
            return $this->con->performUpload($uri, $file);
        } catch(\Exception $e) {
            throw new FMException($e->getMessage(), $e->getCode());
        }
    }

    /**
     * Generate a request URL for container data from Doctrine parameters
     * and the passed FM path
     *
     * @param string $path
     * @return array{0: string, 1: array|null} The URL, and basic auth credentials if there are any
     */
    private function generateURL($path)
    {
        $params = $this->con->getParameters();
        $url = parse_url($params['host']);
        $host = array_key_exists('host', $url)
            ? $url['host']
            : $url['path'];
        $proto = (array_key_exists('scheme', $url)
                ? $url['scheme']
                : 'https')
            . '://';

        if(!empty($params['port'])) {
            $host .= ':'.$params['port'];
        }

        $auth = empty($params['user'])
            ? null
            : [$params['user'], $params['password'] ?? ''];

        return [$proto.$host.htmlspecialchars_decode($path), $auth];
    }

    /**
     * @throws FMException
     */
    private function contentOrFail(int $status, string $content): string
    {
        if ($status >= 400) {
            throw new FMException(sprintf('Unable to retrieve container content, HTTP status %d', $status), $status);
        }

        return $content;
    }

    private function resolveLocation(string $current, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $base = parse_url($current);
        $origin = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        return $origin . rtrim(dirname($base['path'] ?? '/'), '/') . '/' . $location;
    }
}
