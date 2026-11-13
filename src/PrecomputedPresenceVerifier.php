<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Illuminate\Validation\DatabasePresenceVerifierInterface;
use Illuminate\Validation\PresenceVerifierInterface;
use Stringable;

/**
 * A presence verifier that returns pre-computed results from batch queries.
 *
 * Used by BatchDatabaseChecker to replace per-item DB queries with lookup-set
 * checks. The original Exists/Unique rule objects stay in place, so Laravel's
 * full message resolution pipeline (custom messages, :attribute replacement)
 * works unchanged.
 *
 * Lookups registered with addQueryLookup() answer only a call that asks for
 * exactly the batched query: the same connection, table, column, ignored id
 * and where conditions, for a value the batch query included. Any other call,
 * for example from a rule with a closure or a different where clause, goes
 * to the fallback verifier.
 *
 * Lookups registered with the deprecated addLookup() are scoped by
 * table+column only.
 */
final class PrecomputedPresenceVerifier implements DatabasePresenceVerifierInterface
{
    /** @var array<string, array<string, true>>  Keyed by "table:column", values are string-cast flip maps */
    private array $lookups = [];

    /** @var array<string, array{queried: array<string, true>, found: array<string, true>}>  Keyed by queryKey() */
    private array $queryLookups = [];

    private ?string $connection = null;

    public function __construct(private readonly ?PresenceVerifierInterface $fallback = null) {}

    /**
     * Register pre-computed values for a table+column pair.
     * Values are cast to strings for loose comparison matching database behavior
     * (databases do implicit type coercion on WHERE column = value).
     *
     * @param  array<int, mixed>  $values  Values that exist in the database
     *
     * @deprecated The lookup answers every call for the table and column, also
     *             calls with other where conditions, connections or ignored ids.
     *             The package no longer uses it. It will be removed in the next
     *             major release.
     */
    public function addLookup(string $table, string $column, array $values): void
    {
        $this->lookups[$table . ':' . $column] = $this->flip($values);
    }

    /**
     * Register, or merge into, the result of a batched presence query.
     *
     * @internal
     *
     * @param  array<string, string>  $conditions  The `$extra` conditions Laravel passes for the rule
     * @param  array<int, mixed>  $queried  Values the batch query included
     * @param  array<int, mixed>  $found  Values the batch query matched
     */
    public function addQueryLookup(
        ?string $connection,
        string $table,
        string $column,
        array $conditions,
        ?string $excludeId,
        ?string $idColumn,
        array $queried,
        array $found,
    ): void {
        $key = $this->queryKey($connection, $table, $column, $excludeId, $idColumn, $conditions);

        if ($key === null) {
            return;
        }

        $lookup = $this->queryLookups[$key] ?? ['queried' => [], 'found' => []];
        $lookup['queried'] += $this->flip($queried);
        $lookup['found'] += $this->flip($found);

        $this->queryLookups[$key] = $lookup;
    }

    /** @param  array<mixed>  $extra */
    public function getCount(mixed $collection, mixed $column, mixed $value, mixed $excludeId = null, mixed $idColumn = null, array $extra = []): int
    {
        $count = $this->countFromQueryLookup($collection, $column, $value, $excludeId, $idColumn, $extra);

        if ($count !== null) {
            return $count;
        }

        $key = $collection . ':' . $column;

        if (! isset($this->lookups[$key])) {
            if ($this->fallback instanceof PresenceVerifierInterface) {
                return $this->fallback->getCount($collection, $column, $value, $excludeId, $idColumn, $extra);
            }

            return 0;
        }

        return isset($this->lookups[$key][is_scalar($value) ? (string) $value : '']) ? 1 : 0;
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @param  array<mixed>  $extra
     */
    public function getMultiCount(mixed $collection, mixed $column, array $values, array $extra = []): int
    {
        // Laravel counts distinct stored values here, and two input values
        // can match one stored value ('5' and '05'). A batched lookup knows
        // only which inputs matched, so it never answers this call.
        $key = $collection . ':' . $column;

        if (! isset($this->lookups[$key])) {
            if ($this->fallback instanceof PresenceVerifierInterface) {
                return $this->fallback->getMultiCount($collection, $column, $values, $extra);
            }

            return 0;
        }

        $count = 0;
        $lookup = $this->lookups[$key];

        foreach ($values as $val) {
            if (is_scalar($val) && isset($lookup[(string) $val])) {
                ++$count;
            }
        }

        return $count;
    }

    public function setConnection(mixed $connection): void
    {
        $this->connection = is_string($connection) ? $connection : null;

        if ($this->fallback instanceof DatabasePresenceVerifierInterface) {
            $this->fallback->setConnection($connection);
        }
    }

    public function hasLookups(): bool
    {
        return $this->lookups !== [] || $this->queryLookups !== [];
    }

    /**
     * Return 1 or 0 from a matching batched query, or null when no lookup
     * matches the call or the batch did not query the value.
     *
     * @param  array<mixed>  $extra
     */
    private function countFromQueryLookup(mixed $collection, mixed $column, mixed $value, mixed $excludeId, mixed $idColumn, array $extra): ?int
    {
        if ($this->queryLookups === []) {
            return null;
        }

        $key = $this->queryKey($this->connection, $collection, $column, $excludeId, $idColumn, $extra);
        $lookup = $key === null ? null : ($this->queryLookups[$key] ?? null);

        if ($lookup === null) {
            return null;
        }

        $valueKey = $this->valueKey($value);

        if ($valueKey === null || ! isset($lookup['queried'][$valueKey])) {
            return null;
        }

        return isset($lookup['found'][$valueKey]) ? 1 : 0;
    }

    /**
     * Returns null when the call cannot be keyed, for example when `$extra`
     * holds a closure.
     *
     * @param  array<mixed>  $extra
     */
    private function queryKey(?string $connection, mixed $table, mixed $column, mixed $excludeId, mixed $idColumn, array $extra): ?string
    {
        if (! is_string($table) || ! is_string($column)) {
            return null;
        }

        // DatabasePresenceVerifier ignores the id column unless an id is excluded.
        if ($excludeId === null || $excludeId === 'NULL') {
            $excludeId = null;
            $idColumn = null;
        } elseif (is_scalar($excludeId)) {
            $excludeId = (string) $excludeId;
            $idColumn = is_string($idColumn) && $idColumn !== '' ? $idColumn : 'id';
        } else {
            return null;
        }

        $conditions = [];

        foreach ($extra as $conditionColumn => $conditionValue) {
            if (! is_string($conditionColumn) || ! is_scalar($conditionValue)) {
                return null;
            }

            $conditions[$conditionColumn] = (string) $conditionValue;
        }

        ksort($conditions, SORT_STRING);

        // serialize() cannot fail on strings; json_encode() fails on invalid UTF-8.
        return serialize([$connection, $table, $column, $excludeId, $idColumn, $conditions]);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<string, true>
     */
    private function flip(array $values): array
    {
        $map = [];

        foreach ($values as $value) {
            $valueKey = $this->valueKey($value);

            if ($valueKey !== null) {
                $map[$valueKey] = true;
            }
        }

        return $map;
    }

    private function valueKey(mixed $value): ?string
    {
        return is_scalar($value) || $value instanceof Stringable ? (string) $value : null;
    }
}
