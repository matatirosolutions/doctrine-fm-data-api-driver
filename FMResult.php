<?php
declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver;

use Doctrine\DBAL\Driver\Result;

class FMResult implements Result
{
    private int $rowCount = 0;

    public function __construct(
        private readonly array $request,
        private readonly array $results,
        private readonly array $metadata,
    ) {
    }

    public function fetchNumeric()
    {
        // TODO: Implement fetchNumeric() method.
        dd(__METHOD__);
    }

    public function fetchAssociative(): array|false
    {
        if(array_key_exists($this->rowCount, $this->results)) {
            $row = $this->results[$this->rowCount];
            $this->rowCount++;
            return $this->recordToArray($row);
        }

        return false;
    }

    public function fetchOne()
    {
        // TODO: Implement fetchOne() method.
        dd(__METHOD__);
    }

    public function fetchAllNumeric(): array
    {
        // TODO: Implement fetchAllNumeric() method.
        dd(__METHOD__);
    }

    public function fetchAllAssociative(): array
    {
        // TODO: Implement fetchAllAssociative() method.
        dd(__METHOD__);
    }

    public function fetchFirstColumn(): array
    {
        // TODO: Implement fetchFirstColumn() method.
        dd(__METHOD__);
    }

    public function rowCount(): int
    {
        return count($this->results);
    }

    public function columnCount(): int
    {
        // TODO: Implement columnCount() method.
        dd(__METHOD__);
    }

    public function free(): void
    {
        // TODO: Implement free() method.
        //dd(__METHOD__);
        //return;
    }

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
                $resp[$field['alias']['no_quotes']['parts'][0]] = json_encode($this->metadata);
                continue;
            }

            $data = $rec['fieldData'][$field['no_quotes']['parts'][1]];
            $resp[$field['alias']['no_quotes']['parts'][0]] = $data === '' ? null : $data;
        }

        return $resp;
    }

}
