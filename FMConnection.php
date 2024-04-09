<?php
declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver;

use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\ServerInfoAwareConnection;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;
use Exception;
use MSDev\DoctrineFMDataAPIDriver\Exception\FMException;
use MSDev\DoctrineFMDataAPIDriver\Exception\AuthenticationException;
use MSDev\DoctrineFMDataAPIDriver\Exception\NotImplementedException;
use MSDev\DoctrineFMDataAPIDriver\Utility\FMRequest;

class FMConnection implements ServerInfoAwareConnection
{
    private ?FMRequest $connection = null;

    private ?FMStatement $statement = null;

    private bool $transactionOpen = false;

    protected array $params;

    public array $queryStack = [];

    public ?array $metadata;

    /**
     * FMConnection constructor.
     *
     * @param array $params
     * @param FMDriver $driver
     *
     * @throws AuthenticationException
     */
    public function __construct(array $params, FMDriver $driver)
    {
        $this->params = $params;
        $this->connection = new FMRequest($params);
    }

    public function rollBack()
    {
        return true;
    }

    /**
     * @throws Exception
     */
    public function prepare(string $sql): Statement
    {
        $this->statement = new FMStatement($sql, $this);
        return $this->statement;
    }

    public function beginTransaction()
    {
        $this->transactionOpen = true;
        return true;
    }

    /**
     * @throws NotImplementedException|AuthenticationException|FMException|Exception
     */
    public function query(string $sql): Result
    {
        $stmt = $this->prepare($sql);
        $stmt->execute();

        return $stmt;
    }

    /**
     * @return bool
     *
     * @throws AuthenticationException|FMException
     */
    public function commit(): bool
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
     * @return string|int
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
     */
    public function getServerVersion(): string
    {
        return 'FMS Data API v1';
    }

    public function getConnection()
    {
        return $this->connection;
    }

    public function getNativeConnection()
    {
        return $this->connection;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    /**
     * @return array|null
     */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function quote($value, $type = ParameterType::STRING)
    {
        throw new NotImplementedException('Quote method is not implemented in this connection');
    }

    public function exec(string $sql): int
    {
        throw new NotImplementedException('Exec method is not implemented in this connection');
    }

    public function requiresQueryForServerVersion(): bool
    {
        return true;
    }

}
