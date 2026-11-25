<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Closure;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\NotIn;
use SanderMuller\FluentValidation\Internal\ConditionalEvaluationPhase;
use SanderMuller\FluentValidation\Internal\ConditionalVerdict;
use SanderMuller\FluentValidation\Internal\ExcludeConditionExtractor;
use SanderMuller\FluentValidation\Internal\ExcludedData;
use SanderMuller\FluentValidation\Internal\ValidatedRuleKeys;
use SanderMuller\FluentValidation\Internal\WildcardFastChecks;
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
 * references, etc.) fall through to parent::validateAttribute() transparently
 * — and, via {@see MemoizingValidator}, their rule-string parsing is memoized
 * across the whole worker, so the residual slow path is cheaper too.
 */
class OptimizedValidator extends MemoizingValidator
{
    private ?WildcardFastChecks $wildcardFastChecks = null;

    /** @var array<array-key, mixed> the rules the fast checks were built for */
    private array $fastCheckedRules = [];

    /** @var array<string, true> */
    private array $referencedAttributes = [];

    /** @var list<string> attributes an exclude rule removed before Laravel's loop */
    private array $excludedAttributes = [];

    /**
     * @param array<string, Closure(mixed): bool> $fastChecks
     * @param  array<string, string>  $attributePatternMap
     * @param  array<string, true>  $referencedAttributes  attributes whose rules a dependent rule reads; fast-checked only when no dependent is left
     * @param  array<string, array{0: Closure(mixed, array<string, mixed>): bool, 1: list<string>, 2: list<string>}>  $itemChecks  checks that read fields of the item of the attribute, with those fields, keyed by pattern
     * @param  array<string, list<string>|false>  $dependents  referenced attribute => the attributes whose rules read it, or false when not known
     */
    public function withFastChecks(array $fastChecks, array $attributePatternMap, array $referencedAttributes = [], array $itemChecks = [], array $dependents = []): static
    {
        $this->referencedAttributes = $referencedAttributes;
        // A rule added later, such as with sometimes(), leaves its attribute to Laravel.
        $this->fastCheckedRules = $this->rules;
        $this->wildcardFastChecks = WildcardFastChecks::of($fastChecks, $attributePatternMap, $referencedAttributes, $itemChecks, $dependents);

        return $this;
    }

    /**
     * The attributes an exclude rule removed before the validator was made.
     * Their data goes after validation, as in Laravel.
     *
     * @internal
     *
     * @param  list<string>  $attributes
     */
    public function withExcludedAttributes(array $attributes): static
    {
        $this->excludedAttributes = $attributes;

        return $this;
    }

    /**
     * Parse the data as Laravel does, which only replaces a `.` or `*` in a
     * key. Data without such a key comes back as it is, without a copy.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function parseData(array $data): array
    {
        return self::holdsKeyToReplace($data) ? parent::parseData($data) : $data;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function holdsKeyToReplace(array $data): bool
    {
        if (! array_is_list($data) && strpbrk(implode('', array_keys($data)), '.*') !== false) {
            return true;
        }

        foreach ($data as $value) {
            if (is_array($value) && self::holdsKeyToReplace($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Set plain string rules on concrete attributes, as setRules() would,
     * exploding each distinct rule string once. Expanded wildcard attributes
     * repeat the same few rule strings.
     *
     * @internal
     *
     * @param  array<string, string>  $rules  keys without `*` or escaped dots
     */
    public function setPlainRules(array $rules): static
    {
        $this->initialRules = $rules;
        $this->rules = [];
        $exploded = [];

        foreach ($rules as $attribute => $rule) {
            $this->rules[$attribute] = $exploded[$rule] ??= explode('|', $rule);
        }

        return $this;
    }

    /**
     * Set rules that are already exploded against this validator's data, as
     * setRules() would set them.
     *
     * @internal
     *
     * @param  array<array-key, mixed>  $initialRules
     * @param  array<array-key, mixed>  $rules
     * @param  array<array-key, mixed>  $implicitAttributes
     */
    public function setExplodedRules(array $initialRules, array $rules, array $implicitAttributes): static
    {
        $this->initialRules = $initialRules;
        $this->rules = $rules;
        $this->implicitAttributes = $implicitAttributes;

        return $this;
    }

    /**
     * Pre-validate fast-checkable attributes and pre-evaluate conditional
     * rules (exclude_unless/exclude_if) before the main validation loop,
     * removing passing/excluded attributes so Laravel never iterates them.
     */
    public function passes(): bool
    {
        $originalRules = $this->rules;
        // Closure (not [$this, 'getValue']) because parent::getValue() is protected.
        $getValue = fn (int|string $attribute): mixed => $this->getValue((string) $attribute);
        // Null when no rule changed since the fast checks were built, the common case.
        $checkedRules = $this->rules === $this->fastCheckedRules ? null : $this->fastCheckedRules;
        $removedRules = $this->wildcardFastChecks?->removePassing($this->rules, $checkedRules, $this->getData(), $getValue) ?? [];
        $this->runConditionalPhase($removedRules, $originalRules);
        $this->wildcardFastChecks?->removePassingReferenced($this->rules, $checkedRules, $removedRules, $getValue);

        if ($removedRules === [] && $this->rules === []) {
            $this->removeExcludedAttributes();

            return $this->finalizeWithoutParent();
        }

        $result = $this->excludedAttributes === [] ? parent::passes() : $this->passesBeforeAfterHooks();

        // Restore fast-checked rules in their original order, so validated()
        // returns their data in rule order. (Excluded rules are intentionally
        // NOT restored, nor are the rules below a path Laravel excluded.)
        if ($this->excludeAttributes !== []) {
            $removedRules = array_filter($removedRules, fn (string $attribute): bool => ! $this->shouldBeExcluded($attribute), ARRAY_FILTER_USE_KEY);
        }

        $kept = $this->rules + $removedRules;
        $this->rules = array_replace(array_intersect_key($originalRules, $kept), $kept);

        return $result;
    }

    /**
     * Gives the same result as Laravel's validated(), with less work per
     * rule key, see {@see ValidatedRuleKeys}.
     *
     * @return array<array-key, mixed>
     */
    public function validated(): array
    {
        if (! $this->messages instanceof MessageBag) {
            $this->passes();
        }

        if ($this->messages->isNotEmpty()) {
            return parent::validated();
        }

        $results = ValidatedRuleKeys::data($this->getRules(), $this->getData(), $this->excludeUnvalidatedArrayKeys);
        $placeholders = ['__dot__' . static::$placeholderHash, '__asterisk__' . static::$placeholderHash];

        return ValidatedRuleKeys::holdsKey($results, $placeholders) ? $this->replacePlaceholders($results) : $results;
    }

    /**
     * Laravel removes the data of the excluded attributes after its loop and
     * before the after hooks, so the data goes in a hook that runs first.
     */
    private function passesBeforeAfterHooks(): bool
    {
        $after = $this->after;
        $this->after = [$this->removeExcludedAttributes(...), ...$after];

        try {
            return parent::passes();
        } finally {
            $this->after = $after;
        }
    }

    /**
     * Laravel removes the data of an excluded attribute at the end of its
     * loop, so the other rules still read it. The rule is gone already, and
     * removeAttribute() would then remove a rule of the parent key by index.
     */
    private function removeExcludedAttributes(): void
    {
        ExcludedData::forget($this->data, $this->excludedAttributes);
    }

    /**
     * Phase 2: Conditional pre-evaluation and secondary fast-checks.
     * Delegates the indexing/evaluation/wildcard-resolution mechanics to
     * {@see ConditionalEvaluationPhase} so this validator stays focused on
     * orchestration and Laravel-validator state mutation.
     *
     * @param array<string, mixed> $removedRules
     * @param  array<array-key, mixed>  $originalRules  the rules in validation order
     */
    private function runConditionalPhase(array &$removedRules, array $originalRules): void
    {
        $phase = ConditionalEvaluationPhase::for($this);
        // Closure (not [$this, 'getValue']) because parent::getValue() is
        // protected — only invokable from inside this class scope.
        $getValue = fn (string $field): mixed => $this->getValue($field);
        $excluded = [];
        $sharedCheck = [];

        foreach ($phase->indexConditionalAttrs($this->rules) as $attribute => $tuples) {
            /** @var list<mixed> $rules */
            $rules = $this->rules[$attribute];

            $verdict = $phase->evaluate($attribute, $tuples, $getValue);

            if ($verdict === ConditionalVerdict::Exclude) {
                // Laravel runs the rules before an exclude rule, so it keeps those.
                // A dependent rule reads the rules of a referenced attribute until
                // Laravel removes them after its loop.
                if (! isset($this->referencedAttributes[$attribute]) && ! ExcludeConditionExtractor::followsOtherRule($rules)) {
                    $excluded[$attribute] = true;
                }

                continue;
            }

            // Deferred — the optimizer couldn't safely reproduce Laravel's
            // verdict, so leave the rule fully intact (do NOT fast-check it
            // away): the validator must decide both exclusion and validity, or
            // a field Laravel would exclude could leak into validated().
            if ($verdict === ConditionalVerdict::Defer) {
                continue;
            }

            // Not excluded — try fast-checking the remaining non-conditional
            // rules (e.g., the "string" part of ["exclude_unless:...", "string"]).
            if ($this->tryFastCheckRemaining($attribute, $rules, $originalRules, $sharedCheck)) {
                $removedRules[$attribute] = $rules;
                unset($this->rules[$attribute]);
            }
        }

        $this->removeExcluded($excluded, $removedRules, $originalRules);
    }

    /**
     * Removes the rules of the excluded attributes, and the rules below them
     * that Laravel skips, see {@see ConditionalEvaluationPhase::removableExclusions()}.
     *
     * @param  array<string, true>  $excluded
     * @param  array<string, mixed>  $removedRules
     * @param  array<array-key, mixed>  $originalRules
     */
    private function removeExcluded(array $excluded, array &$removedRules, array $originalRules): void
    {
        if ($excluded === []) {
            return;
        }

        [$removed, $below] = ConditionalEvaluationPhase::removableExclusions(array_intersect_key($originalRules, $this->rules + $removedRules), $excluded);

        foreach (array_keys($removed) as $attribute) {
            // Not added to removedRules, so it is absent from validated().
            unset($this->rules[$attribute]);
            $this->excludedAttributes[] = $attribute;
        }

        foreach (array_keys($below) as $attribute) {
            unset($this->rules[$attribute], $removedRules[$attribute]);
        }
    }

    /**
     * Compile and run a fast-check closure against the non-conditional
     * portion of an attribute's rules. Returns true when the closure
     * compiled and the value passed — caller may then drop the attribute.
     *
     * @param  list<mixed>  $rules
     * @param  array<array-key, mixed>  $originalRules
     * @param  array{}|array{0: list<mixed>, 1: (Closure(mixed): bool)|null}  $sharedCheck  the check of the previous rules, when it holds for every attribute with those rules
     */
    private function tryFastCheckRemaining(string $attribute, array $rules, array $originalRules, array &$sharedCheck): bool
    {
        if (isset($this->referencedAttributes[$attribute])) {
            return false;
        }

        if ($sharedCheck !== [] && $sharedCheck[0] === $rules) {
            $check = $sharedCheck[1];
        } else {
            $perAttribute = false;
            $remainingRule = $this->extractNonConditionalRule($attribute, $rules, $originalRules, $perAttribute);
            $check = $remainingRule === null ? null : FastCheckCompiler::compile($remainingRule);
            $sharedCheck = $perAttribute ? [] : [$rules, $check];
        }

        return $check instanceof Closure && $check($this->getValue($attribute));
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
     * @param  array<array-key, mixed>  $originalRules
     * @param  bool  $perAttribute  set when a part depends on the attribute
     */
    private function extractNonConditionalRule(string $attribute, array $rules, array $originalRules, bool &$perAttribute): ?string
    {
        $stringParts = [];

        foreach ($rules as $rule) {
            $part = $this->fastCheckPart($attribute, $rule, $originalRules, $perAttribute);

            if ($part === null) {
                return null;
            }

            if ($part !== '') {
                $stringParts[] = $part;
            }
        }

        return $stringParts !== [] ? implode('|', $stringParts) : null;
    }

    /**
     * One rule as a part of the fast check: the rule string, an empty string
     * for a rule the check leaves out, or null for a rule it cannot run.
     *
     * @param  array<array-key, mixed>  $originalRules
     */
    private function fastCheckPart(string $attribute, mixed $rule, array $originalRules, bool &$perAttribute): ?string
    {
        $name = is_string($rule) ? strstr($rule, ':', true) : false;

        if ($name !== false && isset(ConditionalEvaluationPhase::PRESENCE_RULES[$name])) {
            $rule = [$name, ...str_getcsv(substr((string) $rule, strlen($name) + 1), ',', '"', '\\')];
        }

        if (is_string($rule)) {
            return $rule;
        }

        // Stringable objects like Rule::in(), Rule::notIn() — stringify
        // them so FastCheckCompiler can handle them.
        if ($rule instanceof Stringable) {
            return (string) $rule;
        }

        if (! is_array($rule) || ! isset($rule[0]) || ! is_string($rule[0])) {
            return null;
        }

        // Exclude tuple — skip it (already evaluated).
        if (in_array($rule[0], ['exclude_unless', 'exclude_if'], true)) {
            return '';
        }

        $perAttribute = true;

        return ConditionalEvaluationPhase::presenceRule($rule[0], $attribute, array_slice($rule, 1), $this->data, $originalRules);
    }

    /**
     * A rule as one pipe string for the fast check: a string, or an array of
     * plain strings and `in` / `not_in` rule objects without a pipe inside.
     * Null for anything else.
     *
     * @internal
     */
    public static function plainRuleString(mixed $rule): ?string
    {
        if (! is_array($rule)) {
            return is_string($rule) ? $rule : null;
        }

        $parts = [];

        foreach ($rule as $part) {
            if (! is_string($part) && ! $part instanceof In && ! $part instanceof NotIn) {
                return null;
            }

            $part = (string) $part;

            // An array part is one rule: a pipe inside it is no separator.
            if (str_contains($part, '|')) {
                return null;
            }

            $parts[] = $part;
        }

        return implode('|', $parts);
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
            $rule = self::plainRuleString($rule);

            if ($rule === null) {
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
