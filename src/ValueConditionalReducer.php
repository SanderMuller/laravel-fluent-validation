<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Per-item pre-evaluation of Laravel's value-conditional rules
 * (`required_if` / `required_unless` / `prohibited_if` / `prohibited_unless`).
 * Given an item and its rule set, decides for each rule whether to drop it
 * (inactive), rewrite it to plain `required` / `prohibited` (active without a
 * custom message override — unlocks fast-check), or leave it intact (active
 * with an override — the original rule name must survive so translator
 * lookups still fire).
 *
 * Mirrors `parseDependentRuleParameters` + `validateRequiredIf` / `…Unless`
 * / `validateProhibitedIf` / `…Unless` from Laravel's `ValidatesAttributes`.
 *
 * @internal Implementation detail of `RuleSet`. Not part of the public API.
 */
final class ValueConditionalReducer
{
    /**
     * Longest-prefix-first order for `str_starts_with` disambiguation so
     * `required_unless:x,y` resolves to `required_unless` not `required`.
     *
     * @var list<string>
     */
    private const RULE_NAMES = [
        'required_unless',
        'required_if',
        'prohibited_unless',
        'prohibited_if',
    ];

    /**
     * Rewrite target per rule name when the rule activates and no custom
     * message override is present.
     *
     * @var array<string, string>
     */
    private const REWRITE_TARGET = [
        'required_if' => 'required',
        'required_unless' => 'required',
        'prohibited_if' => 'prohibited',
        'prohibited_unless' => 'prohibited',
    ];

    /**
     * Cheap pre-check: does any rule in the set contain a value conditional?
     * Used by `ItemValidator` to decide whether the per-item reducer path
     * must engage (and whether sibling-dependent dispatch caching is safe).
     *
     * @param  array<string, mixed>  $itemRules
     */
    public static function hasAny(array $itemRules): bool
    {
        foreach ($itemRules as $rule) {
            if (is_string($rule) && self::stringContainsValueRule($rule)) {
                return true;
            }

            if (is_array($rule)) {
                foreach ($rule as $sub) {
                    if (is_string($sub) && self::stringContainsValueRule($sub)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Rewrite value-conditional rules for a single field against item data.
     * Handles pipe-joined strings and list-of-rules shape.
     *
     * `$itemRules` is the full rule set for the item — needed so the reducer
     * can check whether the dependent field has a `boolean` rule, mirroring
     * Laravel's `shouldConvertToBoolean`.
     *
     * @param  array<string, mixed>   $itemData
     * @param  array<string, string>  $itemMessages
     * @param  array<string, mixed>   $itemRules
     */
    public static function apply(mixed $rule, string $field, array $itemData, array $itemMessages, array $itemRules): mixed
    {
        if (is_string($rule)) {
            if (! str_contains($rule, '|')) {
                $rewritten = self::rewriteOne($rule, $field, $itemData, $itemMessages, $itemRules);

                return $rewritten ?? '';
            }

            $parts = [];
            foreach (explode('|', $rule) as $part) {
                $rewritten = self::rewriteOne($part, $field, $itemData, $itemMessages, $itemRules);
                if ($rewritten !== null) {
                    $parts[] = $rewritten;
                }
            }

            return implode('|', $parts);
        }

        if (! is_array($rule)) {
            return $rule;
        }

        $out = [];
        foreach ($rule as $sub) {
            if (is_string($sub)) {
                $rewritten = self::rewriteOne($sub, $field, $itemData, $itemMessages, $itemRules);
                if ($rewritten !== null) {
                    $out[] = $rewritten;
                }

                continue;
            }

            $out[] = $sub;
        }

        return $out;
    }

    private static function stringContainsValueRule(string $rule): bool
    {
        if (str_contains($rule, '|')) {
            foreach (explode('|', $rule) as $part) {
                if (self::parse($part) !== null) {
                    return true;
                }
            }

            return false;
        }

        return self::parse($rule) !== null;
    }

    /**
     * Return `[ruleName, rawParam]` when `$rule` is one of the recognized
     * value conditionals, otherwise null.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function parse(string $rule): ?array
    {
        foreach (self::RULE_NAMES as $name) {
            $prefix = $name . ':';
            if (str_starts_with($rule, $prefix)) {
                return [$name, substr($rule, strlen($prefix))];
            }
        }

        return null;
    }

    /**
     * Rewrite one rule string: drop when the conditional is inactive,
     * collapse to plain `required` / `prohibited` when active and no custom
     * message overrides the original rule name, otherwise return unchanged.
     *
     * @param  array<string, mixed>   $itemData
     * @param  array<string, string>  $itemMessages
     * @param  array<string, mixed>   $itemRules
     */
    private static function rewriteOne(string $rule, string $field, array $itemData, array $itemMessages, array $itemRules): ?string
    {
        $parsed = self::parse($rule);
        if ($parsed === null) {
            return $rule;
        }

        [$ruleName, $rawParam] = $parsed;

        if ($rawParam === '') {
            return $rule;
        }

        $params = str_getcsv($rawParam, ',', '"', '\\');

        // Requires `field,value` minimum — both the dependent path and at
        // least one value slot. Malformed rules fall through to Laravel.
        if (count($params) < 2 || ! is_string($params[0]) || $params[0] === '') {
            return $rule;
        }

        $depPath = $params[0];

        // `validateRequiredIf` short-circuits with `! Arr::has($this->data, $parameters[0])`
        // BEFORE `parseDependentRuleParameters` runs — so `required_if:absent,anything`
        // is inactive. The other three rules skip this check and fall through to
        // null-conversion semantics.
        if ($ruleName === 'required_if' && ! Arr::has($itemData, $depPath)) {
            return null;
        }

        $other = data_get($itemData, $depPath);
        /** @var list<?string> $rawValues */
        $rawValues = array_slice($params, 1);
        $values = self::convertValues($rawValues, $depPath, $other, $itemRules);

        // Laravel's `in_array($other, $values, is_bool($other) || is_null($other))`
        // uses strict mode only for bool/null and loose mode otherwise (so
        // numeric-string `"1"` matches int `1`). `phpstan-strict-rules`
        // disallows loose `in_array`, so hand-roll the scalar loose match
        // via string coercion — covers the scalar grid Laravel's loose
        // comparison hits in practice. Non-scalar `$other` falls back to
        // strict match (atypical for value-conditional deps).
        $match = (is_bool($other) || is_null($other) || ! is_scalar($other))
            ? in_array($other, $values, true)
            : self::scalarLooseIn($other, $values);

        $active = match ($ruleName) {
            'required_if', 'prohibited_if' => $match,
            'required_unless', 'prohibited_unless' => ! $match,
            default => false,
        };

        if (! $active) {
            return null;
        }

        if (self::hasCustomMessage($field, $ruleName, $itemMessages)) {
            return $rule;
        }

        return self::REWRITE_TARGET[$ruleName];
    }

    /**
     * Scalar-only stand-in for PHP's loose `in_array` — string-coerces both
     * sides and strict-compares. Covers the numeric-string ↔ numeric loose
     * match Laravel's `in_array(..., false)` relies on here.
     *
     * @param  list<mixed>  $values
     */
    private static function scalarLooseIn(int|float|string $other, array $values): bool
    {
        $otherStr = (string) $other;

        foreach ($values as $v) {
            if (is_scalar($v) && (string) $v === $otherStr) {
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
     * @param  array<string, mixed>  $itemRules
     * @return list<mixed>
     */
    private static function convertValues(array $values, string $depPath, mixed $other, array $itemRules): array
    {
        if (is_bool($other) || self::dependentHasBooleanRule($depPath, $itemRules)) {
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

    /**
     * Detect custom user-supplied messages for the original rule name so the
     * rewrite path doesn't bypass a `{field}.required_if`-style override at
     * message-formatting time. Mirrors `PresenceConditionalReducer` so both
     * reducers agree on override detection.
     *
     * @param  array<string, string>  $itemMessages
     */
    private static function hasCustomMessage(string $field, string $ruleName, array $itemMessages): bool
    {
        $suffix = '.' . $field . '.' . $ruleName;
        $exactKey = $field . '.' . $ruleName;
        foreach (array_keys($itemMessages) as $key) {
            $key = (string) $key;
            if ($key === $exactKey || str_ends_with($key, $suffix)) {
                return true;
            }
        }

        if (function_exists('trans')) {
            $translatorKey = 'validation.custom.' . $field . '.' . $ruleName;
            $translated = trans($translatorKey);
            if (is_string($translated) && $translated !== $translatorKey) {
                return true;
            }

            $custom = trans('validation.custom');
            if (is_array($custom)) {
                $shortKey = $field . '.' . $ruleName;
                foreach (array_keys(Arr::dot($custom)) as $customKey) {
                    $customKey = (string) $customKey;
                    if ($customKey === $shortKey || str_ends_with($customKey, '.' . $shortKey)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
