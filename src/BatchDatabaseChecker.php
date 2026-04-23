<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\PresenceVerifierInterface;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;
use ReflectionException;
use ReflectionProperty;
use SanderMuller\FluentValidation\Exceptions\BatchLimitExceededException;

/**
 * Batches exists/unique database validation queries for wildcard arrays.
 *
 * Instead of N queries (one per item), collects all values and runs a single
 * whereIn query. Returns a PrecomputedPresenceVerifier that can be set on
 * per-item validators, keeping original rule objects intact for correct
 * error message resolution.
 *
 * Only batchable for rules without closure callbacks. Rules with
 * queryCallbacks() fall through to per-item validation as before.
 */
final class BatchDatabaseChecker
{
    private const CHUNK_SIZE = 1000;

    /**
     * Per-group cap on distinct values allowed through a batched whereIn.
     * Defence in depth against forgotten parent max:N or hostile bulk input.
     *
     * Override once during boot (e.g. in AppServiceProvider::boot()) —
     * mutation at request time is NOT safe under Octane / Swoole.
     */
    public static int $maxValuesPerGroup = 10_000;

    /**
     * Check if the current environment supports batching
     * (default DatabasePresenceVerifier is registered).
     */
    public static function isAvailable(): bool
    {
        try {
            $verifier = resolve(DatabasePresenceVerifier::class);

            return $verifier instanceof DatabasePresenceVerifier;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check if a database rule can be batched.
     * Rules with closure callbacks are not batchable.
     */
    public static function isBatchable(Exists|Unique $rule): bool
    {
        if ($rule->queryCallbacks() !== []) {
            return false;
        }

        // Rules without an explicit column (column='NULL') rely on Laravel
        // inferring the column from the attribute name at validation time.
        // We can't replicate that inference, so skip batching.
        try {
            $column = self::readProperty($rule, 'column');

            return is_string($column) && $column !== 'NULL';
        } catch (ReflectionException) {
            return false;
        }
    }

    /**
     * Batch-query which values exist in the database for an Exists rule.
     *
     * @param  array<int, mixed>  $values  Deduplicated, non-null values to check
     * @return array<int, mixed>  Values that exist in the DB
     */
    public static function fetchExisting(array $values, Exists $rule): array
    {
        if ($values === []) {
            return [];
        }

        $meta = self::extractMeta($rule);

        if ($meta === null) {
            return $values; // Reflection failed — assume all exist (safe fallback)
        }

        return self::queryValues($meta['connection'], $meta['table'], $meta['column'], $values, $meta['wheres']);
    }

    /**
     * Batch-query which values are already taken for a Unique rule.
     *
     * @param  array<int, mixed>  $values  Deduplicated, non-null values to check
     * @return array<int, mixed>  Values that already exist (taken)
     */
    public static function fetchTaken(array $values, Unique $rule): array
    {
        if ($values === []) {
            return [];
        }

        $meta = self::extractMeta($rule);

        if ($meta === null) {
            return []; // Reflection failed — assume none taken (safe fallback: lets per-item handle it)
        }

        return self::queryValues(
            $meta['connection'],
            $meta['table'],
            $meta['column'],
            $values,
            $meta['wheres'],
            $meta['ignore'],
            $meta['idColumn'],
        );
    }

    /**
     * Create an empty PrecomputedPresenceVerifier with an optional fallback
     * for rules that weren't pre-computed (e.g., closure-callback rules).
     */
    public static function makeVerifier(?PresenceVerifierInterface $fallback = null): PrecomputedPresenceVerifier
    {
        return new PrecomputedPresenceVerifier($fallback);
    }

    /**
     * Get the verifier table name (connection-stripped) from a database rule.
     */
    public static function getVerifierTable(Exists|Unique $rule): ?string
    {
        return self::extractMeta($rule)['table'] ?? null;
    }

    /**
     * Get the column name from a database rule.
     */
    public static function getVerifierColumn(Exists|Unique $rule): ?string
    {
        return self::extractMeta($rule)['column'] ?? null;
    }

    /**
     * Find batchable Exists/Unique rules in a set of compiled rules.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, Exists|Unique>  Field name → rule object
     */
    public static function findBatchableRules(array $rules): array
    {
        $batchable = [];

        foreach ($rules as $field => $fieldRules) {
            if (! is_array($fieldRules)) {
                continue;
            }

            foreach ($fieldRules as $rule) {
                if (($rule instanceof Exists || $rule instanceof Unique) && self::isBatchable($rule)) {
                    $batchable[$field] = $rule;
                    break;
                }
            }
        }

        return $batchable;
    }

    /**
     * Collect values from items for batchable rules and group by table:column.
     *
     * @param  array<string, Exists|Unique>  $batchableFields
     * @param  array<int|string, mixed>  $items
     * @param  array<string, mixed>  $fieldRules  Per-field rule array used to derive type predicates.
     * @return array<string, array{rule: Exists|Unique, values: list<mixed>}>
     */
    public static function collectValues(array $batchableFields, array $items, bool $isScalar, array $fieldRules = []): array
    {
        /** @var array<string, list<mixed>> $allValues */
        $allValues = [];

        foreach ($items as $item) {
            /** @var array<string, mixed> $itemData */
            $itemData = $isScalar ? ['_v' => $item] : (is_array($item) ? $item : []);

            foreach (array_keys($batchableFields) as $field) {
                $value = $itemData[$field] ?? null;

                if ($value !== null && $value !== '') {
                    $allValues[$field][] = $value;
                }
            }
        }

        /** @var array<string, array{rule: Exists|Unique, values: list<mixed>}> $groups */
        $groups = [];

        foreach ($batchableFields as $field => $rule) {
            $raw = $allValues[$field] ?? [];
            $rulesForField = $fieldRules[$field] ?? null;
            $filtered = (is_array($rulesForField) || is_string($rulesForField))
                ? self::filterValuesByType($raw, $rulesForField)
                : $raw;
            $values = self::uniqueStringValues($filtered);

            if ($values === []) {
                continue;
            }

            $table = self::getVerifierTable($rule);
            $column = self::getVerifierColumn($rule);
            if ($table === null) {
                continue;
            }

            if ($column === null) {
                continue;
            }

            $groups[self::groupKey($table, $column, $rule)] = ['rule' => $rule, 'values' => $values];
        }

        return $groups;
    }

    /**
     * Collect values from expanded attribute paths (FormRequest path).
     * Unlike collectValues() which iterates items, this iterates expanded
     * rules like 'items.0.email', 'items.1.email' and uses data_get().
     *
     * @param  array<string, mixed>  $preparedRules  Expanded rules with concrete paths
     * @param  array<string, mixed>  $data
     * @return array<string, array{rule: Exists|Unique, values: list<mixed>}>
     */
    public static function collectExpandedValues(array $preparedRules, array $data): array
    {
        /** @var array<string, array{rule: Exists|Unique, values: list<mixed>}> $groups */
        $groups = [];
        /** @var array<string, array<mixed>|string> $groupItemRules */
        $groupItemRules = [];

        foreach ($preparedRules as $attribute => $attributeRules) {
            if (! is_array($attributeRules)) {
                continue;
            }

            foreach ($attributeRules as $rule) {
                if (! ($rule instanceof Exists) && ! ($rule instanceof Unique)) {
                    continue;
                }

                if (! self::isBatchable($rule)) {
                    continue;
                }

                $table = self::getVerifierTable($rule);
                $column = self::getVerifierColumn($rule);
                if ($table === null) {
                    continue;
                }

                if ($column === null) {
                    continue;
                }

                $key = self::groupKey($table, $column, $rule);
                $groups[$key] ??= ['rule' => $rule, 'values' => []];
                $groupItemRules[$key] ??= $attributeRules;

                $value = data_get($data, $attribute);

                if ($value !== null && $value !== '') {
                    $groups[$key]['values'][] = $value;
                }
            }
        }

        foreach ($groups as $key => $group) {
            $filtered = self::filterValuesByType(
                $group['values'],
                $groupItemRules[$key] ?? [],
            );
            $groups[$key]['values'] = self::uniqueStringValues($filtered);
        }

        return $groups;
    }

    /**
     * Build the grouping key for a batched rule. Disambiguates exists / unique
     * against the same (table, column) so a validator carrying both rules
     * does not conflate them.
     */
    private static function groupKey(string $table, string $column, Exists|Unique $rule): string
    {
        return $table . ':' . $column . ':' . ($rule instanceof Unique ? 'unique' : 'exists');
    }

    /**
     * Filter values against per-item string-form type rules (integer, numeric,
     * uuid, ulid, string). Values that would never pass per-item validation
     * are dropped here so the batched whereIn query never sees them —
     * preventing strict-DB crashes (PostgreSQL rejects `"abc"::INTEGER` with
     * `invalid input syntax for type integer`).
     *
     * Object-form rules fall through unchanged; if no known type rule is
     * present, values are returned as-is (matches previous behaviour).
     *
     * @param  array<mixed>  $values
     * @param  array<mixed>|string  $itemRules  Either a pipe-delimited string or an array of rules.
     * @return array<int, mixed>
     */
    public static function filterValuesByType(array $values, array|string $itemRules): array
    {
        $predicates = self::derivePredicates($itemRules);

        if ($predicates === []) {
            return array_values($values);
        }

        return array_values(array_filter($values, static function (mixed $v) use ($predicates): bool {
            foreach ($predicates as $predicate) {
                if (! $predicate($v)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Derive value-predicates from string-form type rules in an item rule set.
     *
     * @param  array<mixed>|string  $itemRules
     * @return list<\Closure(mixed): bool>
     */
    private static function derivePredicates(array|string $itemRules): array
    {
        $strings = [];

        if (is_string($itemRules)) {
            foreach (explode('|', $itemRules) as $rule) {
                $strings[] = $rule;
            }
        } else {
            foreach ($itemRules as $rule) {
                if (is_string($rule)) {
                    $strings[] = $rule;
                }
            }
        }

        $predicates = [];

        foreach ($strings as $rule) {
            $predicate = self::predicateFor($rule);

            if ($predicate instanceof \Closure) {
                $predicates[] = $predicate;
            }
        }

        return $predicates;
    }

    private static function predicateFor(string $rule): ?\Closure
    {
        // Normalise: rules may carry parameters (e.g. `uuid:4`) — only the
        // leading token determines type.
        $token = str_contains($rule, ':') ? substr($rule, 0, (int) strpos($rule, ':')) : $rule;

        return match ($token) {
            'integer', 'int' => static fn (mixed $v): bool => filter_var($v, FILTER_VALIDATE_INT) !== false,
            'numeric' => is_numeric(...),
            'uuid' => static fn (mixed $v): bool => is_string($v) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v) === 1,
            'ulid' => static fn (mixed $v): bool => is_string($v) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $v) === 1,
            'string' => static fn (mixed $v): bool => is_scalar($v) || $v instanceof \Stringable,
            default => null,
        };
    }

    /**
     * Build a PrecomputedPresenceVerifier from grouped rules.
     *
     * Groups arrive already filtered (Phase 1) and deduplicated (collectors
     * own the dedup). Canonical order is filter → dedup → cap check → query.
     *
     * @param  array<string, array{rule: Exists|Unique, values: list<mixed>}>  $groups
     *
     * @throws BatchLimitExceededException When any group's value count exceeds `$maxValuesPerGroup`.
     */
    public static function buildVerifier(array $groups): ?PrecomputedPresenceVerifier
    {
        self::assertWithinCap($groups);

        $fallback = null;

        try {
            $fallback = resolve(DatabasePresenceVerifier::class);
        } catch (\Throwable) {
            // No verifier bound
        }

        $verifier = self::makeVerifier($fallback instanceof PresenceVerifierInterface ? $fallback : null);
        self::registerLookups($verifier, $groups);

        return $verifier->hasLookups() ? $verifier : null;
    }

    /**
     * Throw `BatchLimitExceededException` if any group exceeds the hard cap.
     * Called after filter/dedup so the cap sees the same normalised set the
     * query will.
     *
     * @param  array<string, array{rule: Exists|Unique, values: list<mixed>}>  $groups
     */
    private static function assertWithinCap(array $groups): void
    {
        foreach ($groups as $key => $group) {
            $count = count($group['values']);

            if ($count <= self::$maxValuesPerGroup) {
                continue;
            }

            [$table, $column, $ruleType] = self::parseGroupKey($key);

            throw new BatchLimitExceededException(
                table: $table,
                column: $column,
                ruleType: $ruleType,
                reason: BatchLimitExceededException::REASON_HARD_CAP,
                valueCount: $count,
                limit: self::$maxValuesPerGroup,
            );
        }
    }

    /**
     * Batch-query and register lookups on a verifier for a set of grouped rules.
     *
     * Groups are already filtered + deduped by the collector — no further
     * normalisation here.
     *
     * If the same (table, column) appears in BOTH an `exists` and a `unique`
     * group in this validator, the two rules need semantically distinct
     * lookups (unique with `ignore()` excludes a specific row from "taken")
     * but Laravel's presence-verifier interface — `getCount` is called by
     * both rule types — cannot route to the right bucket from the method
     * alone. Register neither; the fallback `DatabasePresenceVerifier` then
     * handles each rule correctly via per-item queries. Rare case, small
     * perf hit — exists and unique on the same (table, column) is unusual.
     *
     * @param  array<string, array{rule: Exists|Unique, values: list<mixed>}>  $groups  Keyed by "table:column:ruleType"
     */
    public static function registerLookups(PrecomputedPresenceVerifier $verifier, array $groups): void
    {
        $conflicting = self::findConflictingTableColumns($groups);

        foreach ($groups as $key => $group) {
            $values = $group['values'];

            if ($values === []) {
                continue;
            }

            [$table, $column] = self::parseGroupKey($key);

            if (isset($conflicting[$table . ':' . $column])) {
                continue;
            }

            $fetched = $group['rule'] instanceof Unique
                ? self::fetchTaken($values, $group['rule'])
                : self::fetchExisting($values, $group['rule']);

            $verifier->addLookup($table, $column, $fetched);
        }
    }

    /**
     * Return the set of "table:column" strings that appear under more than
     * one rule type in the grouped rules (i.e. both an exists and a unique
     * group exist for the same physical column).
     *
     * @param  array<string, array{rule: Exists|Unique, values: list<mixed>}>  $groups
     * @return array<string, true>
     */
    private static function findConflictingTableColumns(array $groups): array
    {
        /** @var array<string, array<string, true>> $seen */
        $seen = [];

        foreach (array_keys($groups) as $key) {
            [$table, $column, $ruleType] = self::parseGroupKey($key);
            $seen[$table . ':' . $column][$ruleType] = true;
        }

        $conflicting = [];

        foreach ($seen as $tableColumn => $ruleTypes) {
            if (count($ruleTypes) > 1) {
                $conflicting[$tableColumn] = true;
            }
        }

        return $conflicting;
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private static function parseGroupKey(string $key): array
    {
        $parts = explode(':', $key, 3);

        return [$parts[0] ?? '', $parts[1] ?? '', $parts[2] ?? 'exists'];
    }

    /**
     * Extract metadata from a database rule via reflection.
     *
     * @return array{connection: string|null, table: string, column: string, wheres: array<int, array{column: string, value: string}>, ignore: mixed, idColumn: string}|null
     */
    private static function extractMeta(Exists|Unique $rule): ?array
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
                $ignore = self::readProperty($rule, 'ignore');
                $rawIdColumn = self::readProperty($rule, 'idColumn');
                $idColumn = is_string($rawIdColumn) ? $rawIdColumn : 'id';
            }

            /** @var array<int, array{column: string, value: string}> $typedWheres */
            $typedWheres = [];

            foreach ($wheres as $where) {
                if (is_array($where) && isset($where['column'], $where['value'])
                    && is_string($where['column']) && is_scalar($where['value'])) {
                    $typedWheres[] = ['column' => $where['column'], 'value' => (string) $where['value']];
                }
            }

            return [
                'connection' => $connection,
                'table' => $table,
                'column' => $column === 'NULL' ? '' : $column,
                'wheres' => $typedWheres,
                'ignore' => $ignore,
                'idColumn' => $idColumn,
            ];
        } catch (ReflectionException) {
            return null;
        }
    }

    /**
     * Run the batched whereIn query and return matching values.
     *
     * @param  array<int, mixed>  $values
     * @param  array<int, array{column: string, value: string}>  $wheres
     * @return array<int, mixed>
     */
    private static function queryValues(
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

            // Replay scalar where conditions (matching DatabasePresenceVerifier::addWhere())
            foreach ($wheres as $where) {
                if (! isset($where['column'], $where['value'])) {
                    continue;
                }

                if (! is_string($where['column'])) {
                    continue;
                }

                if (! is_string($where['value'])) {
                    continue;
                }

                $extraValue = $where['value'];

                if ($extraValue === 'NULL') {
                    $query->whereNull($where['column']);
                } elseif ($extraValue === 'NOT_NULL') {
                    $query->whereNotNull($where['column']);
                } elseif (str_starts_with($extraValue, '!')) {
                    $query->where($where['column'], '!=', mb_substr($extraValue, 1));
                } else {
                    $query->where($where['column'], $extraValue);
                }
            }

            // Unique: exclude the ignored ID
            if ($ignore !== null && $ignore !== 'NULL') {
                $query->where($idColumn, '<>', $ignore);
            }

            $chunks[] = array_values($query->pluck($column)->all());
        }

        return array_merge(...$chunks);
    }

    /**
     * Deduplicate and cast values to strings for batch queries.
     *
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private static function uniqueStringValues(array $values): array
    {
        return array_values(array_unique(
            array_map(
                static fn (mixed $v): string => is_scalar($v) || $v instanceof \Stringable ? (string) $v : '',
                $values,
            ),
            SORT_STRING,
        ));
    }

    private static function readProperty(object $object, string $property): mixed
    {
        return (new ReflectionProperty($object, $property))->getValue($object);
    }
}
