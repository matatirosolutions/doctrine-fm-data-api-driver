<?php

namespace MSDev\DoctrineFMDataAPIDriver;

use ArrayIterator;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Exception;
use IteratorAggregate;
use MSDev\DoctrineFMDataAPIDriver\Exception\AuthenticationException;
use MSDev\DoctrineFMDataAPIDriver\Exception\FMException;
use MSDev\DoctrineFMDataAPIDriver\Exception\MethodNotSupportedException;
use MSDev\DoctrineFMDataAPIDriver\Exception\NotImplementedException;
use MSDev\DoctrineFMDataAPIDriver\Utility\MetaData;
use MSDev\DoctrineFMDataAPIDriver\Utility\QueryBuilder;
use MSDev\DoctrineFMDataAPIDriver\FMResult;
use PDO;
use PHPSQLParser\PHPSQLParser;
use stdClass;


class FMStatement implements IteratorAggregate, Statement
{
    /** @var int */
    public $id;

    /** @var resource */
    private $_stmt;

    /** @var array */
    private $_bindParam = [];

    /**
     * @var string Name of the default class to instantiate when fetch mode is \PDO::FETCH_CLASS.
     */
    private $defaultFetchClass = stdClass::class;

    /**
     * @var string Constructor arguments for the default class to instantiate when fetch mode is \PDO::FETCH_CLASS.
     */
    private $defaultFetchClassCtorArgs = [];

    /** @var integer */
    private $_defaultFetchMode = PDO::FETCH_BOTH;

    /**
     * @var array The query which has been parsed from the SQL by PHPSQLParser
     */
    private $request;

    /**
     * @var array|stdClass Hold the response from FileMaker be it a result object or an error object
     */
    private $response;

    /**
     * @var array Records returned upon successful query
     */
    private $records = [];

    /** @var int */
    private $numRows = 0;

    /**
     * @var bool Indicates whether the response is in a state where fetching results is possible
     */
    private $result;

    /** @var PHPSQLParser */
    private $sqlParser;

    /** @var QueryBuilder */
    private $qb;

    /** @var FMConnection */
    private $conn;

    private array $metadata = [];

    /**
     * @param string $stmt
     * @param FMConnection $conn
     * @throws Exception
     */
    public function __construct(string $stmt, FMConnection $conn)
    {
        $this->id = Uniqid('', true) . random_int(999, 999999);

        $this->_stmt = $stmt;
        $this->conn = $conn;

        $this->sqlParser = new PHPSQLParser();
        $this->qb = new QueryBuilder($conn);
    }

    /**
     * {@inheritdoc}
     */
    public function bindValue($param, $value, $type = null): bool
    {
        return $this->bindParam($param, $value, $type);
    }

    /**
     * {@inheritdoc}
     */
    public function bindParam($param, &$variable, $type = null, $length = null): bool
    {
        $this->_bindParam[$param] =& $variable;
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function closeCursor(): bool
    {
        if (!$this->_stmt) {
            return false;
        }

        $this->_bindParam = [];
        $this->_stmt = null;
        $this->result = false;

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function columnCount()
    {
        if (!$this->_stmt) {
            return false;
        }

        return count($this->request['SELECT']);
    }

    /**
     * {@inheritdoc}
     */
    public function errorCode()
    {
        /** @var stdClass $this- >response */
        return $this->response->code;
    }

    /**
     * {@inheritdoc}
     */
    public function errorInfo(): array
    {
        /** @var Exception $response */
        $response = $this->response;
        return [
            0 => $response->getMessage(),
            1 => $response->getCode(),
        ];
    }

    /**
     * @throws NotImplementedException|AuthenticationException|FMException|Exception
     */
    public function execute($params = null): Result
    {
        $this->setRequest();
        $this->id = Uniqid('', true) . random_int(999, 999999);
        $this->qb->getQueryFromRequest($this->request, $this->_stmt, $this->_bindParam);

        if ($this->conn->isTransactionOpen()) {
            $clone = clone $this;
            $clone->qb = new QueryBuilder($this->conn);
            $clone->qb->getQueryFromRequest($this->request, $this->_stmt, $this->_bindParam);

            $this->conn->queryStack[$this->id] = $clone;
        } else {
            $this->performCommand();
        }

        return new FMResult($this->request, $this->records, $this->metadata);
    }

    /**
     * @throws AuthenticationException|FMException
     */
    public function performCommand(): void
    {
        $this->records = $this->conn->getNativeConnection()->performFMRequest($this->qb->getMethod(), $this->qb->getUri(), $this->qb->getOptions());
        $this->metadata = $this->conn->getNativeConnection()->getMetadata();
        $this->numRows = count($this->records);
        $this->result = true;
    }

    /**
     * {@inheritdoc}
     */
    public function setFetchMode($fetchMode, $arg2 = null, $arg3 = null): bool
    {
        $this->_defaultFetchMode = $fetchMode;
        $this->defaultFetchClass = $arg2 ?: $this->defaultFetchClass;
        $this->defaultFetchClassCtorArgs = $arg3 ? (array)$arg3 : $this->defaultFetchClassCtorArgs;

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getIterator()
    {
        $data = $this->fetchAll();
        return new ArrayIterator($data);
    }

    /**
     * @throws MethodNotSupportedException
     */
    public function fetch($fetchMode = null, $cursorOrientation = PDO::FETCH_ORI_NEXT, $cursorOffset = 0)
    {
        // do not try fetching from the statement if it's not expected to contain a result
        if (!$this->result) {
            return false;
        }

        $fetchMode = $fetchMode ?: $this->_defaultFetchMode;
        if ($fetchMode === PDO::FETCH_ASSOC) {
            return count($this->records) === 0 ? false : $this->recordToArray(array_shift($this->records));
        }

        throw new MethodNotSupportedException($fetchMode);
    }

    /**
     * @throws MethodNotSupportedException
     */
    public function fetchAll($fetchMode = null, $fetchArgument = null, $ctorArgs = null): array
    {
        $rows = [];

        switch ($fetchMode) {
            case PDO::FETCH_CLASS:
                while ($row = call_user_func_array([$this, 'fetch'], func_get_args())) {
                    $rows[] = $row;
                }
                break;
            case PDO::FETCH_COLUMN:
                while ($row = $this->fetchColumn()) {
                    $rows[] = $row;
                }
                break;
            default:
                while ($row = $this->fetch('fetch mode ' . $fetchMode)) {
                    $rows[] = $row;
                }
        }

        return $rows;
    }

    /**
     * @throws MethodNotSupportedException
     */
    public function fetchColumn($columnIndex = 0)
    {
        $row = $this->fetch(PDO::FETCH_NUM);

        if (false === $row) {
            return false;
        }

        return $row[$columnIndex] ?? null;
    }

    public function rowCount(): int
    {
        return $this->numRows;
    }

    private function setRequest(): void
    {
        $query = $this->populateParams($this->_stmt, $this->_bindParam);
        $tokens = $this->sqlParser->parse($query);

        if ('select' === strtolower(array_keys($tokens)[0])) {
            $tokens = $this->sqlParser->parse($this->_stmt);
        }
        $this->request = $tokens;
    }

    /**
     * Populate parameters, removing characters which will cause issues with later
     * query parsing
     *
     * @param $statement
     * @param $params
     * @return mixed
     */
    private function populateParams($statement, $params)
    {
        return array_reduce($params, static function ($statement, $param) {
            $param = str_ireplace(['?', '(', ')', '@', '#', '`', '--', 'union', 'where', 'rename'], '', $param);
            return strpos($statement, '?')
                ? substr_replace($statement, addslashes($param), strpos($statement, '?'), strlen('?'))
                : $statement;
        }, $statement);
    }

    /**
     * Parses a FileMaker record into an array whose keys are the fields from
     * the requested query.
     *
     * @param array $rec
     * @return array
     */
    private function recordToArray(array $rec): array
    {
        $select = $this->request['SELECT'];
        if ('subquery' === $this->request['FROM'][0]['expr_type']) {
            $select = $this->request['FROM'][0]['sub_tree']['FROM'][0]['sub_tree']['SELECT'];
        }

        $resp = [];
        foreach ($select as $field) {
            if ('rec_id' === $field['no_quotes']['parts'][1]) {
                $resp[$field['alias']['no_quotes']['parts'][0]] = $rec['recordId'];
                continue;
            }
            if ('mod_id' === $field['no_quotes']['parts'][1]) {
                $resp[$field['alias']['no_quotes']['parts'][0]] = $rec['modId'];
                continue;
            }
            if ('rec_meta' === $field['no_quotes']['parts'][1]) {
                $resp[$field['alias']['no_quotes']['parts'][0]] = $this->getMetadataArray();
                continue;
            }

            $data = $rec['fieldData'][$field['no_quotes']['parts'][1]];
            $resp[$field['alias']['no_quotes']['parts'][0]] = $data === '' ? null : $data;
        }

        return $resp;
    }

    /**
     * Find the name of the ID column and return that value from the first record
     *
     * @return string
     * @throws FMException
     */
    public function extractID(): string
    {
        $idColumn = $this->qb->getIdColumn($this->request, new MetaData());
        if ('rec_id' === $idColumn) {
            return $this->records['recordId'];
        }

        $uri = $this->qb->getUri() . '/' . $this->records['recordId'];
        try {
            $record = $this->conn->getNativeConnection()->performFMRequest('GET', $uri, $this->qb->getOptions());
            return $record[0]['fieldData'][$idColumn];
        } catch (Exception $e) {
            throw new FMException('Unable to locate record primary key with error ' . $e->getMessage());
        }

    }

    /**
     * Extract query metadata from the returned response - not currently supported by the
     * DataAPI - hopefully in FMS 18
     *
     * @return string
     */
    private function getMetadataArray(): string
    {
        $meta = $this->conn->getMetadata();
        return json_encode([
            'found' => $meta['foundCount'] ?? 0,
            'fetch' => $meta['returnedCount'] ?? 0,
            'total' => $meta['totalRecordCount'] ?? 0,
        ]);
    }
}
