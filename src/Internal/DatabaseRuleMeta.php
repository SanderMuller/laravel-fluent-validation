<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;
use ReflectionException;
use ReflectionProperty;

/**
 * @phpstan-import-type NormalizedWhere from DatabaseRuleWheres
 *
 * @phpstan-type RuleMeta array{connection: string|null, table: string, column: string, wheres: list<NormalizedWhere>, conditions: array<string, string>, ignore: string|null, idColumn: string}
 *
 * @internal
 */
final class DatabaseRuleMeta
{
    /**
     * Read a database rule's table, column, where clauses and ignored id via
     * reflection. Returns null when the where clauses or ignored id cannot be
     * replayed exactly.
     *
     * @return RuleMeta|null
     */
    public static function extract(Exists|Unique $rule): ?array
    {
        try {
            $table = self::readProperty($rule, 'table');
            $column = self::readProperty($rule, 'column');
            $wheres = self::readProperty($rule, 'wheres');

            if (! is_string($table) || ! is_string($column) || ! is_array($wheres)) {
                return null;
            }

            // Parse connection.table format (matching Laravel's parseTable())
            $connection = null;

            if (str_contains($table, '.')) {
                $parts = explode('.', $table, 2);
                $connection = $parts[0];
                $table = $parts[1];
            }

            $ignore = null;
            $idColumn = 'id';

            if ($rule instanceof Unique) {
                $rawIgnore = self::readProperty($rule, 'ignore');

                if ($rawIgnore !== null && ! is_scalar($rawIgnore)) {
                    return null;
                }

                // Unique::__toString() writes a falsy ignore as NULL, so
                // Laravel excludes no row for it.
                $ignore = is_scalar($rawIgnore) && (bool) $rawIgnore ? (string) $rawIgnore : null;

                $rawIdColumn = self::readProperty($rule, 'idColumn');
                $idColumn = is_string($rawIdColumn) ? $rawIdColumn : 'id';
            }

            $typedWheres = DatabaseRuleWheres::normalize($wheres);
            $conditions = $typedWheres === null ? null : DatabaseRuleWheres::presenceConditions($typedWheres);

            if ($typedWheres === null || $conditions === null) {
                return null;
            }

            return [
                'connection' => $connection,
                'table' => $table,
                'column' => $column === 'NULL' ? '' : $column,
                'wheres' => $typedWheres,
                'conditions' => $conditions,
                'ignore' => $ignore,
                'idColumn' => $idColumn,
            ];
        } catch (ReflectionException) {
            return null;
        }
    }

    private static function readProperty(object $object, string $property): mixed
    {
        return (new ReflectionProperty($object, $property))->getValue($object);
    }
}
