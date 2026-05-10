<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Closure;
use SanderMuller\FluentValidation\OptimizedValidator;

/**
 * Pre-evaluates `exclude_unless` / `exclude_if` rule tuples against a
 * validator's data so passing/excluded attributes can be dropped before
 * Laravel's main validation loop runs.
 *
 * Owned by {@see OptimizedValidator}; one
 * instance per `passes()` call. Caches stringified condition values across
 * the call so multiple rules referencing the same field share one lookup.
 *
 * @internal
 */
final class ConditionalEvaluationPhase
{
    /** @var array<string, string> */
    private array $valueCache = [];

    /**
     * Build a flat map of attributes that carry exclude_unless / exclude_if
     * tuples, paired with the parsed tuple data. Pure — depends only on the
     * rule shape. Accepts the Validator's untyped `$rules` array directly;
     * non-string keys are filtered inside.
     *
     * @param  array<array-key, mixed>  $rules
     * @return array<string, list<array{action: string, field: string, values: list<mixed>}>>
     */
    public function indexConditionalAttrs(array $rules): array
    {
        $map = [];

        foreach ($rules as $attribute => $attributeRules) {
            if (! is_string($attribute)) {
                continue;
            }

            if (! is_array($attributeRules)) {
                continue;
            }

            $tuples = [];

            foreach ($attributeRules as $rule) {
                if (! is_array($rule)) {
                    continue;
                }

                if (count($rule) < 3) {
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
     * true if the attribute should be excluded.
     *
     * `$getValue` resolves a (possibly wildcard-replaced) field reference
     * to its value in the validator's data. Closure rather than callable
     * because `Validator::getValue()` is protected — the closure must be
     * constructed inside the validator subclass to capture scope.
     *
     * @param  list<array{action: string, field: string, values: list<mixed>}>  $tuples
     * @param  Closure(string): mixed  $getValue
     */
    public function evaluate(string $attribute, array $tuples, Closure $getValue): bool
    {
        foreach ($tuples as $tuple) {
            $field = $tuple['field'];

            if (str_contains($field, '*')) {
                $field = self::resolveWildcard($attribute, $field);
            }

            if (! isset($this->valueCache[$field])) {
                $rawValue = $getValue($field);
                $this->valueCache[$field] = is_scalar($rawValue) ? (string) $rawValue : '';
            }

            $actualValue = $this->valueCache[$field];

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
     * Replace wildcards in a condition field reference with concrete indices
     * from the attribute name. E.g., for attribute "interactions.5.style.top"
     * and condition field "interactions.*.type", returns "interactions.5.type".
     *
     * Pure — exposed `static` so tests can pin it without instantiating.
     */
    public static function resolveWildcard(string $attribute, string $conditionField): string
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
}
