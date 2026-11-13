<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Runs the batched whereIn query for exists/unique presence verification,
 * replaying the rule's where conditions (normalized to explicit operators by
 * DatabaseRuleWheres) and the unique-rule ignore clause.
 *
 * The database decides which input values match, with one
 * `MAX(CASE WHEN column = ? ...)` column per value. A collation or a type
 * coercion can match a stored value that differs from the input ('ALICE'
 * and 'alice', '05' and 5), so comparing the stored values with the input
 * in PHP gives wrong results.
 *
 * @phpstan-import-type NormalizedWhere from DatabaseRuleWheres
 *
 * @internal
 */
final class BatchPresenceQuery
{
    /** Each value is bound twice; SQL Server allows 2100 parameters per query. */
    private const CHUNK_SIZE = 500;

    /**
     * @param  array<int, mixed>  $values
     * @param  list<NormalizedWhere>  $wheres
     * @return list<mixed>  The input values that match a row
     */
    public static function run(
        ?string $connection,
        string $table,
        string $column,
        array $values,
        array $wheres,
        mixed $ignore = null,
        string $idColumn = 'id',
    ): array {
        $matched = [];

        foreach (array_chunk($values, self::CHUNK_SIZE) as $chunk) {
            $query = ($connection !== null
                ? DB::connection($connection)->table($table)
                : DB::table($table)
            )->useWritePdo();

            $wrappedColumn = $query->getGrammar()->wrap($column);
            $selects = [];

            foreach (array_keys($chunk) as $index) {
                $selects[] = "MAX(CASE WHEN {$wrappedColumn} = ? THEN 1 ELSE 0 END) AS m{$index}";
            }

            // The column is quoted by the grammar and every value is bound.
            $query->selectRaw(implode(', ', $selects), $chunk); // @phpstan-ignore argument.type
            $query->whereIn($column, $chunk);

            // Replay where conditions (matches DatabasePresenceVerifier::addWhere()).
            foreach ($wheres as $where) {
                self::applyWhere($query, $where);
            }

            if ($ignore !== null && $ignore !== 'NULL') {
                $query->where($idColumn, '<>', $ignore);
            }

            $row = (array) $query->first();

            foreach ($chunk as $index => $value) {
                $flag = $row['m' . $index] ?? 0;

                // Drivers return the flag as an int or as a string.
                if ($flag === 1 || $flag === '1') {
                    $matched[] = $value;
                }
            }
        }

        return $matched;
    }

    /** @param  NormalizedWhere  $where */
    private static function applyWhere(Builder $query, array $where): void
    {
        match ($where['operator']) {
            'NULL' => $query->whereNull($where['column']),
            'NOT_NULL' => $query->whereNotNull($where['column']),
            '!=' => $query->where($where['column'], '!=', $where['value']),
            '=' => $query->where($where['column'], $where['value']),
        };
    }
}
