<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

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
     * Returns a map of field → condition info for fast per-item evaluation.
     *
     * @param  array<string, mixed>  $itemRules
     * @return array<string, array{action: string, field: string, values: list<string>}>
     */
    public function analyzeConditionals(array $itemRules): array
    {
        $conditionals = [];

        foreach ($itemRules as $field => $rules) {
            if (! is_array($rules)) {
                continue;
            }

            foreach ($rules as $rule) {
                if (is_array($rule) && count($rule) >= 3
                    && ($rule[0] === 'exclude_unless' || $rule[0] === 'exclude_if')
                    && is_string($rule[1])) {
                    $conditionals[$field] = [
                        'action' => $rule[0],
                        'field' => $rule[1],
                        'values' => array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', array_values(array_slice($rule, 2))),
                    ];
                    break;
                }
            }
        }

        return $conditionals;
    }

    /**
     * Reduce item rules by evaluating conditional exclusions against the item data.
     *
     * @param  array<string, mixed>  $itemRules
     * @param  array<string, mixed>  $itemData
     * @param  array<string, array{action: string, field: string, values: list<string>}>  $conditionalFields
     * @param  array<string, string>  $itemMessages
     * @return array<string, mixed>
     */
    public function reduceRulesForItem(array $itemRules, array $itemData, array $conditionalFields, array $itemMessages = []): array
    {
        foreach ($conditionalFields as $field => $condition) {
            $rawValue = $itemData[$condition['field']] ?? '';
            $actualValue = is_scalar($rawValue) ? (string) $rawValue : '';

            $shouldExclude = ($condition['action'] === 'exclude_unless' && ! in_array($actualValue, $condition['values'], true))
                || ($condition['action'] === 'exclude_if' && in_array($actualValue, $condition['values'], true));

            if ($shouldExclude) {
                unset($itemRules[$field]);
            } else {
                // Condition matched — strip the conditional tuple so only the
                // actual validation rules remain. This enables fast-checking.
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
     * Strip exclude_unless/exclude_if tuples from a rule array, leaving
     * only the actual validation rules. Joins remaining strings into a
     * pipe-delimited string when possible.
     */
    private function stripConditionalTuples(mixed $rules): mixed
    {
        if (! is_array($rules)) {
            return $rules;
        }

        $stripped = [];

        foreach ($rules as $rule) {
            if (is_array($rule) && isset($rule[0]) && is_string($rule[0])
                && in_array($rule[0], ['exclude_unless', 'exclude_if', 'required_if', 'required_unless'], true)) {
                continue;
            }

            // Stringify Stringable objects (Rule::in, Rule::notIn) so the
            // result can be fast-checked as a pipe-joined string.
            $stripped[] = $rule instanceof \Stringable ? (string) $rule : $rule;
        }

        // If all remaining rules are strings, join them for faster parsing.
        $allStrings = true;
        foreach ($stripped as $rule) {
            if (! is_string($rule)) {
                $allStrings = false;
                break;
            }
        }

        if ($allStrings && $stripped !== []) {
            return implode('|', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $stripped));
        }

        return $stripped;
    }

    /**
     * Find a common dispatch field if ALL conditionals reference the same field.
     * Returns the field name (e.g., "type") or null if conditions reference
     * different fields or there are no conditionals.
     *
     * @param  array<string, array{action: string, field: string, values: list<string>}>  $conditionalFields
     */
    public function findCommonDispatchField(array $conditionalFields): ?string
    {
        if ($conditionalFields === []) {
            return null;
        }

        $field = null;

        foreach ($conditionalFields as $condition) {
            if ($field === null) {
                $field = $condition['field'];
            } elseif ($field !== $condition['field']) {
                return null; // Multiple fields — can't dispatch.
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
     * @return array{0: list<\Closure(array<string, mixed>): bool>, 1: array<string, mixed>}
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

            if (! $valueCheck instanceof \Closure) {
                // Pass the within-item attribute name so `confirmed` can
                // rewrite to `same:${attr}_confirmation`. For `items.*.password`
                // the attribute is `password`; for flat `password` it's the
                // key itself.
                $attributeName = str_contains($field, '*.')
                    ? explode('.*.', $field, 2)[1]
                    : $field;

                $itemAwareCheck = FastCheckCompiler::compileWithItemContext($rule, $attributeName)
                    ?? FastCheckCompiler::compileWithPresenceConditionals($rule);

                if (! $itemAwareCheck instanceof \Closure) {
                    $slowRules[$field] = $rule;

                    continue;
                }
            }

            // Nested wildcard field (e.g., options.*.label): expand and check each item
            if (str_contains($field, '*.')) {
                $parts = explode('.*.', $field, 2);
                $parentField = $parts[0];
                $childField = $parts[1];

                if ($itemAwareCheck instanceof \Closure) {
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
                if ($itemAwareCheck instanceof \Closure) {
                    $checks[] = static function (array $data) use ($itemAwareCheck): bool {
                        /** @var array<string, mixed> $data — caller guarantees string-keyed. */
                        return $itemAwareCheck($data['_v'] ?? null, $data);
                    };
                } else {
                    $checks[] = static fn (array $data): bool => $valueCheck($data['_v'] ?? null);
                }
            } elseif ($itemAwareCheck instanceof \Closure) {
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
