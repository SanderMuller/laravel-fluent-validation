<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Illuminate\Support\Arr;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\Validator;

/**
 * Validator subclass that fast-checks expanded wildcard attributes
 * using pure PHP closures before falling back to Laravel's validation.
 *
 * On valid data, eligible wildcard attributes skip Laravel's rule parsing,
 * method dispatch, and error formatting entirely — yielding significant
 * speedups on large arrays (hundreds/thousands of items).
 *
 * Ineligible attributes (object rules, date comparisons, cross-field
 * references, etc.) fall through to parent::validateAttribute() transparently.
 */
class OptimizedValidator extends Validator
{
    /** @var array<string, \Closure(mixed): bool> Fast checks keyed by wildcard pattern */
    private array $fastChecks = [];

    /** @var array<string, list<string>> Pattern → expanded attributes (pre-grouped) */
    private array $fastCheckGroups = [];

    /**
     * @param  array<string, \Closure(mixed): bool>  $fastChecks
     * @param  array<string, string>  $attributePatternMap
     */
    public function withFastChecks(array $fastChecks, array $attributePatternMap): static
    {
        $this->fastChecks = $fastChecks;

        // Pre-group attributes by pattern for the fast-check loop
        foreach ($attributePatternMap as $attribute => $pattern) {
            if (isset($fastChecks[$pattern])) {
                $this->fastCheckGroups[$pattern][] = $attribute;
            }
        }

        return $this;
    }

    /**
     * Pre-validate fast-checkable attributes and pre-evaluate conditional
     * rules (exclude_unless/exclude_if) before the main validation loop,
     * removing passing/excluded attributes so Laravel never iterates them.
     */
    public function passes(): bool
    {
        $removedRules = [];
        $this->conditionValueCache = [];

        // Phase 1: Fast-check wildcard attributes by pattern.
        // Iterates per-pattern with all values for that pattern, improving
        // cache locality and reducing closure dispatch overhead.
        if ($this->fastCheckGroups !== []) {
            $flatData = Arr::dot($this->getData());

            foreach ($this->fastCheckGroups as $pattern => $attributes) {
                $check = $this->fastChecks[$pattern];

                foreach ($attributes as $attribute) {
                    if (isset($this->rules[$attribute]) && $check($flatData[$attribute] ?? null)) {
                        $removedRules[$attribute] = $this->rules[$attribute];
                        unset($this->rules[$attribute]);
                    }
                }
            }
        }

        // Phase 2: Conditional pre-evaluation and secondary fast-checks
        // for remaining attributes (non-fast-checked wildcards + top-level rules).
        foreach ($this->rules as $attribute => $attributeRules) {
            if (! is_array($attributeRules)) {
                continue;
            }

            /** @var list<mixed> $rules */
            $rules = $attributeRules;

            // Conditional pre-evaluation: check exclude_unless/exclude_if
            // conditions without going through Laravel's validation loop.
            $excludeResult = $this->evaluateConditionals($attribute, $rules);

            if ($excludeResult === true) {
                // Excluded — don't add to removedRules so it's absent from validated().
                unset($this->rules[$attribute]);

                continue;
            }

            // If condition was present but NOT excluded, try fast-checking the
            // remaining non-conditional rules (e.g., the "string" part of
            // ["exclude_unless:...", "string"]).
            if ($excludeResult === false && $this->fastChecks !== []) {
                $remainingRule = $this->extractNonConditionalRule($rules);

                if ($remainingRule !== null) {
                    $check = FastCheckCompiler::compile($remainingRule);

                    if ($check instanceof \Closure) {
                        $value = $this->getValue($attribute);

                        if ($check($value)) {
                            $removedRules[$attribute] = $rules;
                            unset($this->rules[$attribute]);

                            continue;
                        }
                    }
                }
            }
        }

        if ($removedRules === [] && $this->rules === []) {
            // Everything was removed — skip parent entirely.
            $this->messages = new MessageBag();
            $this->failedRules = [];

            foreach ($this->after as $after) {
                if (is_callable($after)) {
                    $after();
                }
            }

            return $this->messages->isEmpty();
        }

        $result = parent::passes();

        // Restore fast-checked rules so validated() returns their data.
        // (Excluded rules are intentionally NOT restored.)
        foreach ($removedRules as $attribute => $rules) {
            $this->rules[$attribute] = $rules;
        }

        return $result;
    }

    /**
     * Check if an attribute should be excluded based on its exclude_unless
     * or exclude_if condition, without invoking Laravel's validator.
     *
     * @param  list<mixed>  $rules
     */
    /** @var array<string, string> */
    private array $conditionValueCache = [];

    /**
     * Evaluate exclude_unless/exclude_if conditions on an attribute's rules.
     * Returns true if excluded, false if condition present but not excluded,
     * null if no conditional rules found.
     *
     * @param  list<mixed>  $rules
     */
    private function evaluateConditionals(string $attribute, array $rules): ?bool
    {
        $hasCondition = false;

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            if (count($rule) < 3) {
                continue;
            }

            $action = $rule[0];
            $conditionField = $rule[1];

            if ($action !== 'exclude_unless' && $action !== 'exclude_if') {
                continue;
            }

            if (! is_string($conditionField)) {
                continue;
            }

            $hasCondition = true;
            $allowedValues = array_slice($rule, 2);

            if (str_contains($conditionField, '*')) {
                $conditionField = $this->resolveWildcard($attribute, $conditionField);
            }

            if (! isset($this->conditionValueCache[$conditionField])) {
                $rawValue = $this->getValue($conditionField);
                $this->conditionValueCache[$conditionField] = is_scalar($rawValue) ? (string) $rawValue : '';
            }

            $actualValue = $this->conditionValueCache[$conditionField];

            if ($action === 'exclude_unless' && ! in_array($actualValue, $allowedValues, true)) {
                return true;
            }

            if ($action === 'exclude_if' && in_array($actualValue, $allowedValues, true)) {
                return true;
            }
        }

        return $hasCondition ? false : null;
    }

    /**
     * Extract the non-conditional string rules from an attribute's rule array
     * and join them into a pipe-delimited string for fast-check compilation.
     *
     * @param  list<mixed>  $rules
     */
    private function extractNonConditionalRule(array $rules): ?string
    {
        $stringParts = [];

        foreach ($rules as $rule) {
            if (is_string($rule)) {
                $stringParts[] = $rule;
            } elseif (is_array($rule) && isset($rule[0]) && is_string($rule[0])) {
                // Conditional tuple — skip it (already evaluated).
                if (in_array($rule[0], ['exclude_unless', 'exclude_if', 'required_if', 'required_unless'], true)) {
                    continue;
                }

                // Other array tuple — can't fast-check.
                return null;
            } elseif ($rule instanceof \Stringable) {
                // Stringable objects like Rule::in(), Rule::notIn() — stringify
                // them so FastCheckCompiler can handle them.
                $stringParts[] = (string) $rule;
            } else {
                // Non-stringable object (Closure, custom ValidationRule) — bail.
                return null;
            }
        }

        return $stringParts !== [] ? implode('|', $stringParts) : null;
    }

    /**
     * Replace wildcards in a condition field reference with concrete indices
     * from the attribute name. E.g., for attribute "interactions.5.style.top"
     * and condition field "interactions.*.type", returns "interactions.5.type".
     */
    private function resolveWildcard(string $attribute, string $conditionField): string
    {
        // Extract all concrete indices from the attribute path.
        preg_match_all('/\.(\d+)(?:\.|$)/', $attribute, $matches);
        $indices = $matches[1];

        // Replace each * in the condition field with the corresponding index.
        $i = 0;

        return (string) preg_replace_callback('/\*/', static function () use ($indices, &$i) {
            return $indices[$i++] ?? '*';
        }, $conditionField);
    }

    /**
     * Build fast-check closures from compiled rule strings.
     * Returns closures keyed by wildcard pattern that accept a single value.
     *
     * Only string-only rules are eligible. Object rules, date comparisons,
     * cross-field references, distinct, size/between are skipped.
     *
     * @param  array<string, mixed>  $compiledRules  Compiled rules keyed by wildcard pattern
     * @return array<string, \Closure(mixed): bool>
     */
    public static function buildFastChecks(array $compiledRules): array
    {
        $checks = [];

        foreach ($compiledRules as $pattern => $rule) {
            if (! is_string($rule)) {
                continue;
            }

            $check = FastCheckCompiler::compile($rule);

            if ($check instanceof \Closure) {
                $checks[$pattern] = $check;
            }
        }

        return $checks;
    }
}
