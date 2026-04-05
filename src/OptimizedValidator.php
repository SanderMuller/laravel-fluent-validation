<?php declare(strict_types=1);

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
     * Run fast checks before the main validation loop, removing passing
     * attributes entirely so Laravel never iterates their rules.
     */
    public function passes(): bool
    {
        if ($this->fastChecks !== []) {
            // Pre-validate fast-checkable attributes and remove passing ones
            // from $this->rules so the parent loop never sees them.
            $removedRules = [];

            foreach ($this->rules as $attribute => $rules) {
                $pattern = $this->attributePatternMap[$attribute] ?? null;

                if ($pattern !== null && isset($this->fastChecks[$pattern])) {
                    $value = $this->getValue($attribute);

                    if (($this->fastChecks[$pattern])($value)) {
                        $removedRules[$attribute] = $rules;
                        unset($this->rules[$attribute]);
                    }
                }
            }

            // Run parent passes() on the reduced rule set.
            $result = parent::passes();

            // Restore removed rules so validated() can still return their data.
            foreach ($removedRules as $attribute => $rules) {
                $this->rules[$attribute] = $rules;
            }

            return $result;
        }

        return parent::passes();
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
