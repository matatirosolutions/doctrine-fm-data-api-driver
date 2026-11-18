<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Driver;

use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;
use MSDev\DoctrineFMDataAPIDriver\Exception\AuthenticationException;
use MSDev\DoctrineFMDataAPIDriver\Exception\FMException;
use MSDev\DoctrineFMDataAPIDriver\Exception\NotImplementedException;
use MSDev\DoctrineFMDataAPIDriver\Utility\QueryBuilder;
use PHPSQLParser\PHPSQLParser;
use Throwable;

/**
 * Translates the SQL which Doctrine generates into Data API requests.
 */
class FMStatement implements Statement
{
    /** @var array<int|string, mixed> */
    private array $params = [];

    /** @var array|null The SQL, as parsed by PHPSQLParser */
    private ?array $tokens = null;

    public function __construct(
        private readonly string $sql,
        private readonly FMConnection $conn,
    ) {
    }

    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        $this->params[$param] = $value;
    }

    /**
     * @throws NotImplementedException|AuthenticationException|FMException
     */
    public function execute(): Result
    {
        $tokens = $this->tokens ??= $this->parse();
        // Statements are re-used with new values (e.g. a batch of inserts), so work from a snapshot
        $params = $this->params;

        switch (strtolower((string)array_key_first($tokens))) {
            case 'select':
                [$builder, $records, $metadata] = $this->perform($tokens, $params);
                $this->rememberRecordId($builder, $records);
                if ($builder->isCount()) {
                    $alias = $tokens['SELECT'][0]['alias']['no_quotes']['parts'][0] ?? 'count';

                    return new FMResult([[$alias => (int)($metadata['foundCount'] ?? 0)]]);
                }

                return FMResult::fromRecords($records, $this->resultColumns($tokens), $metadata);

            case 'insert':
                [, $created] = $this->perform($tokens, $params);
                $this->conn->recordInsert((string)($created['recordId'] ?? ''));

                return new FMResult([], 1, 0);

            case 'update':
            case 'delete':
                if ($this->conn->isTransactionOpen()) {
                    $this->conn->defer(fn() => $this->perform($tokens, $params));
                } else {
                    $this->perform($tokens, $params);
                }

                return new FMResult([], 1, 0);
        }

        throw new NotImplementedException('Unknown request type');
    }

    /**
     * @return array{0: QueryBuilder, 1: array, 2: array} The builder, the records returned, and the response's dataInfo
     *
     * @throws AuthenticationException|FMException|NotImplementedException
     */
    private function perform(array $tokens, array $params): array
    {
        $builder = new QueryBuilder($this->conn);
        $builder->getQueryFromRequest($tokens, $this->sql, $params);

        $request = $this->conn->getNativeConnection();
        $records = $request->performFMRequest($builder->getMethod(), $builder->getUri(), $builder->getOptions());

        return [$builder, $records, $request->getMetadata()];
    }

    /**
     * When a single record has been found by a single field (i.e. loading an entity by its key) hold on to its
     * record id, so that a following UPDATE or DELETE of that entity doesn't have to run a second _find to get it.
     */
    private function rememberRecordId(QueryBuilder $builder, array $records): void
    {
        $uri = $builder->getUri();
        if ('POST' !== $builder->getMethod() || !str_ends_with($uri, '/_find') || 1 !== count($records) || !isset($records[0]['recordId'])) {
            return;
        }

        $query = json_decode($builder->getOptions()['body'] ?? '', true)['query'] ?? [];
        if (1 !== count($query) || 1 !== count($query[0]) || !is_scalar(current($query[0]))) {
            return;
        }

        $this->conn->rememberRecordId(
            explode('/', $uri)[1],
            (string)key($query[0]),
            (string)preg_replace('/^==/', '', (string)current($query[0])),
            (int)$records[0]['recordId']
        );
    }

    /**
     * @throws FMException
     */
    private function parse(): array
    {
        try {
            $tokens = (new PHPSQLParser())->parse($this->sql);
        } catch (Throwable $e) {
            throw new FMException('Unable to parse the SQL: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($tokens) || [] === $tokens) {
            throw new FMException('Unable to parse the SQL: ' . $this->sql);
        }

        return $tokens;
    }

    /**
     * The columns of the result, in order, each with the alias Doctrine expects and the Data API
     * field (or pseudo field) it's read from.
     *
     * @return list<array{alias: string, source: string}>
     */
    private function resultColumns(array $tokens): array
    {
        $select = $tokens['SELECT'];
        // When a limit is applied the real query is wrapped in two sub queries
        if ('subquery' === ($tokens['FROM'][0]['expr_type'] ?? null)) {
            $select = $tokens['FROM'][0]['sub_tree']['FROM'][0]['sub_tree']['SELECT'];
        }

        $columns = [];
        foreach ($select as $field) {
            $columns[] = [
                'alias' => $field['alias']['no_quotes']['parts'][0],
                'source' => $field['no_quotes']['parts'][1],
            ];
        }

        return $columns;
    }
}
