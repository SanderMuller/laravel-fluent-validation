<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Closure;
use Illuminate\Support\Arr;
use SanderMuller\FluentValidation\BatchDatabaseChecker;
use SanderMuller\FluentValidation\FastCheckCompiler;
use SanderMuller\FluentValidation\PrecomputedPresenceVerifier;
use SanderMuller\FluentValidation\PresenceConditionalReducer;
use SanderMuller\FluentValidation\ValueConditionalReducer;

/**
 * Collaborator for {@see ItemValidator}. Extracts the rule-shape concerns
 * previously tangled in `RuleSet`: conditional analysis, rule reduction,
 * dispatch-field detection, cache-key generation, fast-check compilation,
 * and batch-verifier construction.
 *
 * Instantiated once per `validate()` call on `RuleSet`; holds no per-call state.
 *
 * @internal
 */
final class ItemRuleCompiler
{
    /**
     * Analyze conditional rules (exclude_unless/exclude_if) in item rules.
     * Returns a map of field → all of its exclude conditions for fast per-item
     * evaluation. A field may carry more than one exclude_* rule; all of them
     * must be evaluated (the field is excluded if any fires), matching native.
     *
     * @param  array<string, mixed>  $itemRules
     * @return array<string, list<array{action: string, field: string, values: list<string>}>>
     */
    public function analyzeConditionals(array $itemRules): array
    {
        $conditionals = [];

        foreach ($itemRules as $field => $rules) {
            // Recognise tuple AND string/pipe-joined exclude_* forms (the fluent
            // `->excludeUnless()` API compiles to the string form).
            $conditions = ExcludeConditionExtractor::extract($rules);

            if ($conditions !== []) {
                $conditionals[$field] = $conditions;
            }
        }

        return $conditionals;
    }

    /**
     * Reduce item rules against the item data: drop excluded fields, then run
     * the presence and value reducers on the fields that carry a conditional.
     *
     * @param  array<string, mixed>  $itemRules
     * @param  array<string, mixed>  $itemData
     * @param  array<string, list<array{action: string, field: string, values: list<string>}>>  $conditionalFields
     * @param  array<string, string>  $itemMessages
     * @param  array<string, true>|null  $siblingFields  the fields with a presence or value conditional; null reduces every field
     * @return array<string, mixed>
     */
    public function reduceRulesForItem(array $itemRules, array $itemData, array $conditionalFields, array $itemMessages = [], ?array $siblingFields = null, bool $keepActiveConditionals = false): array
    {
        // Laravel removes an excluded field only after it has run every rule,
        // so a `boolean` rule on an excluded field still converts the values.
        $allRules = $itemRules;

        foreach ($conditionalFields as $field => $conditions) {
            if (self::fieldIsExcluded($conditions, $itemData, $allRules)) {
                unset($itemRules[$field]);
            } else {
                // Kept — strip the exclude tuple so only the actual validation
                // rules remain. This enables fast-checking.
                $itemRules[$field] = ExcludeRuleStripper::strip($itemRules[$field]);
            }
        }

        $reducible = $siblingFields === null ? $itemRules : array_intersect_key($itemRules, $siblingFields);

        foreach ($reducible as $field => $rule) {
            $rule = PresenceConditionalReducer::apply($rule, (string) $field, $itemData, $itemMessages, $keepActiveConditionals);
            $itemRules[$field] = ValueConditionalReducer::apply($rule, (string) $field, $itemData, $itemMessages, $allRules, $keepActiveConditionals);
        }

        return $itemRules;
    }

    /**
     * Rules that let Laravel exclude each given field that has a `boolean`
     * rule, and still see that rule when another rule reads the field.
     *
     * @param  array<array-key, mixed>  $fields
     * @param  array<string, mixed>  $itemRules
     * @return array<string, list<string>>
     */
    public static function excludedBooleanRules(array $fields, array $itemRules): array
    {
        $rules = [];

        foreach (array_keys($fields) as $field) {
            if (ConditionalValueMatcher::hasBooleanRule((string) $field, $itemRules)) {
                $rules[(string) $field] = ['exclude', 'boolean'];
            }
        }

        return $rules;
    }

    /**
     * Is the field excluded by ANY of its exclude_* conditions? Mirrors native
     * Laravel, which evaluates every exclude rule on a field.
     *
     * @param  list<array{action: string, field: string, values: list<string>}>  $conditions
     * @param  array<array-key, mixed>  $itemData
     * @param  array<string, mixed>  $itemRules
     */
    public static function fieldIsExcluded(array $conditions, array $itemData, array $itemRules): bool
    {
        foreach ($conditions as $condition) {
            // exclude_if is inactive when the dependent field is absent (mirrors
            // Laravel's Arr::has short-circuit); short-circuiting also skips the
            // matcher's data_get there. Otherwise match via the shared matcher,
            // which reproduces Laravel's null/bool/loose-scalar coercion.
            $present = $condition['action'] !== 'exclude_if' || Arr::has($itemData, $condition['field']);
            $match = $present
                && ConditionalValueMatcher::matches($condition['field'], $condition['values'], $itemData, $itemRules);

            if ($condition['action'] === 'exclude_unless' ? ! $match : $match) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find a common dispatch field if EVERY exclude condition references the
     * same field. Returns the field name (e.g., "type") or null if conditions
     * reference different fields or there are no conditionals.
     *
     * @param  array<string, list<array{action: string, field: string, values: list<string>}>>  $conditionalFields
     */
    public function findCommonDispatchField(array $conditionalFields): ?string
    {
        if ($conditionalFields === []) {
            return null;
        }

        $field = null;

        foreach ($conditionalFields as $conditions) {
            foreach ($conditions as $condition) {
                if ($field === null) {
                    $field = $condition['field'];
                } elseif ($field !== $condition['field']) {
                    return null; // Multiple fields — can't dispatch.
                }
            }
        }

        return $field;
    }

    /**
     * Delegate to `RuleCacheKey::for` — see that class for the rationale on
     * why field names alone are insufficient after per-item reducers engage.
     *
     * @param  array<string, mixed>  $rules
     */
    public function ruleCacheKey(array $rules): string
    {
        return RuleCacheKey::for($rules);
    }

    /**
     * Build fast-check closures for eligible fields.
     * Returns fast checks for compilable fields and the remaining slow rules.
     *
     * @param  array<string, mixed>  $compiledRules
     * @return array{0: array<string, Closure(array<string, mixed>): bool>, 1: array<string, mixed>} checks keyed by field, then the slow rules
     */
    public function buildFastChecks(array $compiledRules): array
    {
        [$readableRules, $slowRules] = PathFastCheck::splitUnreadable($compiledRules);
        [$checks, $readableRules] = CustomRuleFastCheck::split($readableRules);

        foreach ($readableRules as $field => $rule) {
            // A numeric rule key such as `0` is an integer array key.
            $field = (string) $field;

            if (! is_string($rule)) {
                $slowRules[$field] = $rule;

                continue;
            }

            $valueCheck = FastCheckCompiler::compile($rule);
            $itemAwareCheck = $valueCheck instanceof Closure ? null : $this->compileItemAwareCheck($field, $rule);

            if (! $valueCheck instanceof Closure && ! $itemAwareCheck instanceof Closure) {
                $slowRules[$field] = $rule;

                continue;
            }

            // Nested wildcard field (e.g., options.*.label): expand and check each item
            if (str_contains($field, '*.')) {
                [$parentField, $childField] = explode('.*.', $field, 2);
                $check = PathFastCheck::eachItem($parentField, $childField, $valueCheck, $itemAwareCheck);

                if (! $check instanceof Closure) {
                    $slowRules[$field] = $rule;

                    continue;
                }

                $checks[$field] = $check;
            } elseif ($field === '*' || str_contains($field, '.')) {
                $checks[$field] = PathFastCheck::build($field === '*' ? ['_v'] : explode('.', $field), $valueCheck, $itemAwareCheck);
            } elseif ($itemAwareCheck instanceof Closure) {
                $checks[$field] = static function (array $data) use ($field, $itemAwareCheck): bool {
                    /** @var array<string, mixed> $data — caller guarantees string-keyed. */
                    return $itemAwareCheck($data[$field] ?? null, $data);
                };
            } else {
                $checks[$field] = static fn (array $data): bool => $valueCheck($data[$field] ?? null);
            }
        }

        // Slow rules keep the input order, so Laravel reports errors in rule order.
        return [$checks, array_intersect_key($compiledRules, FieldLocalRules::withReadReferences($slowRules, $compiledRules))];
    }

    /** @return (Closure(mixed, array<string, mixed>): bool)|null */
    private function compileItemAwareCheck(string $field, string $rule): ?Closure
    {
        // Pass the within-item attribute name so `confirmed` can
        // rewrite to `same:${attr}_confirmation`. For `items.*.password`
        // the attribute is `password`; for flat `password` it's the
        // key itself.
        $attributeName = str_contains($field, '*.')
            ? explode('.*.', $field, 2)[1]
            : $field;

        return FastCheckCompiler::compileWithItemContext($rule, $attributeName)
            ?? FastCheckCompiler::compileWithPresenceConditionals($rule);
    }

    /**
     * Build a PrecomputedPresenceVerifier by batching all exists/unique values
     * from slow rules across all items in a single whereIn query.
     *
     * @param  array<string, mixed>  $slowRules
     * @param  array<int|string, mixed>  $items
     */
    public function buildBatchVerifier(array $slowRules, array $items, bool $isScalar): ?PrecomputedPresenceVerifier
    {
        $batchableFields = BatchDatabaseChecker::findBatchableRules($slowRules);

        if ($batchableFields === []) {
            return null;
        }

        $groups = BatchDatabaseChecker::collectValues($batchableFields, $items, $isScalar, $slowRules);

        if ($groups === []) {
            return null;
        }

        return BatchDatabaseChecker::buildVerifier($groups);
    }
}
