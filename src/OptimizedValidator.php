<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\Validator;
use Stringable;

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
    /** @var array<string, Closure(mixed): bool> Fast checks keyed by wildcard pattern */
    private array $fastChecks = [];

    /** @var array<string, list<string>> Pattern → expanded attributes (pre-grouped) */
    private array $fastCheckGroups = [];

    /**
     * @param array<string, Closure(mixed): bool> $fastChecks
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
        $this->conditionValueCache = [];
        $removedRules = $this->runFastCheckPhase();
        $this->runConditionalPhase($removedRules);

        if ($removedRules === [] && $this->rules === []) {
            return $this->finalizeWithoutParent();
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
     * Phase 1: Fast-check wildcard attributes by pattern. Iterates per-pattern
     * with all values for that pattern, improving cache locality and reducing
     * closure dispatch overhead.
     *
     * @return array<string, mixed> Removed rules keyed by attribute.
     */
    private function runFastCheckPhase(): array
    {
        if ($this->fastCheckGroups === []) {
            return [];
        }

        $removedRules = [];
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

        return $removedRules;
    }

    /**
     * Phase 2: Conditional pre-evaluation and secondary fast-checks.
     * Pre-extracts attributes carrying exclude_unless/exclude_if tuples in a
     * single pass so we skip the per-attribute foreach search that used to
     * fire for every rule, conditional or not.
     *
     * @param array<string, mixed> $removedRules
     */
    private function runConditionalPhase(array &$removedRules): void
    {
        foreach ($this->indexConditionalAttrs() as $attribute => $tuples) {
            /** @var list<mixed> $rules */
            $rules = $this->rules[$attribute];

            if ($this->evaluateExtractedConditionals($attribute, $tuples)) {
                // Excluded — don't add to removedRules so it's absent from validated().
                unset($this->rules[$attribute]);

                continue;
            }

            // Condition present but did not exclude — try fast-checking the
            // remaining non-conditional rules (e.g., the "string" part of
            // ["exclude_unless:...", "string"]).
            if ($this->fastChecks !== [] && $this->tryFastCheckRemaining($attribute, $rules)) {
                $removedRules[$attribute] = $rules;
                unset($this->rules[$attribute]);
            }
        }
    }

    /**
     * Compile and run a fast-check closure against the non-conditional
     * portion of an attribute's rules. Returns true when the closure
     * compiled and the value passed — caller may then drop the attribute.
     *
     * @param  list<mixed>  $rules
     */
    private function tryFastCheckRemaining(string $attribute, array $rules): bool
    {
        $remainingRule = $this->extractNonConditionalRule($rules);

        if ($remainingRule === null) {
            return false;
        }

        $check = FastCheckCompiler::compile($remainingRule);

        if (! $check instanceof Closure) {
            return false;
        }

        return (bool) $check($this->getValue($attribute));
    }

    /**
     * Short-circuit when both `passes()` phases drained every rule:
     * skip parent's full validation loop and run any registered
     * `after()` callbacks against an empty MessageBag.
     */
    private function finalizeWithoutParent(): bool
    {
        $this->messages = new MessageBag();
        $this->failedRules = [];

        foreach ($this->after as $after) {
            if (is_callable($after)) {
                $after();
            }
        }

        return $this->messages->isEmpty();
    }

    /** @var array<string, string> */
    private array $conditionValueCache = [];

    /**
     * Build a flat map of attributes that carry exclude_unless / exclude_if
     * tuples, paired with the parsed tuple data. Run once per `passes()`
     * call so Phase 2 can skip the per-attribute tuple search that used to
     * fire on every rule, conditional or not.
     *
     * @return array<string, list<array{action: string, field: string, values: list<mixed>}>>
     */
    private function indexConditionalAttrs(): array
    {
        $map = [];

        foreach ($this->rules as $attribute => $attributeRules) {
            if (! is_string($attribute) || ! is_array($attributeRules)) {
                continue;
            }

            $tuples = [];

            foreach ($attributeRules as $rule) {
                if (! is_array($rule) || count($rule) < 3) {
                    continue;
                }

                $action = $rule[0];

                if ($action !== 'exclude_unless' && $action !== 'exclude_if') {
                    continue;
                }

                $field = $rule[1];

                if (! is_string($field)) {
                    continue;
                }

                $tuples[] = [
                    'action' => $action,
                    'field' => $field,
                    'values' => array_values(array_slice($rule, 2)),
                ];
            }

            if ($tuples !== []) {
                $map[$attribute] = $tuples;
            }
        }

        return $map;
    }

    /**
     * Evaluate pre-extracted conditional tuples for an attribute. Returns
     * true if the attribute should be excluded. False otherwise — the
     * caller knows at least one condition exists, so the tri-state null
     * the legacy `evaluateConditionals` returned is no longer needed.
     *
     * @param list<array{action: string, field: string, values: list<mixed>}> $tuples
     */
    private function evaluateExtractedConditionals(string $attribute, array $tuples): bool
    {
        foreach ($tuples as $tuple) {
            $field = $tuple['field'];

            if (str_contains($field, '*')) {
                $field = $this->resolveWildcard($attribute, $field);
            }

            if (! isset($this->conditionValueCache[$field])) {
                $rawValue = $this->getValue($field);
                $this->conditionValueCache[$field] = is_scalar($rawValue) ? (string) $rawValue : '';
            }

            $actualValue = $this->conditionValueCache[$field];

            if ($tuple['action'] === 'exclude_unless' && ! in_array($actualValue, $tuple['values'], true)) {
                return true;
            }

            if ($tuple['action'] === 'exclude_if' && in_array($actualValue, $tuple['values'], true)) {
                return true;
            }
        }

        return false;
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
            } elseif ($rule instanceof Stringable) {
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
     * @return array<string, Closure(mixed): bool>
     */
    public static function buildFastChecks(array $compiledRules): array
    {
        $checks = [];

        foreach ($compiledRules as $pattern => $rule) {
            if (! is_string($rule)) {
                continue;
            }

            $check = FastCheckCompiler::compile($rule);

            if ($check instanceof Closure) {
                $checks[$pattern] = $check;
            }
        }

        return $checks;
    }
}
