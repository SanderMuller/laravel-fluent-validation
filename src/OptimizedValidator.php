<?php

declare(strict_types=1);

namespace SanderMuller\FluentValidation;

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

    /** @var array<string, string> Expanded attribute → wildcard pattern lookup */
    private array $attributePatternMap = [];

    /** @var array<string, true> Attributes that passed the fast check — skip all their rules */
    private array $fastPassedAttributes = [];

    /** @var array<string, true> Attributes already evaluated for fast-check eligibility */
    private array $fastEvaluatedAttributes = [];

    /**
     * @param  array<string, \Closure(mixed): bool>  $fastChecks
     * @param  array<string, string>  $attributePatternMap
     */
    public function withFastChecks(array $fastChecks, array $attributePatternMap): static
    {
        $this->fastChecks = $fastChecks;
        $this->attributePatternMap = $attributePatternMap;

        return $this;
    }

    /**
     * Reset fast-check caches when validation restarts.
     * Parent resets MessageBag and failedRules; we must reset our caches too
     * so stale results from a previous passes() call don't leak through.
     */
    public function passes(): bool
    {
        $this->fastPassedAttributes = [];
        $this->fastEvaluatedAttributes = [];

        return parent::passes();
    }

    protected function validateAttribute($attribute, $rule)
    {
        // Already fast-checked and passed — skip all remaining rules for this attribute.
        if (isset($this->fastPassedAttributes[$attribute])) {
            return;
        }

        // First encounter for this attribute: try the fast check.
        if (! isset($this->fastEvaluatedAttributes[$attribute])) {
            $this->fastEvaluatedAttributes[$attribute] = true;

            $pattern = $this->attributePatternMap[$attribute] ?? null;

            if ($pattern !== null && isset($this->fastChecks[$pattern])) {
                $value = $this->getValue($attribute);

                if (($this->fastChecks[$pattern])($value)) {
                    $this->fastPassedAttributes[$attribute] = true;

                    return;
                }
            }
        }

        // Ineligible or failed fast check — let Laravel handle it.
        parent::validateAttribute($attribute, $rule);
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
