<?php
/**
 * Created by PhpStorm.
 * User: SteveWinter
 * Date: 17/02/2017
 * Time: 16:42
 */

namespace MSDev\DoctrineFMDataAPIDriver;

use Doctrine\DBAL\Connection as AbstractConnection;
use Doctrine\DBAL\DBALException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use MSDev\DoctrineFMDataAPIDriver\Exception\FMException;
use MSDev\DoctrineFMDataAPIDriver\Exception\AuthenticationException;

class FMConnection extends AbstractConnection
{

    /**
     * @var
     */
    private $connection = null;

    /**
     * @var FMStatement
     */
    private $statement = null;

    /**
     * @var bool
     */
    private $transactionOpen = false;

    /**
     * @var array
     */
    protected $params;

    protected $baseURI;
    protected $token;
    protected $retried = false;

    public $queryStack = [];

    /**
     * FMConnection constructor.
     *
     * @param array $params
     * @param FMDriver $driver
     *
     * @throws AuthenticationException
     * @throws DBALException
     */
    public function __construct(array $params, FMDriver $driver)
    {
        $this->params = $params;
        $this->setBaseURL($params['host'], $params['dbname']);
        $this->fetchToken($params);

        parent::__construct($params, $driver);
    }

    public function rollBack()
    {
        // this method must exist, but rollback isn't possible so nothing is implemented
    }

    public function prepare($prepareString)
    {
        $this->statement = new FMStatement($prepareString, $this);
        $this->statement->setFetchMode($this->defaultFetchMode);

        return $this->statement;
    }

    public function beginTransaction()
    {
        $this->transactionOpen = true;
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function query()
    {
        $args = func_get_args();
        $sql = $args[0];

        $stmt = $this->prepare($sql);
        $stmt->execute();

        return $stmt;
    }

    public function commit()
    {
        /** @var FMStatement $stmt */
        foreach($this->queryStack as $stmt) {
            $stmt->performCommand();
        }

        $this->queryStack = [];
        $this->transactionOpen = false;

        return true;
    }


    public function isTransactionOpen()
    {
        return $this->transactionOpen;
    }

    /**
     * @param null $name
     *
     * @return string
     *
     * @throws FMException
     */
    public function lastInsertId($name = null)
    {
        $this->statement->performCommand();
        unset($this->queryStack[$this->statement->id]);

        return $this->statement->extractID();
    }

    /**
     * At present it's not possible to get metadata from the Data API
     * so for now this is hard coded.
     *
     * @return string
     */
    public function getServerVersion()
    {
        return 'FMS Data API v1';
    }

    /**
     * @return
     */
    public function getConnection()
    {
        return $this->connection;
    }

    public function getParameters()
    {
        return $this->params;
    }

    /**
     * @param $method
     * @param $uri
     * @param $options
     *
     * @return array
     *
     * @throws AuthenticationException
     * @throws FMException
     */
    public function performFMRequest($method, $uri, $options)
    {
        $client = new Client();
        $headers = [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => sprintf('Bearer %s', $this->token),
            ]
        ];

        try {
            $response = $client->request($method, $this->baseURI.$uri, array_merge($headers, $options));
            $content = json_decode($response->getBody()->getContents(), true);

            return isset($content['response']['data']) ? $content['response']['data'] : $content['response'];
        } catch (\Exception $e) {
            /** @var ClientException $e */
            $content = json_decode($e->getResponse()->getBody()->getContents());
            if(401 == $content->messages[0]->code) {
                // no records found
                return [];
            }

            // if the token has expired or is invalid then in theory 952 will come back
            // but sometimes you get 105 missing layout (go figure), so try a token refresh
            if(in_array($content->messages[0]->code, [105, 952]) && !$this->retried) {
                $this->retried = true;
                $this->forceTokenRefresh();
                return $this->performFMRequest($method, $uri, $options);

            }
            throw new FMException($content->messages[0]->message, $content->messages[0]->code);
        } catch(GuzzleException $e) {
            throw new AuthenticationException('Unknown error', -1);
        }
    }

    /**
     * @param string $host
     * @param string $database
     */
    private function setBaseURL(string $host, string $database)
    {
        $this->baseURI =
            ('http' == substr($host, 4) ? $host : 'https://' . $host) .
            ('/' == substr($host, -1) ? '' : '/') .
            'fmi/data/v1/databases/' .
            $database . '/';
    }

    /**
     * @param array $params
     *
     * @throws AuthenticationException
     */
    private function fetchToken(array $params)
    {
        if($token = $this->readTokenFromDisk()) {
            $this->token = $token;
            return;
        }

        $client = new Client();
        try {
            $response = $client->request('POST', $this->baseURI . 'sessions', [
                'headers' => [
                    'Content-Type' => 'application/json'
                ],
                'auth' => [
                    $params['user'], $params['password']
                ]
            ]);

            $content = json_decode($response->getBody()->getContents());
            $this->token = $content->response->token;
            $this->writeTokenToDisk();
        } catch (\Exception $e) {
            /** @var ClientException $e */
            if(404 == $e->getResponse()->getStatusCode()) {
                throw new AuthenticationException($e->getResponse()->getReasonPhrase(), $e->getResponse()->getStatusCode());
            }

            $content = json_decode($e->getResponse()->getBody()->getContents());
            throw new AuthenticationException($content->messages[0]->message, $content->messages[0]->code);
        } catch(GuzzleException $e) {
            throw new AuthenticationException('Unknown error', -1);
        }
    }

    /**
     * @param $params
     *
     * @throws AuthenticationException
     */
    private function forceTokenRefresh()
    {
        $path = $this->getTokenDiskLocation();
        unlink($path);

        $this->fetchToken($this->params);
    }

    /**
     * @return boolean|string
     */
    private function readTokenFromDisk()
    {
        $path = $this->getTokenDiskLocation();
        if(!file_exists($path)) {
            return false;
        }

        return file_get_contents($path);
    }

    /**
     * Write the Data API token to disk for later access
     */
    private function writeTokenToDisk()
    {
        $path = $this->getTokenDiskLocation();
        file_put_contents($path, $this->token);
    }

    /**
     * Determine where to save the Data API token
     *
     * @return string
     */
    private function getTokenDiskLocation()
    {
        return sys_get_temp_dir().'fmp-token.txt';
    }
}