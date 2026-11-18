<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Driver;

use Doctrine\DBAL\Driver\Result;

/**
 * An in-memory result set built from the records returned by the Data API.
 */
class FMResult implements Result
{
    private int $position = 0;

    /**
     * @param list<array<string, mixed>> $rows        Associative rows keyed by column alias
     * @param int|null                   $rowCount    Rows affected, if that differs from the number of rows
     * @param int|null                   $columnCount Number of columns, if there are no rows to infer it from
     */
    public function __construct(
        private array $rows = [],
        private readonly ?int $rowCount = null,
        private readonly ?int $columnCount = null,
    ) {
    }

    /**
     * Turn Data API records into rows.
     *
     * @param list<array>                                $records
     * @param list<array{alias: string, source: string}> $columns  Each column's alias, and the field (or pseudo field) it is read from
     * @param array                                      $metadata The `dataInfo` from the response
     */
    public static function fromRecords(array $records, array $columns, array $metadata = []): self
    {
        $rows = [];
        foreach ($records as $record) {
            $row = [];
            foreach ($columns as ['alias' => $alias, 'source' => $source]) {
                $row[$alias] = match ($source) {
                    'rec_id' => $record['recordId'] ?? null,
                    'mod_id' => $record['modId'] ?? null,
                    'rec_meta' => json_encode([
                        'found' => $metadata['foundCount'] ?? 0,
                        'fetch' => $metadata['returnedCount'] ?? 0,
                        'total' => $metadata['totalRecordCount'] ?? 0,
                    ]),
                    default => self::emptyToNull($record['fieldData'][$source] ?? null),
                };
            }
            $rows[] = $row;
        }

        return new self($rows, null, count($columns));
    }

    public function fetchNumeric(): array|false
    {
        $row = $this->fetchAssociative();

        return false === $row ? false : array_values($row);
    }

    public function fetchAssociative(): array|false
    {
        return $this->rows[$this->position++] ?? false;
    }

    public function fetchOne(): mixed
    {
        $row = $this->fetchNumeric();

        return false === $row ? false : ($row[0] ?? null);
    }

    public function fetchAllNumeric(): array
    {
        return array_map('array_values', $this->fetchAllAssociative());
    }

    public function fetchAllAssociative(): array
    {
        $remaining = array_slice($this->rows, $this->position);
        $this->position = count($this->rows);

        return $remaining;
    }

    public function fetchFirstColumn(): array
    {
        return array_map(static fn (array $row) => $row ? reset($row) : null, $this->fetchAllAssociative());
    }

    public function rowCount(): int
    {
        return $this->rowCount ?? count($this->rows);
    }

    public function columnCount(): int
    {
        return $this->columnCount ?? count($this->rows[0] ?? []);
    }

    public function free(): void
    {
        $this->rows = [];
        $this->position = 0;
    }

    private static function emptyToNull(mixed $value): mixed
    {
        return '' === $value ? null : $value;
    }
}
