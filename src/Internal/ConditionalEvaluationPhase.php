<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Closure;

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
     * @return array<string, list<array{action: string, field: string, values: list<string>}>>
     */
    public function indexConditionalAttrs(array $rules): array
    {
        $map = [];

        foreach ($rules as $attribute => $attributeRules) {
            if (! is_string($attribute)) {
                continue;
            }

            // Recognise tuple AND string/pipe-joined exclude_* forms (the fluent
            // `->excludeUnless()` API compiles to the string form).
            $tuples = ExcludeConditionExtractor::extract($attributeRules);

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
     * @param  list<array{action: string, field: string, values: list<string>}>  $tuples
     * @param  Closure(string): mixed  $getValue
     */
    public function evaluate(string $attribute, array $tuples, Closure $getValue): ConditionalVerdict
    {
        $deferred = false;

        foreach ($tuples as $tuple) {
            $field = $tuple['field'];

            if (str_contains($field, '*')) {
                $field = self::resolveWildcard($attribute, $field);

                // Unresolved wildcard (the dep position has no matching segment)
                // — can't pin down the dependent path, so defer to Laravel.
                if (str_contains($field, '*')) {
                    $deferred = true;

                    continue;
                }
            }

            if (! array_key_exists($field, $this->valueCache)) {
                $this->valueCache[$field] = $getValue($field);
            }

            $value = $this->valueCache[$field];

            // exclude_if is inactive when the dependent is absent. getValue()
            // can't tell a missing field from an explicit null, so defer null
            // exclude_if to the validator (which distinguishes them and is
            // authoritative). exclude_unless has no such presence short-circuit.
            if ($tuple['action'] === 'exclude_if' && $value === null) {
                $deferred = true;

                continue;
            }

            // Match Laravel's dependent-value coercion (null/bool/loose scalar)
            // via the shared matcher, against the value resolved through the
            // validator's getValue() closure.
            $match = ConditionalValueMatcher::matchesValue($value, $tuple['values']);
            $excludes = $tuple['action'] === 'exclude_unless' ? ! $match : $match;

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
