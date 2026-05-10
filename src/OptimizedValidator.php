<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\Validator;
use SanderMuller\FluentValidation\Internal\ConditionalEvaluationPhase;
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
     * Delegates the indexing/evaluation/wildcard-resolution mechanics to
     * {@see ConditionalEvaluationPhase} so this validator stays focused on
     * orchestration and Laravel-validator state mutation.
     *
     * @param array<string, mixed> $removedRules
     */
    private function runConditionalPhase(array &$removedRules): void
    {
        $phase = new ConditionalEvaluationPhase();
        // Closure (not [$this, 'getValue']) because parent::getValue() is
        // protected — only invokable from inside this class scope.
        $getValue = fn (string $field): mixed => $this->getValue($field);

        foreach ($phase->indexConditionalAttrs($this->rules) as $attribute => $tuples) {
            /** @var list<mixed> $rules */
            $rules = $this->rules[$attribute];

            if ($phase->evaluate($attribute, $tuples, $getValue)) {
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

        return $check($this->getValue($attribute));
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
