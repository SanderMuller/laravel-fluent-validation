<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

/**
 * @phpstan-type NormalizedWhere array{column: string, operator: '='|'!='|'NULL'|'NOT_NULL', value: string|null}
 *
 * @internal
 */
final class DatabaseRuleWheres
{
    /**
     * Normalize a database rule's where clauses to explicit operators.
     *
     * Laravel releases before laravel/framework#62425 store `['column', 'value']`
     * and encode the operator in the value (`NULL`, `NOT_NULL`, `!value`).
     * Later releases store `['column', 'operator', 'value']` and compare the
     * value literally. Returns null when a clause cannot be replayed.
     *
     * @param  array<mixed>  $wheres
     * @return list<NormalizedWhere>|null
     */
    public static function normalize(array $wheres): ?array
    {
        $normalized = [];

        foreach ($wheres as $where) {
            if (! is_array($where) || ! isset($where['column']) || ! is_string($where['column'])
                || ! array_key_exists('value', $where)) {
                return null;
            }

            $column = $where['column'];
            $value = $where['value'];

            if (array_key_exists('operator', $where)) {
                $operator = $where['operator'];

                if ($operator === 'NULL' || $operator === 'NOT_NULL') {
                    $normalized[] = ['column' => $column, 'operator' => $operator, 'value' => null];

                    continue;
                }

                if (($operator !== '=' && $operator !== '!=') || ! is_scalar($value)) {
                    return null;
                }

                $normalized[] = ['column' => $column, 'operator' => $operator, 'value' => (string) $value];

                continue;
            }

            if (! is_scalar($value)) {
                return null;
            }

            $value = (string) $value;

            $normalized[] = match (true) {
                $value === 'NULL' => ['column' => $column, 'operator' => 'NULL', 'value' => null],
                $value === 'NOT_NULL' => ['column' => $column, 'operator' => 'NOT_NULL', 'value' => null],
                str_starts_with($value, '!') => ['column' => $column, 'operator' => '!=', 'value' => mb_substr($value, 1)],
                default => ['column' => $column, 'operator' => '=', 'value' => $value],
            };
        }

        return $normalized;
    }

    /**
     * Build the `$extra` conditions Laravel passes to the presence verifier
     * for these where clauses: column => `value`, `!value`, `NULL` or `NOT_NULL`.
     *
     * Returns null when Laravel passes something that cannot be compared:
     * a repeated column (older releases keep only the last clause, newer ones
     * add a closure for the repeat) or a literal `=` value that looks like an
     * operator (newer releases add a closure for it).
     *
     * @param  list<NormalizedWhere>  $wheres
     * @return array<string, string>|null
     */
    public static function presenceConditions(array $wheres): ?array
    {
        $conditions = [];

        foreach ($wheres as $where) {
            $column = $where['column'];
            $value = $where['value'] ?? '';

            if (array_key_exists($column, $conditions)) {
                return null;
            }

            if ($where['operator'] === '='
                && ($value === 'NULL' || $value === 'NOT_NULL' || str_starts_with($value, '!'))) {
                return null;
            }

            $conditions[$column] = match ($where['operator']) {
                '=' => $value,
                '!=' => '!' . $value,
                'NULL' => 'NULL',
                'NOT_NULL' => 'NOT_NULL',
            };
        }

        return $conditions;
    }
}
