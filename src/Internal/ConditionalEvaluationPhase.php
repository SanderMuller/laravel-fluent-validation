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
    /** @var array<string, mixed> */
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
     * Evaluate pre-extracted conditional tuples for an attribute.
     *
     * Returns {@see ConditionalVerdict::Exclude} when a decidable tuple fires,
     * {@see ConditionalVerdict::Defer} when no tuple fires but at least one
     * couldn't be safely decided (so the validator must evaluate it), and
     * {@see ConditionalVerdict::NotExcluded} only when every tuple was decided
     * and none excluded.
     *
     * `$getValue` resolves a (possibly wildcard-replaced) field reference
     * to its value in the validator's data. Closure rather than callable
     * because `Validator::getValue()` is protected — the closure must be
     * constructed inside the validator subclass to capture scope.
     *
     * @param  list<array{action: string, field: string, values: list<mixed>}>  $tuples
     * @param  Closure(string): mixed  $getValue
     */
    public function evaluate(string $attribute, array $tuples, Closure $getValue): ConditionalVerdict
    {
        $deferred = false;

        foreach ($tuples as $tuple) {
            $field = $tuple['field'];

            if (str_contains($field, '*')) {
                $field = self::resolveWildcard($attribute, $field);

                // Unresolved wildcard (associative/non-numeric key) — can't pin
                // down the dependent path, so defer to Laravel's resolution.
                if (str_contains($field, '*')) {
                    $deferred = true;

                    continue;
                }
            }

            if (! array_key_exists($field, $this->valueCache)) {
                $this->valueCache[$field] = $getValue($field);
            }

            $rawValue = $this->valueCache[$field];

            // Only pre-decide on a plain string/numeric dependent, where a string
            // comparison matches Laravel. A null/bool/non-scalar dependent needs
            // Laravel's dependent-value coercion ('null'/'true'/'false'); defer
            // those to the validator instead of risking a divergent verdict.
            if (! is_string($rawValue) && ! is_int($rawValue) && ! is_float($rawValue)) {
                $deferred = true;

                continue;
            }

            $actualValue = (string) $rawValue;

            $excludes = $tuple['action'] === 'exclude_unless'
                ? ! in_array($actualValue, $tuple['values'], true)
                : in_array($actualValue, $tuple['values'], true);

            if ($excludes) {
                return ConditionalVerdict::Exclude;
            }
        }

        return $deferred ? ConditionalVerdict::Defer : ConditionalVerdict::NotExcluded;
    }

    /**
     * Replace wildcards in a condition field reference with the concrete key
     * at the SAME dot-position in the attribute path — mirroring how Laravel
     * resolves a dependent wildcard reference against the attribute under
     * validation. E.g. "interactions.*.type" against "interactions.5.style.top"
     * → "interactions.5.type", and "items.*.type" against "items.foo.rows.0.x"
     * → "items.foo.type" (associative keys handled, not just numeric indices).
     *
     * A `*` whose position has no corresponding attribute segment is left in
     * place, so callers can detect the unresolved wildcard and defer.
     *
     * Pure — exposed `static` so tests can pin it without instantiating.
     */
    public static function resolveWildcard(string $attribute, string $conditionField): string
    {
        if (! str_contains($conditionField, '*')) {
            return $conditionField;
        }

        $attributeSegments = explode('.', $attribute);
        $fieldSegments = explode('.', $conditionField);

        foreach ($fieldSegments as $i => $segment) {
            if ($segment === '*') {
                $fieldSegments[$i] = $attributeSegments[$i] ?? '*';
            }
        }

        return implode('.', $fieldSegments);
    }
}
