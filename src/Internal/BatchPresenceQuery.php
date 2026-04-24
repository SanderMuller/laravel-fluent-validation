<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Runs the batched whereIn query for exists/unique presence verification,
 * replaying Laravel's scalar where conditions (NULL / NOT_NULL / !value /
 * literal) and the unique-rule ignore clause.
 *
 * @internal
 */
final class BatchPresenceQuery
{
    private const CHUNK_SIZE = 1000;

    /**
     * @param  array<int, mixed>  $values
     * @param  array<int, array{column: string, value: string}>  $wheres
     * @return array<int, mixed>
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
        /** @var list<array<int, mixed>> $chunks */
        $chunks = [];

        foreach (array_chunk($values, self::CHUNK_SIZE) as $chunk) {
            $query = ($connection !== null
                ? DB::connection($connection)->table($table)
                : DB::table($table)
            )->useWritePdo();

            $query->whereIn($column, $chunk);

            // Replay scalar where conditions (matches DatabasePresenceVerifier::addWhere()).
            foreach ($wheres as $where) {
                self::applyWhere($query, $where);
            }

            if ($ignore !== null && $ignore !== 'NULL') {
                $query->where($idColumn, '<>', $ignore);
            }

            $chunks[] = array_values($query->pluck($column)->all());
        }

        return array_merge(...$chunks);
    }

    /**
     * Apply a single Laravel-style where condition to the query.
     *
     * @param  array{column: string, value: string}  $where
     */
    private static function applyWhere(Builder $query, array $where): void
    {
        $extraValue = $where['value'];

        if ($extraValue === 'NULL') {
            $query->whereNull($where['column']);

            return;
        }

        if ($extraValue === 'NOT_NULL') {
            $query->whereNotNull($where['column']);

            return;
        }

        if (str_starts_with($extraValue, '!')) {
            $query->where($where['column'], '!=', mb_substr($extraValue, 1));

            return;
        }

        $query->where($where['column'], $extraValue);
    }
}
