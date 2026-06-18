<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Illuminate\Support\Str;

/**
 * Decides whether a dependent field's value matches a value-conditional rule's
 * parameter list, reproducing Laravel's dependent-value coercion exactly.
 *
 * Shared by every value-conditional family that compares a dependent against a
 * value list — `required_if`/`required_unless`/`prohibited_if`/`prohibited_unless`
 * (via {@see ValueConditionalReducer}) and `exclude_if`/`exclude_unless` (via
 * {@see ItemRuleCompiler}). Centralising it keeps the per-item reducers and the
 * exclude pre-evaluation behaviourally identical to native Laravel, instead of
 * each path hand-rolling a string comparison that diverges on null/bool.
 *
 * @internal Not part of the public API.
 */
final class ConditionalValueMatcher
{
    /**
     * Does the dependent at `$depPath` match `$rawValues` under Laravel's
     * `validateRequiredIf`/`…Unless` comparison semantics?
     *
     * @param  list<?string>         $rawValues  The rule's value parameters.
     * @param  array<string, mixed>  $itemData
     * @param  array<string, mixed>  $itemRules  Item-scoped rules, for the `boolean` declaration check.
     */
    public static function matches(string $depPath, array $rawValues, array $itemData, array $itemRules): bool
    {
        return self::matchesValue(
            data_get($itemData, $depPath),
            $rawValues,
            self::dependentHasBooleanRule($depPath, $itemRules),
        );
    }

    /**
     * Same comparison as {@see matches()} but against an already-resolved
     * dependent value — for callers that resolve the value themselves (e.g.
     * through a validator's protected `getValue()`), so they don't re-resolve
     * via `data_get`.
     *
     * @param  list<?string>  $rawValues
     */
    public static function matchesValue(mixed $other, array $rawValues, bool $dependentHasBooleanRule = false): bool
    {
        $values = self::convertValues($rawValues, $other, $dependentHasBooleanRule);

        // Laravel's `in_array($other, $values, is_bool($other) || is_null($other))`
        // uses strict mode only for bool/null and loose mode otherwise (so
        // numeric-string `"1"` matches int `1`). `phpstan-strict-rules`
        // disallows loose `in_array`, so hand-roll the scalar loose match
        // via string coercion — covers the scalar grid Laravel's loose
        // comparison hits in practice. Non-scalar `$other` falls back to
        // strict match (atypical for value-conditional deps).
        return (is_bool($other) || is_null($other) || ! is_scalar($other))
            ? in_array($other, $values, true)
            : self::scalarLooseIn($other, $values);
    }

    /**
     * Stand-in for PHP's loose `in_array($other, $values, false)` with `$other`
     * a non-bool/non-null scalar — phpstan-strict-rules forbids loose `in_array`,
     * so the cases are hand-rolled:
     *
     * - a bool value (rule param converted from `'true'`/`'false'`) compares by
     *   truthiness — `'0'`/`''` ⟺ false, other non-empty strings / non-zero
     *   numbers ⟺ true — matching PHP's `'0' == false` / `'1' == true`. This is
     *   the boolean-input case (`flag` declared `boolean`, request sends `'0'`).
     * - any other scalar compares by string coercion, covering numeric-string ↔
     *   numeric (`'1'` ↔ `1`).
     *
     * @param  list<mixed>  $values
     */
    private static function scalarLooseIn(int|float|string $other, array $values): bool
    {
        $otherStr = (string) $other;

        foreach ($values as $v) {
            $matched = is_bool($v)
                ? (bool) $other === $v
                : is_scalar($v) && (string) $v === $otherStr;

            if ($matched) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply Laravel's `parseDependentRuleParameters` value transforms:
     * boolean conversion when the dep has a `boolean` rule or the resolved
     * value is already a bool; null conversion when the resolved value is
     * null. Order matters — bool first, then null.
     *
     * @param  list<mixed>  $values
     * @return list<mixed>
     */
    private static function convertValues(array $values, mixed $other, bool $dependentHasBooleanRule): array
    {
        if (is_bool($other) || $dependentHasBooleanRule) {
            $values = array_map(static fn (mixed $v): mixed => match ($v) {
                'true' => true,
                'false' => false,
                default => $v,
            }, $values);
        }

        if (is_null($other)) {
            return array_map(
                static fn (mixed $v): mixed => is_string($v) && Str::lower($v) === 'null' ? null : $v,
                $values,
            );
        }

        return $values;
    }

    /**
     * Does the dependent field's rule set contain a `boolean` marker?
     * Best-effort check against the item-scoped rule set — mirrors Laravel's
     * `shouldConvertToBoolean` which reads `$this->rules[$parameter]`.
     *
     * @param  array<string, mixed>  $itemRules
     */
    private static function dependentHasBooleanRule(string $depPath, array $itemRules): bool
    {
        $rules = $itemRules[$depPath] ?? null;

        if (is_string($rules)) {
            return in_array('boolean', explode('|', $rules), true);
        }

        if (! is_array($rules)) {
            return false;
        }

        return in_array('boolean', $rules, true);
    }
}
