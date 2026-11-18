<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Driver;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use MSDev\DoctrineFMDataAPIDriver\Exception\AuthenticationException;
use MSDev\DoctrineFMDataAPIDriver\Exception\FMException;
use MSDev\DoctrineFMDataAPIDriver\Exception\NotImplementedException;
use MSDev\DoctrineFMDataAPIDriver\Utility\FMRequest;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;
use MSDev\DoctrineFMDataAPIDriver\Id\FMIdentityGenerator;

/**
 * FileMaker has no transactions. To approximate them, UPDATE and DELETE statements issued while a
 * transaction is open are held back until commit() and dropped by rollBack(). INSERTs always run
 * straight away, because the key FileMaker generates is needed before the transaction ends.
 */
class FMConnection implements Connection
{
    private FMRequest $connection;

    private bool $transactionOpen = false;

    /** @var list<callable(): mixed> */
    private array $deferred = [];

    /** @var array<string, int> Savepoint name => number of writes being held back when it was created */
    private array $savepoints = [];

    private ?string $lastInsertedRecordId = null;

    /**
     * @throws AuthenticationException
     */
    public function __construct(protected array $params, ?HttpClientInterface $client = null)
    {
        $this->connection = new FMRequest($params, $client);
    }

    public function prepare(string $sql): Statement
    {
        return new FMStatement($sql, $this);
    }

    /**
     * @throws NotImplementedException|AuthenticationException|FMException
     */
    public function query(string $sql): Result
    {
        return $this->prepare($sql)->execute();
    }

    public function beginTransaction(): void
    {
        $this->transactionOpen = true;
    }

    /**
     * @throws AuthenticationException|FMException
     */
    public function commit(): void
    {
        $this->transactionOpen = false;
        $this->savepoints = [];

        // Take each write off the queue before running it so a failure can't cause it to run twice
        while ($write = array_shift($this->deferred)) {
            try {
                $write();
            } catch (Throwable $e) {
                $this->deferred = [];
                throw $e;
            }
        }
    }

    public function rollBack(): void
    {
        $this->deferred = [];
        $this->savepoints = [];
        $this->transactionOpen = false;
    }

    public function isTransactionOpen(): bool
    {
        return $this->transactionOpen;
    }

    public function createSavepoint(string $name): void
    {
        $this->savepoints[$name] = count($this->deferred);
    }

    public function releaseSavepoint(string $name): void
    {
        unset($this->savepoints[$name]);
    }

    /**
     * Drop the writes which were queued after the savepoint was created
     */
    public function rollbackToSavepoint(string $name): void
    {
        if (isset($this->savepoints[$name])) {
            $this->deferred = array_slice($this->deferred, 0, $this->savepoints[$name]);
            unset($this->savepoints[$name]);
        }
    }

    /**
     * @param callable(): mixed $write
     */
    public function defer(callable $write): void
    {
        $this->deferred[] = $write;
    }

    public function recordInsert(string $recordId): void
    {
        $this->lastInsertedRecordId = $recordId;
    }

    /**
     * The FileMaker record id of the record created by the most recent INSERT.
     *
     * Where the entity's identifier is some other field (typically an auto-enter Get(UUID) calculation)
     * then FMIdentityGenerator reads that field from the record with this id.
     *
     * @throws FMException
     */
    public function lastInsertId(): int|string
    {
        return $this->lastInsertedRecordId
            ?? throw new FMException('No record has been inserted on this connection');
    }

    /**
     * At present, it's not possible to get the version from the Data API
     * so for now this is hard coded.
     */
    public function getServerVersion(): string
    {
        return 'FMS Data API v1';
    }

    public function getConnection(): FMRequest
    {
        return $this->connection;
    }

    public function getNativeConnection(): FMRequest
    {
        return $this->connection;
    }

    public function getToken(): ?string
    {
        return $this->connection->getToken();
    }

    /**
     * The `dataInfo` from the most recent response
     */
    public function getMetadata(): array
    {
        return $this->connection->getMetadata();
    }

    public function quote(string $value): string
    {
        throw new NotImplementedException('Quote method is not implemented in this connection');
    }

    /**
     * Doctrine nests transactions using savepoints. There's nothing to send to FileMaker for those,
     * but they're applied to the writes which are being held back. Nothing else can be exec()'d.
     */
    public function exec(string $sql): int|string
    {
        if (preg_match('/^\s*(SAVEPOINT|RELEASE SAVEPOINT|ROLLBACK TO SAVEPOINT)\s+(\w+)\s*$/i', $sql, $matches)) {
            match (strtoupper($matches[1])) {
                'SAVEPOINT' => $this->createSavepoint($matches[2]),
                'RELEASE SAVEPOINT' => $this->releaseSavepoint($matches[2]),
                default => $this->rollbackToSavepoint($matches[2]),
            };

            return 0;
        }

        throw new NotImplementedException('Exec method is not implemented in this connection');
    }
}
