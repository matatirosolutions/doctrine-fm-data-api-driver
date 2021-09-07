<?php
/**
 * Created by PhpStorm.
 * User: SteveWinter
 * Date: 03/04/2017
 * Time: 20:31
 */

namespace MSDev\DoctrineFMDataAPIDriver\Utility;

use Doctrine\ORM\Mapping\ClassMetadata;
use MSDev\DoctrineFMDataAPIDriver\FMConnection;
use MSDev\DoctrineFMDataAPIDriver\Exception\FMException;
use MSDev\DoctrineFMDataAPIDriver\Exception\NotImplementedException;

class QueryBuilder
{
    /** @var FMConnection */
    private $connetion;

    /** @var string */
    private $operation;

    /** @var array */
    private $query;

    /** @var string */
    private $method;

    /** @var string */
    private $uri;

    /** @var array */
    private $options = [];


    public function __construct(FMConnection $connetion)
    {
        $this->connetion = $connetion;
    }


    public function getOperation()
    {
        return $this->operation;
    }

    /**
     * @return string
     */
    public function getMethod()
    {
        return $this->method;
    }

    /**
     * @return string
     */
    public function getUri()
    {
        return $this->uri;
    }

    /**
     * @return array
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    public function getQueryFromRequest(array $tokens, string $statement, array $params) {
        $this->operation = strtolower(array_keys($tokens)[0]);

        switch($this->operation) {
            case 'select':
                return $this->generateFindCommand($tokens, $params);
            case 'update':
                return $this->generateUpdateCommand($tokens, $statement, $params);
            case 'insert':
                return $this->generateInsertCommand($tokens, $params);
            case 'delete':
                return $this->generateDeleteCommand($tokens, $params);
        }

        throw new NotImplementedException('Unknown request type');
    }


    private function generateFindCommand($tokens, $params)
    {
        $layout = $this->getLayout($tokens);
        if (empty($this->query['WHERE'])) {
            $this->body = [];
            $this->method = 'GET';
            $this->uri = sprintf('layouts/%s/records', $layout);

            $prefix = '?';
            if(array_key_exists('ORDER', $this->query)) {
                $this->uri .= '?_sort='.json_encode($this->getSort());
                $prefix= '&';
            }

            // If we don't set a (crazy) high default limit then findAll() doesn't really work
            // as the FM Data API only returns 100 records by default
            $offset = 1;
            $limit = 10000;
            if(isset($tokens['FROM'][0]['expr_type']) && 'subquery' == $tokens['FROM'][0]['expr_type']) {
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
        if('subquery' == $tokens['FROM'][0]['expr_type']) {
            $body['offset'] = $this->getSkip($tokens);
            $body['limit'] = $this->getMax($tokens);
        }

        $this->options = [
            'body' => json_encode($body)
        ];
    }


    /**
     * @param $tokens
     * @param $statement
     * @param $params
     *
     * @throws FMException
     */
    private function generateUpdateCommand($tokens, $statement, $params)
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
            ])
        ];
    }


    private function generateDeleteCommand($tokens, $params)
    {
        $this->method = 'DELETE';
        $layout = $this->getLayout($tokens);
        $recID = $this->getRecordID($tokens, $layout, $params);

        $this->uri = sprintf('layouts/%s/records/%s', $layout, $recID);
    }


    private function generateInsertCommand($tokens, $params)
    {
        $layout = $this->getLayout($tokens);
        $list = substr($tokens['INSERT'][2]['base_expr'], 1, -1);
        $fields = explode(',', $list);

        // need to know which is the Id column
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
            ], JSON_FORCE_OBJECT)
        ];
    }

    /**
     * @param $tokens
     * @param $layout
     *
     * @param $params
     * @return integer
     * @throws FMException
     */
    private function getRecordID($tokens, $layout, $params): int
    {
        $uri = sprintf('layouts/%s/_find', $layout);
        $uuid = $params[count($params)];
        $options = [
            'body' => json_encode([
                'query' => [
                    [str_replace("'", '', $tokens['WHERE'][0]['base_expr']) => $uuid]
                ]
            ])
        ];

        try {
            $record = $this->connetion->performFMRequest('POST', $uri, $options);
            return $record[0]['recordId'];
        } catch (\Exception $e) {
            throw new FMException($e->getMessage(), $e->getCode());
        }
    }

    /**
     * @param array $tokens
     * @return mixed
     *
     * @throws FMException
     */
    public function getLayout(array $tokens) {
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
                if('subquery' == $tokens['FROM'][0]['expr_type']) {
                    $this->query = $tokens['FROM'][0]['sub_tree']['FROM'][0]['sub_tree'];
                    return $tokens['FROM'][0]['sub_tree']['FROM'][0]['sub_tree']['FROM'][0]['no_quotes']['parts'][0];
                }
                return $tokens['FROM'][0]['no_quotes']['parts'][0];
        }
    }

    /**
     * @param array $params
     * @return array
     */
    private function generateWhere($params)
    {
        $request = [];
        $cols = $this->selectColumns($this->query);
        $pc = 1;

        foreach ($this->query['WHERE'] as $c => $cValue) {
            $query = $cValue;

            if(array_key_exists($query['base_expr'], $cols)) {
                $op = $this->getOperator($this->query['WHERE'][$c+1]['base_expr'], $params[$pc]);
                $request[$query['no_quotes']['parts'][1]] = $op.($params[$pc] === false ? 0 : $params[$pc]);
                $pc++;
            }
        }

        return [$request];
    }


    private function selectColumns($tokens)
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
     * @param  array    $tokens
     * @param  MetaData $metaData
     * @return string
     *
     */
    public function getIdColumn(array $tokens, MetaData $metaData)
    {
        $table = $this->getLayout($tokens);
        $meta = array_filter($metaData->get(), function ($meta) use ($table) {
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
    private function getSkip($tokens)
    {
        if(isset($tokens['WHERE'][1]['base_expr']) && '>=' == $tokens['WHERE'][1]['base_expr']) {
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
    private function getMax($tokens)
    {
        $skip = $this->getSkip($tokens);
        if(isset($tokens['WHERE'][6]['base_expr'])) {
            return (int)$tokens['WHERE'][6]['base_expr'] - $skip + 1;
        }

        if(isset($tokens['WHERE'][1]['base_expr']) && '<=' == $tokens['WHERE'][1]['base_expr']) {
            return (int)$tokens['WHERE'][2]['base_expr'];
        }

        return 10;
    }

    /**
     * Generate an array of sort requests
     *
     * @return array
     */
    private function getSort()
    {
        $sort = [];
        foreach($this->query['ORDER'] as $k => $rule) {
            $sort[] = [
                'fieldName' => $rule['no_quotes']['parts'][1],
                'sortOrder' => 'ASC' == $rule['direction'] ? 'ascend' : 'descend'
            ];
        }
        return $sort;
    }

    private function getOperator($request, $parameter)
    {
        switch($request) {
            case '=':
                $param = substr($parameter, 0, 1);
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
                return $request;
            // ExpliAdding greatercitly here for clarity
            case 'LIKE':
            case '!=':
            // Anything else gets the standard FM find method
            default:
                return '';
        }
    }
}
