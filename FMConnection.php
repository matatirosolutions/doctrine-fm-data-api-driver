<?php


namespace MSDev\DoctrineFMDataAPIDriver;


use Doctrine\DBAL\Connection as AbstractConnection;
use Doctrine\DBAL\DBALException;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use MSDev\DoctrineFMDataAPIDriver\Exception\FMException;
use MSDev\DoctrineFMDataAPIDriver\Exception\AuthenticationException;
use MSDev\DoctrineFMDataAPIDriver\Exception\NotImplementedException;

class FMConnection extends AbstractConnection
{
    /** @var */
    private $connection = null;

    /** @var FMStatement */
    private $statement = null;

    /** @var bool */
    private $transactionOpen = false;

    /** @var array */
    protected $params;

    /** @var string */
    protected $baseURI;

    /** @var string */
    protected $token;

    /** @var bool */
    protected $retried = false;

    /** @var array  */
    public $queryStack = [];

    /** @var array|null */
    public $metadata;

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
     * @throws NotImplementedException|AuthenticationException|FMException
     */
    public function query()
    {
        $args = func_get_args();
        $sql = $args[0];

        $stmt = $this->prepare($sql);
        $stmt->execute();

        return $stmt;
    }

    /**
     * @return bool
     *
     * @throws AuthenticationException|FMException
     */
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
     * @throws FMException|AuthenticationException
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
     * @return string
     */
    public function getToken()
    {
        return $this->token;
    }

    /**
     * @return string
     */
    public function getBaseURI()
    {
        return $this->baseURI;
    }

    /**
     * @param string $method
     * @param string $uri
     * @param array $options
     *
     * @return array
     *
     * @throws AuthenticationException
     * @throws FMException
     */
    public function performFMRequest(string $method, string $uri, array $options, bool $returnScriptResult = false): array
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
            $this->metadata = $content['response']['dataInfo'] ?? null;

            if($returnScriptResult) {
                return [
                    'error' => $content['response']['scriptError'],
                    'result' => $content['response']['scriptResult'] ?? '',
                ];
            }

            return isset($content['response']['data']) ? $content['response']['data'] : $content['response'];
        } catch (Exception $e) {
            /** @var ClientException $e */
            if(null === $e->getResponse()) {
                throw new FMException($e->getMessage(), $e->getCode(), $e);
            }

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
            ('http' === substr($host, 4) ? $host : 'https://' . $host) .
            ('/' === substr($host, -1) ? '' : '/') .
            'fmi/data/v1/databases/' .
            $database . '/';
    }

    /**
     * @return array|null
     */
    public function getMetadata(): ?array
    {
        return $this->metadata;
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
        } catch (Exception $e) {
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
     * @throws AuthenticationException
     */
    private function forceTokenRefresh()
    {
        $file = $this->getTokenDiskLocation();
        file_put_contents($file, '');

        $this->fetchToken($this->params);
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
    private function writeTokenToDisk()
    {
        $file = $this->getTokenDiskLocation();
        file_put_contents($file, $this->token);
    }

    /**
     * Determine where to save the Data API token
     *
     * @return string
     */
    private function getTokenDiskLocation()
    {
        return sys_get_temp_dir().DIRECTORY_SEPARATOR.'fmp-token.txt';
    }
}