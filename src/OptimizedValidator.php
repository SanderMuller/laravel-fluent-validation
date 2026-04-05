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
     * Pre-validate fast-checkable attributes and pre-evaluate conditional
     * rules (exclude_unless/exclude_if) before the main validation loop,
     * removing passing/excluded attributes so Laravel never iterates them.
     */
    public function passes(): bool
    {
        $removedRules = [];
        $hasFastChecks = $this->fastChecks !== [];

        foreach ($this->rules as $attribute => $rules) {
            // Fast-check: skip eligible wildcard attributes that pass PHP checks.
            if ($hasFastChecks) {
                $pattern = $this->attributePatternMap[$attribute] ?? null;

                if ($pattern !== null && isset($this->fastChecks[$pattern])) {
                    $value = $this->getValue($attribute);

                    if (($this->fastChecks[$pattern])($value)) {
                        $removedRules[$attribute] = $rules;
                        unset($this->rules[$attribute]);

                        continue;
                    }
                }
            }

            // Conditional pre-evaluation: check exclude_unless/exclude_if
            // conditions without going through Laravel's validation loop.
            if ($this->shouldPreExclude($attribute, $rules)) {
                // Don't add to removedRules — excluded fields should NOT
                // appear in validated() output.
                unset($this->rules[$attribute]);
            }
        }

        if ($removedRules === [] && $this->rules === []) {
            // Everything was removed — skip parent entirely.
            $this->messages = new \Illuminate\Support\MessageBag;
            $this->failedRules = [];

            foreach ($this->after as $after) {
                $after();
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
    private function shouldPreExclude(string $attribute, array $rules): bool
    {
        foreach ($rules as $rule) {
            if (! is_array($rule) || count($rule) < 3) {
                continue;
            }

            $action = $rule[0];
            $conditionField = $rule[1];
            $allowedValues = array_slice($rule, 2);

            if ($action !== 'exclude_unless' && $action !== 'exclude_if') {
                continue;
            }

            // Resolve wildcard in condition field using attribute's concrete index.
            if (str_contains($conditionField, '*')) {
                $conditionField = $this->resolveWildcard($attribute, $conditionField);
            }

            $actualValue = (string) ($this->getValue($conditionField) ?? '');

            if ($action === 'exclude_unless') {
                // Exclude UNLESS value is in the allowed list.
                if (! in_array($actualValue, $allowedValues, true)) {
                    return true;
                }
            } elseif ($action === 'exclude_if') {
                // Exclude IF value is in the list.
                if (in_array($actualValue, $allowedValues, true)) {
                    return true;
                }
            }
        }

        return false;
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

        return (string) preg_replace_callback('/\*/', function () use ($indices, &$i) {
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
