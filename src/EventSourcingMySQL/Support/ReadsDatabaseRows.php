<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingMySQL\Support;

/**
 * One place where a PDO result stops being untyped.
 *
 * `fetchAll()` is declared as returning a plain `array`, and every value in a
 * row is `mixed` however it was declared in the schema — a driver may hand back
 * an int, a string or null for the same column depending on emulation settings
 * and native types. Level 10 says so out loud instead of letting each call site
 * assume.
 *
 * These are conversions rather than assertions on purpose. A row that came out
 * of `PDO::FETCH_ASSOC` is already string-keyed, so a guard that threw would be
 * a branch nothing could reach — the same reasoning that kept the `query()`
 * false-check out of the outbox reader.
 */
trait ReadsDatabaseRows
{
    /**
     * A result set as rows this adapter can talk about.
     *
     * @param mixed $rows
     * @return list<array<string, mixed>>
     */
    private function toRows(
        mixed $rows
    ): array {
        $result = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $result[] = $this->toRow($row);
        }

        return $result;
    }

    /**
     * @param mixed $row
     * @return array<string, mixed>
     */
    private function toRow(
        mixed $row
    ): array {
        $fields = [];

        foreach (is_array($row) ? $row : [] as $column => $value) {
            $fields[(string) $column] = $value;
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $row
     * @param string $column
     * @param string $default
     * @return string
     */
    private function stringColumn(
        array $row,
        string $column,
        string $default = ''
    ): string {
        $value = $row[$column] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * @param array<string, mixed> $row
     * @param string $column
     * @param int $default
     * @return int
     */
    private function intColumn(
        array $row,
        string $column,
        int $default = 0
    ): int {
        $value = $row[$column] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }
}
