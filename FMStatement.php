<?php


namespace MSDev\DoctrineFMDataAPIDriver;


use ArrayIterator;
use Doctrine\DBAL\Driver\Statement;
use Exception;
use IteratorAggregate;
use MSDev\DoctrineFMDataAPIDriver\Exception\AuthenticationException;
use MSDev\DoctrineFMDataAPIDriver\Exception\FMException;
use MSDev\DoctrineFMDataAPIDriver\Exception\MethodNotSupportedException;
use MSDev\DoctrineFMDataAPIDriver\Exception\NotImplementedException;
use MSDev\DoctrineFMDataAPIDriver\Utility\MetaData;
use MSDev\DoctrineFMDataAPIDriver\Utility\QueryBuilder;
use PDO;
use PHPSQLParser\PHPSQLParser;


class FMStatement implements IteratorAggregate, Statement
{
    /** @var int */
    public $id;

    /**
     * @var resource
     */
    private $_stmt;

    /**
     * @var array
     */
    private $_bindParam = array();

    /**
     * @var string Name of the default class to instantiate when fetch mode is \PDO::FETCH_CLASS.
     */
    private $defaultFetchClass = '\stdClass';

    /**
     * @var string Constructor arguments for the default class to instantiate when fetch mode is \PDO::FETCH_CLASS.
     */
    private $defaultFetchClassCtorArgs = array();

    /**
     * @var integer
     */
    private $_defaultFetchMode = PDO::FETCH_BOTH;

    /**
     * The query which has been parsed from the SQL by PHPSQLParser
     *
     * @var array
     */
    private $request;

    /**
     * Hold the response from FileMaker be it a result object or an error object
     *
     * @var array
     */
    private $response;

    /**
     * Records returned upon successful query
     *
     * @var array
     */
    private $records = array();

    /**
     * @var int
     */
    private $numRows = 0;

    /**
     * Indicates whether the response is in the state when fetching results is possible
     *
     * @var bool
     */
    private $result;

    /**
     * @var PHPSQLParser
     */
    private $sqlParser;

    /**
     * @var QueryBuilder
     */
    private $qb;

    /** @var FMConnection  */
    private $conn;


    /**
     * @param string $stmt
     * @param FMConnection $conn
     */
    public function __construct(string $stmt, FMConnection $conn)
    {
        $this->id = Uniqid('', true).mt_rand(999, 999999);

        $this->_stmt = $stmt;
        $this->conn = $conn;

        $this->sqlParser = new PHPSQLParser();
        $this->qb = new QueryBuilder($conn);
    }

    /**
     * {@inheritdoc}
     */
    public function bindValue($param, $value, $type = null)
    {
        return $this->bindParam($param, $value, $type);
    }

    /**
     * {@inheritdoc}
     */
    public function bindParam($column, &$variable, $type = null, $length = null)
    {
        $this->_bindParam[$column] =& $variable;

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function closeCursor()
    {
        if ( ! $this->_stmt) {
            return false;
        }

        $this->_bindParam = array();
        $this->_stmt = null;
        $this->result = false;

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function columnCount()
    {
        if ( ! $this->_stmt) {
            return false;
        }

        return count($this->request['SELECT']);
    }

    /**
     * {@inheritdoc}
     */
    public function errorCode()
    {
        return $this->response->code;
    }

    /**
     * {@inheritdoc}
     */
    public function errorInfo()
    {
        return array(
            0 => $this->response->getMessage(),
            1 => $this->response->getCode(),
        );
    }

    /**
     * {@inheritdoc}
     * @throws NotImplementedException|AuthenticationException|FMException
     */
    public function execute($params = null)
    {
        $this->setRequest();
        $this->id = Uniqid('', true).mt_rand(999, 999999);
        $this->qb->getQueryFromRequest($this->request, $this->_stmt, $this->_bindParam);

        if($this->conn->isTransactionOpen()) {
            $this->conn->queryStack[$this->id] = clone $this;
        } else {
            $this->performCommand();
        }
    }

    /**
     * @throws AuthenticationException|FMException
     */
    public function performCommand()
    {
        $this->records = $this->conn->performFMRequest($this->qb->getMethod(), $this->qb->getUri(), $this->qb->getOptions());
        $this->numRows = count($this->records);
        $this->result = true;
    }

    /**
     * {@inheritdoc}
     */
    public function setFetchMode($fetchMode, $arg2 = null, $arg3 = null)
    {
        $this->_defaultFetchMode         = $fetchMode;
        $this->defaultFetchClass         = $arg2 ? $arg2 : $this->defaultFetchClass;
        $this->defaultFetchClassCtorArgs = $arg3 ? (array) $arg3 : $this->defaultFetchClassCtorArgs;

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
     * {@inheritdoc}
     * @throws MethodNotSupportedException
     */
    public function fetch($fetchMode = null, $cursorOrientation = PDO::FETCH_ORI_NEXT, $cursorOffset = 0)
    {
        // do not try fetching from the statement if it's not expected to contain a result
        if (!$this->result) {
            return false;
        }

        $fetchMode = $fetchMode ?: $this->_defaultFetchMode;
        switch ($fetchMode) {
            case PDO::FETCH_ASSOC:
                return count($this->records) === 0 ? false : $this->recordToArray(array_shift($this->records));
            default:
                throw new MethodNotSupportedException($fetchMode);
        }
    }

    /**
     * {@inheritdoc}
     * @throws MethodNotSupportedException
     */
    public function fetchAll($fetchMode = null, $fetchArgument = NULL, $ctorArgs = NULL)
    {
        $rows = array();

        switch ($fetchMode) {
            case PDO::FETCH_CLASS:
                while ($row = call_user_func_array(array($this, 'fetch'), func_get_args())) {
                    $rows[] = $row;
                }
                break;
            case PDO::FETCH_COLUMN:
                while ($row = $this->fetchColumn()) {
                    $rows[] = $row;
                }
                break;
            default:
                while ($row = $this->fetch('fetch mode '.$fetchMode)) {
                    $rows[] = $row;
                }
        }

        return $rows;
    }

    /**
     * {@inheritdoc}
     * @throws MethodNotSupportedException
     */
    public function fetchColumn($columnIndex = 0)
    {
        $row = $this->fetch(PDO::FETCH_NUM);

        if (false === $row) {
            return false;
        }

        return isset($row[$columnIndex]) ? $row[$columnIndex] : null;
    }

    /**
     * {@inheritdoc}
     */
    public function rowCount()
    {
        return $this->numRows;
    }

    private function setRequest()
    {
        $query = $this->populateParams($this->_stmt, $this->_bindParam);
        $tokens = $this->sqlParser->parse($query);

        if('select' === strtolower(array_keys($tokens)[0])) {
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
        return array_reduce($params, function($statement, $param) {
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
     * @param  array $rec
     * @return array
     */
    private function recordToArray(array $rec)
    {
        $select = $this->request['SELECT'];
        if('subquery' == $this->request['FROM'][0]['expr_type']) {
            $select = $this->request['FROM'][0]['sub_tree']['FROM'][0]['sub_tree']['SELECT'];
        }

        $resp = [];
        foreach($select as $field) {
            if('rec_id' === $field['no_quotes']['parts'][1]) {
                $resp[$field['alias']['no_quotes']['parts'][0]] = $rec['recordId'];
                continue;
            }
            if('mod_id' === $field['no_quotes']['parts'][1]) {
                $resp[$field['alias']['no_quotes']['parts'][0]] = $rec['modId'];
                continue;
            }
            if('rec_meta' === $field['no_quotes']['parts'][1]) {
                $resp[$field['alias']['no_quotes']['parts'][0]] = $this->getMetadataArray();
                continue;
            }

            $data = $rec['fieldData'][$field['no_quotes']['parts'][1]];
            $resp[$field['alias']['no_quotes']['parts'][0]] = $data == "" ? null : $data;
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
        if('rec_id' == $idColumn) {
            return $this->records['recordId'];
        }

        $uri = $this->qb->getUri() .'/' . $this->records['recordId'];
        try {
            $record = $this->conn->performFMRequest('GET', $uri, $this->qb->getOptions());
            return $record[0]['fieldData'][$idColumn];
        } catch(Exception $e) {
            throw new FMException('Unable to locate record primary key with error '. $e->getMessage());
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
