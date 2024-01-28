<?php

namespace MSDev\DoctrineFMDataAPIDriver\Utility;

use Doctrine\ORM\Mapping\ClassMetadata;
use JsonException;
use MSDev\DoctrineFMDataAPIDriver\FMConnection;
use MSDev\DoctrineFMDataAPIDriver\Exception\FMException;
use MSDev\DoctrineFMDataAPIDriver\Exception\NotImplementedException;
use RuntimeException;
use Throwable;

class QueryBuilder
{
    private FMConnection $connection;

    private string $operation;

    private array $query;

    private string $method;

    private string $uri;

    private array $options = [];


    public function __construct(FMConnection $connection)
    {
        $this->connection = $connection;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @throws FMException | NotImplementedException | JsonException
     */
    public function getQueryFromRequest(array $tokens, string $statement, array $params): void
    {
        $this->operation = strtolower(array_keys($tokens)[0]);

        switch($this->operation) {
            case 'select':
                $this->generateFindCommand($tokens, $params);
                return;
            case 'update':
                $this->generateUpdateCommand($tokens, $statement, $params);
                return;
            case 'insert':
                $this->generateInsertCommand($tokens, $params);
                return;
            case 'delete':
                $this->generateDeleteCommand($tokens, $params);
                return;
        }

        throw new NotImplementedException('Unknown request type');
    }


    /**
     * @throws FMException | JsonException
     */
    private function generateFindCommand($tokens, $params): void
    {
        $layout = $this->getLayout($tokens);
        if (empty($this->query['WHERE'])) {
            $this->method = 'GET';
            $this->uri = sprintf('layouts/%s/records', $layout);

            $prefix = '?';
            if(array_key_exists('ORDER', $this->query)) {
                $this->uri .= '?_sort='. json_encode($this->getSort(), JSON_THROW_ON_ERROR);
                $prefix= '&';
            }

            // If we don't set a (crazy) high default limit then findAll() doesn't really work
            // as the FM Data API only returns 100 records by default
            $offset = 1;
            $limit = 10000;
            if(isset($tokens['FROM'][0]['expr_type']) && 'subquery' === $tokens['FROM'][0]['expr_type']) {
                $offset = $this->getSkip($tokens);
                $limit = $this->getMax($tokens);
            }
            $this->uri .= sprintf( '%s_offset=%s&_limit=%s', $prefix, $offset, $limit);

            return;
        }

        $this->method = 'POST';
        $this->uri = sprintf('layouts/%s/_find', $layout);
        $body = [
            'query' => $this->generateWhere($params)
        ];

        // Sort
        if(array_key_exists('ORDER', $this->query)) {
            $body['sort'] = $this->getSort();
        }

        // Limit
        if('subquery' === $tokens['FROM'][0]['expr_type']) {
            $body['offset'] = $this->getSkip($tokens);
            $body['limit'] = $this->getMax($tokens);
        }

        $this->options = [
            'body' => json_encode($body, JSON_THROW_ON_ERROR)
        ];
    }


    /**
     * @throws FMException | JsonException
     */
    private function generateUpdateCommand(array $tokens, string $statement, array $params): void
    {
        $this->method = 'PATCH';
        $layout = $this->getLayout($tokens);
        $recID = $this->getRecordID($tokens, $layout, $params);
        $this->uri = sprintf('layouts/%s/records/%s', $layout, $recID);

        $data = $matches = [];
        $count = 1;

        preg_match('/ SET (.*) WHERE /', $statement, $matches);
        $pairs = explode(',', $matches[1]);
        foreach($pairs as $up) {
            $details = explode('=', $up);
            $field = trim(str_replace("'", '', array_shift($details)));
            if($field) {
                $data[$field] = is_null($params[$count]) ? "" : $params[$count];
                $count++;
            }
        }

        $this->options = [
            'body' => json_encode([
                'fieldData' => $data,
            ], JSON_THROW_ON_ERROR)
        ];
    }


    /**
     * @throws FMException | JsonException
     */
    private function generateDeleteCommand(array $tokens, array $params): void
    {
        $this->method = 'DELETE';
        $layout = $this->getLayout($tokens);
        $recID = $this->getRecordID($tokens, $layout, $params);

        $this->uri = sprintf('layouts/%s/records/%s', $layout, $recID);
    }


    /**
     * @throws FMException | JsonException
     */
    private function generateInsertCommand(array $tokens, array $params): void
    {
        $layout = $this->getLayout($tokens);
        $list = substr($tokens['INSERT'][2]['base_expr'], 1, -1);
        $fields = explode(',', $list);

        // need to know which is the ID column
        $idColumn = $this->getIdColumn($tokens, new MetaData());
        $this->method = 'POST';
        $this->uri = sprintf('layouts/%s/records', $layout);

        $data = [];
        foreach($fields as $c => $f) {
            $field = trim($f);
            if('rec_id' === $field || 'mod_id' === $field || 'rec_meta' === $field || ($idColumn === $field && empty($params[$c+1]))) {
                continue;
            }
            $data[$field] = is_null($params[$c+1]) ? "" : $params[$c+1];
        }

        $this->options = [
            'body' => json_encode([
                'fieldData' => $data,
            ], JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT)
        ];
    }

    /**
     * @throws FMException  | JsonException
     */
    private function getRecordID(array $tokens, string $layout, array $params): int
    {
        $uri = sprintf('layouts/%s/_find', $layout);
        $uuid = $params[count($params)];
        $options = [
            'body' => json_encode([
                'query' => [
                    [str_replace("'", '', $tokens['WHERE'][0]['base_expr']) => $uuid]
                ]
            ], JSON_THROW_ON_ERROR)
        ];

        try {
            $connection = $this->connection->getNativeConnection();
            if(null === $connection) {
                throw new RuntimeException('No connection to FileMaker');
            }

            $record = $connection->performFMRequest('POST', $uri, $options);
            return $record[0]['recordId'];
        } catch (Throwable $e) {
            throw new FMException($e->getMessage(), $e->getCode());
        }
    }

    /**
     * @throws FMException
     */
    public function getLayout(array $tokens): string
    {
        $this->query = $tokens;
        if (empty($tokens['FROM']) && empty($tokens['INSERT']) && empty($tokens['UPDATE'])) {
            throw new FMException('Unknown layout');
        }

        switch($this->operation) {
            case 'insert':
                return $tokens['INSERT'][1]['no_quotes']['parts'][0];
            case 'update':
                return $tokens['UPDATE'][0]['no_quotes']['parts'][0];
            default:
                if('subquery' === $tokens['FROM'][0]['expr_type']) {
                    $this->query = $tokens['FROM'][0]['sub_tree']['FROM'][0]['sub_tree'];
                    return $tokens['FROM'][0]['sub_tree']['FROM'][0]['sub_tree']['FROM'][0]['no_quotes']['parts'][0];
                }
                return $tokens['FROM'][0]['no_quotes']['parts'][0];
        }
    }

    private function generateWhere(array $params): array
    {
        $request = [];
        $requests = [];
        $cols = $this->selectColumns($this->query);
        $pc = 1;

        foreach ($this->query['WHERE'] as $c => $cValue) {
            $query = $cValue;

            if(array_key_exists($query['base_expr'], $cols)) {
                $op = $this->getOperator($this->query['WHERE'][$c+1]['base_expr'], $params[$pc]);
                if('!=' === $op) {
                    // if this isn't the first loop, add the current request to the requests array and reset it
                    if(!empty($request)) {
                        $requests[] = $request;
                        $request = [];
                    }
                    $requests[] = [
                        $query['no_quotes']['parts'][1] => ($params[$pc] === false ? 0 : $params[$pc]),
                        'omit' => "true",
                    ];

                } elseif('IN' === $op) {
                    $baseRequest = $request;
                    $inCount = substr_count($this->query['WHERE'][$c+2]['base_expr'], '?');
                    for($i = 0; $i < $inCount; $i++) {
                        $request = $baseRequest;
                        $request[$query['no_quotes']['parts'][1]] = '==' . ($params[$pc] === false ? 0 : $params[$pc]);
                        $requests[] = $request;
                        $pc++;
                    }
                    $request = null;
                } else {
                    $request[$query['no_quotes']['parts'][1]] = $op . ($params[$pc] === false ? 0 : $params[$pc]);
                }
                $pc++;
            } elseif ('bracket_expression' === $query['expr_type']) {
                $baseRequest = $request;
                foreach($query['sub_tree'] as $subCount => $subExpression) {
                    if('colref' === $subExpression['expr_type']
                        && array_key_exists('no_quotes', $subExpression)
                        && isset($subExpression['no_quotes']['parts'])
                        && isset($subExpression['no_quotes']['parts'][1])
                        && array_key_exists($subExpression['base_expr'], $cols)
                    ) {
                        $op = $this->getOperator($query['sub_tree'][$subCount+1]['base_expr'], $params[$pc]);
                        $request = $baseRequest;
                        $request[$subExpression['no_quotes']['parts'][1]] = $op . ($params[$pc] === false ? 0 : $params[$pc]);
                        $requests[] = $request;

                        $request = [];
                        $pc++;
                    }
                }
            }
        }

        if(!empty($request)) {
            $requests[] = $request;
        }

        return $requests;
    }


    private function selectColumns($tokens): array
    {
        $cols = [];
        foreach($tokens['SELECT'] as $column) {
            if(isset($column['no_quotes'])) {
                $cols[$column['base_expr']] = $column['no_quotes']['parts'][1];
                continue;
            }

            if(isset($column['sub_tree'])) {
                $field = [];
                foreach($column['sub_tree'] as $sub) {
                    $field[] = end($sub['no_quotes']['parts']);
                }
                $cols[$column['base_expr']] = implode(' ', $field);
            }
        }

        return $cols;
    }

    /**
     * returns the column of the id
     *
     * @param array $tokens
     * @param MetaData $metaData
     * @return string
     *
     * @throws FMException
     */
    public function getIdColumn(array $tokens, MetaData $metaData): string
    {
        $table = $this->getLayout($tokens);
        $meta = array_filter($metaData->get(), static function ($meta) use ($table) {
            /** @var ClassMetadata $meta */
            return $meta->getTableName() === $table;
        });

        $idColumns = !empty($meta) ? end($meta)->getIdentifierColumnNames() : [];

        return !empty($idColumns) ? end($idColumns) : 'id';
    }

    /**
     * Work out the skip value based on the query tokens
     *
     * @param array $tokens
     * @return int
     */
    private function getSkip(array $tokens): int
    {
        if(isset($tokens['WHERE'][1]['base_expr']) && '>=' === $tokens['WHERE'][1]['base_expr']) {
            return (int)$tokens['WHERE'][2]['base_expr'];
        }

        return 1;
    }

    /**
     * Work out the max records value based on the query tokens
     *
     * @param array $tokens
     * @return int
     */
    private function getMax(array $tokens): int
    {
        $skip = $this->getSkip($tokens);
        if(isset($tokens['WHERE'][6]['base_expr'])) {
            return (int)$tokens['WHERE'][6]['base_expr'] - $skip + 1;
        }

        if(isset($tokens['WHERE'][1]['base_expr']) && '<=' === $tokens['WHERE'][1]['base_expr']) {
            return (int)$tokens['WHERE'][2]['base_expr'];
        }

        return 10;
    }

    /**
     * Generate an array of sort requests
==
     */
    private function getSort(): array
    {
        $sort = [];
        foreach($this->query['ORDER'] as $rule) {
            $sort[] = [
                'fieldName' => $rule['no_quotes']['parts'][1],
                'sortOrder' => 'ASC' === $rule['direction'] ? 'ascend' : 'descend'
            ];
        }
        return $sort;
    }

    private function getOperator(string $request, string $parameter): string
    {
        switch($request) {
            case '=':
                $param = $parameter[0];
                if(in_array($param, ['=', '<', '>'])) {
                    return '';
                }
                return '==';
            case '>':
            case '<':
            case '>=':
            case '<=':
            case '=<':
            case '=>':
            case 'IN':
                return $request;
            case '<>':
                return '!=';
            // Explicitly adding here for clarity
            case 'LIKE':
            case '!=':
            // Anything else gets the standard FM find method
            default:
                return '';
        }
    }

}
