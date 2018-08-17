<?php
/**
 * Created by PhpStorm.
 * User: SteveWinter
 * Date: 17/02/2017
 * Time: 16:42
 */

namespace MSDev\DoctrineFMDataAPIDriver;

use Doctrine\DBAL\Connection as AbstractConnection;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
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


    public function lastInsertId($name = null)
    {
        $this->statement->performCommand();
        unset($this->queryStack[$this->statement->id]);

        return $this->statement->extractID();
    }

    public function getServerVersion()
    {
        return $this->connection->getAPIVersion();
    }

    /**
     * @return FileMaker
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
            $content = json_decode($e->getResponse()->getBody()->getContents());
            if(401 == $content->messages[0]->code) {
                // no records found
                return [];
            }

            // if the token has expired or is invalid then in theory 952 will come back
            // but sometimes you get 105 missing layout (go figure), so try a token refresh
            if(in_array($content->messages[0]->code, [105, 952]) && !$this->retried) {
                $this->retried = true;
                $this->forceTokenRefresh($this->params);
                return $this->performFMRequest($method, $uri, $options);

            }
            throw new FMException($content->messages[0]->message, $content->messages[0]->code);
        }
    }

    private function setBaseURL($host, $database)
    {
        $this->baseURI =
            ('http' == substr($host, 4) ? $host : 'https://' . $host) .
            ('/' == substr($host, -1) ? '' : '/') .
            'fmi/data/v1/databases/' .
            $database . '/';
    }

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
        }
    }

    private function forceTokenRefresh($params)
    {
        $path = $this->getTokenDiskLocation();
        unlink($path);

        $this->fetchToken($this->params);
    }

    private function readTokenFromDisk()
    {
        $path = $this->getTokenDiskLocation();
        if(!file_exists($path)) {
            return false;
        }

        return file_get_contents($path);
    }

    private function writeTokenToDisk()
    {
        $path = $this->getTokenDiskLocation();
        file_put_contents($path, $this->token);
    }

    private function getTokenDiskLocation()
    {
        return sys_get_temp_dir().'fmp-token.txt';
    }
}