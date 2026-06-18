<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Closure;
use Illuminate\Support\Arr;
use SanderMuller\FluentValidation\BatchDatabaseChecker;
use SanderMuller\FluentValidation\FastCheckCompiler;
use SanderMuller\FluentValidation\PrecomputedPresenceVerifier;
use SanderMuller\FluentValidation\PresenceConditionalReducer;
use SanderMuller\FluentValidation\ValueConditionalReducer;
use Stringable;

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
     * Reduce item rules by evaluating conditional exclusions against the item data.
     *
     * @param  array<string, mixed>  $itemRules
     * @param  array<string, mixed>  $itemData
     * @param  array<string, list<array{action: string, field: string, values: list<string>}>>  $conditionalFields
     * @param  array<string, string>  $itemMessages
     * @return array<string, mixed>
     */
    public function reduceRulesForItem(array $itemRules, array $itemData, array $conditionalFields, array $itemMessages = []): array
    {
        foreach ($conditionalFields as $field => $conditions) {
            if ($this->fieldIsExcluded($conditions, $itemData, $itemRules)) {
                unset($itemRules[$field]);
            } else {
                // Kept — strip the exclude tuple so only the actual validation
                // rules remain. This enables fast-checking.
                $itemRules[$field] = $this->stripConditionalTuples($itemRules[$field]);
            }
        }

        foreach ($itemRules as $field => $rule) {
            $rule = PresenceConditionalReducer::apply($rule, (string) $field, $itemData, $itemMessages);
            $itemRules[$field] = ValueConditionalReducer::apply($rule, (string) $field, $itemData, $itemMessages, $itemRules);
        }

        return $itemRules;
    }

    /**
     * Is the field excluded by ANY of its exclude_* conditions? Mirrors native
     * Laravel, which evaluates every exclude rule on a field.
     *
     * @param  list<array{action: string, field: string, values: list<string>}>  $conditions
     * @param  array<string, mixed>  $itemData
     * @param  array<string, mixed>  $itemRules
     */
    private function fieldIsExcluded(array $conditions, array $itemData, array $itemRules): bool
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
     * Strip exclude_unless/exclude_if tuples from a rule array, leaving the
     * actual validation rules. Joins remaining strings into a pipe-delimited
     * string when possible.
     *
     * Only the exclude tuples are removed — required_if/required_unless tuples
     * must survive so the field's presence conditional still validates (the
     * value-conditional reducer rewrites string forms; native Laravel handles
     * the surviving tuple form).
     */
    private function stripConditionalTuples(mixed $rules): mixed
    {
        if (! is_array($rules)) {
            return $rules;
        }

        $stripped = [];

        foreach ($rules as $rule) {
            if (is_array($rule) && isset($rule[0]) && is_string($rule[0])
                && in_array($rule[0], ['exclude_unless', 'exclude_if'], true)) {
                continue;
            }

            // Stringify Stringable objects (Rule::in, Rule::notIn) so the
            // result can be fast-checked as a pipe-joined string.
            $stripped[] = $rule instanceof Stringable ? (string) $rule : $rule;
        }

        // If all remaining rules are strings, join them for faster parsing —
        // unless a token contains a literal `|` (e.g. `regex:/^(a|b)$/`), which
        // Laravel's parser would split. Keep the array form in that case.
        $allStrings = true;
        $anyContainsPipe = false;
        foreach ($stripped as $rule) {
            if (! is_string($rule)) {
                $allStrings = false;
                break;
            }

            if (str_contains($rule, '|')) {
                $anyContainsPipe = true;
            }
        }

        if ($allStrings && ! $anyContainsPipe && $stripped !== []) {
            return implode('|', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $stripped));
        }

        return $stripped;
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
     * @return array{0: list<Closure(array<string, mixed>): bool>, 1: array<string, mixed>}
     */
    public function buildFastChecks(array $compiledRules): array
    {
        $checks = [];
        $slowRules = [];

        foreach ($compiledRules as $field => $rule) {
            if (! is_string($rule)) {
                $slowRules[$field] = $rule;

                continue;
            }

            $valueCheck = FastCheckCompiler::compile($rule);
            $itemAwareCheck = null;

            if (! $valueCheck instanceof Closure) {
                // Pass the within-item attribute name so `confirmed` can
                // rewrite to `same:${attr}_confirmation`. For `items.*.password`
                // the attribute is `password`; for flat `password` it's the
                // key itself.
                $attributeName = str_contains($field, '*.')
                    ? explode('.*.', $field, 2)[1]
                    : $field;

                $itemAwareCheck = FastCheckCompiler::compileWithItemContext($rule, $attributeName)
                    ?? FastCheckCompiler::compileWithPresenceConditionals($rule);

                if (! $itemAwareCheck instanceof Closure) {
                    $slowRules[$field] = $rule;

                    continue;
                }
            }

            // Nested wildcard field (e.g., options.*.label): expand and check each item
            if (str_contains($field, '*.')) {
                $parts = explode('.*.', $field, 2);
                $parentField = $parts[0];
                $childField = $parts[1];

                if ($itemAwareCheck instanceof Closure) {
                    $checks[] = static function (array $data) use ($parentField, $childField, $itemAwareCheck): bool {
                        $items = $data[$parentField] ?? null;
                        if (! is_array($items)) {
                            return true;
                        }

                        foreach ($items as $item) {
                            if (! is_array($item)) {
                                return false;
                            }

                            /** @var array<string, mixed> $item */
                            if (! $itemAwareCheck($item[$childField] ?? null, $item)) {
                                return false;
                            }
                        }

                        return true;
                    };
                } else {
                    $checks[] = static function (array $data) use ($parentField, $childField, $valueCheck): bool {
                        $items = $data[$parentField] ?? null;
                        if (! is_array($items)) {
                            return true;
                        }

                        foreach ($items as $item) {
                            if (! is_array($item)) {
                                return false;
                            }

                            if (! $valueCheck($item[$childField] ?? null)) {
                                return false;
                            }
                        }

                        return true;
                    };
                }
            } elseif ($field === '*') {
                // Scalar each: value is in '_v' key
                if ($itemAwareCheck instanceof Closure) {
                    $checks[] = static function (array $data) use ($itemAwareCheck): bool {
                        /** @var array<string, mixed> $data — caller guarantees string-keyed. */
                        return $itemAwareCheck($data['_v'] ?? null, $data);
                    };
                } else {
                    $checks[] = static fn (array $data): bool => $valueCheck($data['_v'] ?? null);
                }
            } elseif ($itemAwareCheck instanceof Closure) {
                $checks[] = static function (array $data) use ($field, $itemAwareCheck): bool {
                    /** @var array<string, mixed> $data — caller guarantees string-keyed. */
                    return $itemAwareCheck($data[$field] ?? null, $data);
                };
            } else {
                $checks[] = static fn (array $data): bool => $valueCheck($data[$field] ?? null);
            }
        }

        return [$checks, $slowRules];
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
