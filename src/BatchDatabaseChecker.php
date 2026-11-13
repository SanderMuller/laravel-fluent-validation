<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\PresenceVerifierInterface;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;
use SanderMuller\FluentValidation\Exceptions\BatchLimitExceededException;
use SanderMuller\FluentValidation\Internal\BatchPresenceQuery;
use SanderMuller\FluentValidation\Internal\DatabaseRuleMeta;
use SanderMuller\FluentValidation\Internal\ValueTypePredicates;
use Stringable;
use Throwable;

/**
 * Batches exists/unique database validation queries for wildcard arrays.
 *
 * Instead of N queries (one per item), collects all values and runs a single
 * whereIn query. Returns a PrecomputedPresenceVerifier that can be set on
 * per-item validators, keeping original rule objects intact for correct
 * error message resolution.
 *
 * A rule is batched only when its query can be replayed exactly (see
 * isBatchable()). Other rules fall through to per-item validation.
 */
final class BatchDatabaseChecker
{
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
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Check if a database rule can be batched: it has an explicit column, no
     * closure callbacks, and where clauses and an ignored id that can be
     * replayed exactly.
     */
    public static function isBatchable(Exists|Unique $rule): bool
    {
        if ($rule->queryCallbacks() !== []) {
            return false;
        }

        // A rule stays per-item when its where clauses or ignored id cannot be
        // replayed exactly, or when Laravel infers its column from the
        // attribute name (column 'NULL').
        $meta = DatabaseRuleMeta::extract($rule);

        return $meta !== null && $meta['column'] !== '';
    }

    /**
     * Batch-query which values exist in the database for an Exists rule.
     *
     * @param  array<int, mixed>  $values  Deduplicated, non-null values to check
     * @return array<int, mixed>  The given values that match a row, as the database compares them
     */
    public static function fetchExisting(array $values, Exists $rule): array
    {
        if ($values === []) {
            return [];
        }

        $meta = DatabaseRuleMeta::extract($rule);

        if ($meta === null) {
            return $values; // Not batchable (see isBatchable()) — assume all exist
        }

        return BatchPresenceQuery::run($meta['connection'], $meta['table'], $meta['column'], $values, $meta['wheres']);
    }

    /**
     * Batch-query which values are already taken for a Unique rule.
     *
     * @param  array<int, mixed>  $values  Deduplicated, non-null values to check
     * @return array<int, mixed>  The given values that are taken, as the database compares them
     */
    public static function fetchTaken(array $values, Unique $rule): array
    {
        if ($values === []) {
            return [];
        }

        $meta = DatabaseRuleMeta::extract($rule);

        if ($meta === null) {
            return []; // Not batchable (see isBatchable()) — assume none taken
        }

        return BatchPresenceQuery::run(
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
        return DatabaseRuleMeta::extract($rule)['table'] ?? null;
    }

    /**
     * Get the column name from a database rule.
     */
    public static function getVerifierColumn(Exists|Unique $rule): ?string
    {
        return DatabaseRuleMeta::extract($rule)['column'] ?? null;
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
     * Collect values from items for batchable rules and group them by groupKey().
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
            $key = self::groupKey($rule);

            if ($filtered === [] || $key === null) {
                continue;
            }

            // Fields with the same group key run one query on all their values.
            $groups[$key] = [
                'rule' => $groups[$key]['rule'] ?? $rule,
                'values' => [...$groups[$key]['values'] ?? [], ...$filtered],
            ];
        }

        return self::uniqueGroupValues($groups);
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

                $key = self::groupKey($rule);

                if ($key === null) {
                    continue;
                }

                $groups[$key] ??= ['rule' => $rule, 'values' => []];

                $value = data_get($data, $attribute);

                if ($value === null || $value === '') {
                    continue;
                }

                // Filter with this attribute's own type rules: attributes that
                // share a group can carry different ones.
                array_push($groups[$key]['values'], ...self::filterValuesByType([$value], $attributeRules));
            }
        }

        return self::uniqueGroupValues(array_filter($groups, static fn (array $group): bool => $group['values'] !== []));
    }

    /**
     * @param  array<string, array{rule: Exists|Unique, values: list<mixed>}>  $groups
     * @return array<string, array{rule: Exists|Unique, values: list<mixed>}>
     */
    private static function uniqueGroupValues(array $groups): array
    {
        foreach ($groups as $key => $group) {
            $groups[$key]['values'] = self::uniqueStringValues($group['values']);
        }

        return $groups;
    }

    /**
     * Rules share a group only when they run the same query and have the
     * same rule type.
     */
    private static function groupKey(Exists|Unique $rule): ?string
    {
        $meta = DatabaseRuleMeta::extract($rule);

        if ($meta === null) {
            return null;
        }

        $key = ($meta['connection'] !== null ? $meta['connection'] . '.' : '')
            . $meta['table'] . ':' . $meta['column'] . ':' . ($rule instanceof Unique ? 'unique' : 'exists');

        if ($meta['conditions'] === [] && $meta['ignore'] === null) {
            return $key;
        }

        $conditions = $meta['conditions'];
        ksort($conditions, SORT_STRING);

        // serialize() cannot fail on strings; json_encode() fails on invalid UTF-8.
        return $key . ' ' . serialize([$conditions, $meta['ignore'], $meta['idColumn']]);
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
        return ValueTypePredicates::filter($values, $itemRules);
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
        } catch (Throwable) {
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
        foreach ($groups as $group) {
            $count = count($group['values']);

            if ($count <= self::$maxValuesPerGroup) {
                continue;
            }

            throw new BatchLimitExceededException(
                table: self::getVerifierTable($group['rule']) ?? '',
                column: self::getVerifierColumn($group['rule']) ?? '',
                ruleType: $group['rule'] instanceof Unique ? 'unique' : 'exists',
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
     * Lookups are keyed by the exact verifier call, so a rule never reads
     * another query's result. Exists and unique without an ignored id run the
     * same query and share one lookup.
     *
     * @param  array<string, array{rule: Exists|Unique, values: list<mixed>}>  $groups
     */
    public static function registerLookups(PrecomputedPresenceVerifier $verifier, array $groups): void
    {
        foreach ($groups as $group) {
            $values = $group['values'];
            $meta = DatabaseRuleMeta::extract($group['rule']);

            if ($values === [] || $meta === null) {
                continue;
            }

            $fetched = $group['rule'] instanceof Unique
                ? self::fetchTaken($values, $group['rule'])
                : self::fetchExisting($values, $group['rule']);

            $verifier->addQueryLookup(
                $meta['connection'],
                $meta['table'],
                $meta['column'],
                $meta['conditions'],
                $meta['ignore'],
                $meta['idColumn'],
                $values,
                $fetched,
            );
        }
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
                static fn (mixed $v): string => is_scalar($v) || $v instanceof Stringable ? (string) $v : '',
                $values,
            ),
            SORT_STRING,
        ));
    }
}
