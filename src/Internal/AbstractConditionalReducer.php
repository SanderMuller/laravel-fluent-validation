<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Closure;
use Illuminate\Support\Arr;

/**
 * Shared template for per-item conditional-rule reducers. Subclasses
 * (`PresenceConditionalReducer`, `ValueConditionalReducer`) implement the
 * rule-family-specific `parse()` and `apply()` entry points; this base
 * owns the dispatch shape (`hasAny()`, `applyTemplate()`) and the custom
 * message lookup that both subclasses agree on.
 *
 * Static-by-design: subclasses' public entry points are static, so the
 * base uses late static binding (`static::parse()`) rather than instance
 * dispatch — avoids object allocation across the per-item hot path in
 * `ItemRuleCompiler::buildItemRules()`.
 *
 * @internal
 */
abstract class AbstractConditionalReducer
{
    /**
     * Parse a single rule string into `[ruleName, rawParam]` when it matches
     * one of the family's recognised conditionals. Subclasses define the
     * recognised set; longest-prefix order is each subclass's responsibility.
     *
     * @return array{0: string, 1: string}|null
     */
    abstract protected static function parse(string $rule): ?array;

    /**
     * Cheap pre-check: does any rule in the set carry a conditional this
     * reducer recognises? Used by callers to decide whether the per-item
     * reducer path must engage.
     *
     * @param  array<string, mixed>  $itemRules
     */
    public static function hasAny(array $itemRules): bool
    {
        foreach ($itemRules as $rule) {
            if (is_string($rule) && static::stringContainsRule($rule)) {
                return true;
            }

            if (is_array($rule)) {
                foreach ($rule as $sub) {
                    if (is_string($sub) && static::stringContainsRule($sub)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Walk a single rule value (string with optional pipe-joined parts, or
     * a list of rules) and dispatch each string part to `$rewriteOne`.
     * Returns the rewritten rule in its original shape.
     *
     * Subclasses' `apply()` methods bind their per-call context (field,
     * itemData, etc.) into a closure and hand it here.
     *
     * @param  Closure(string): ?string  $rewriteOne
     */
    protected static function applyTemplate(mixed $rule, Closure $rewriteOne): mixed
    {
        if (is_string($rule)) {
            if (! str_contains($rule, '|')) {
                $rewritten = $rewriteOne($rule);

                return $rewritten ?? '';
            }

            $parts = [];
            foreach (explode('|', $rule) as $part) {
                $rewritten = $rewriteOne($part);
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
                $rewritten = $rewriteOne($sub);
                if ($rewritten !== null) {
                    $out[] = $rewritten;
                }

                continue;
            }

            $out[] = $sub;
        }

        return $out;
    }

    /**
     * Detect custom user-supplied messages for the original rule name so the
     * rewrite path doesn't bypass a `{field}.{rule}`-style override at
     * message-formatting time. Identical across all conditional reducers.
     *
     * @param  array<string, string>  $itemMessages
     */
    protected static function hasCustomMessage(string $field, string $ruleName, array $itemMessages): bool
    {
        // Inside wildcard-item reduction, `$field` is the item-local key
        // (e.g. `postcode`), but user-supplied messages typically come in
        // via the original wildcard form (`addresses.*.postcode.required_without`).
        // Match any message whose key equals `{field}.{rule}` or ends with
        // `.{field}.{rule}` — covers bare-field, wildcard-prefixed, and
        // any parent-prefixed variant.
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

            // Wildcard-keyed translator overrides
            // (`validation.custom.addresses.*.postcode.required_without`)
            // are resolved via Laravel's `Str::is()` against the flattened
            // `validation.custom` namespace — mirror Validator::getCustomMessageFromTranslator.
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

    /**
     * Pipe-aware wrapper around `parse()` for `hasAny()`. Returns true when
     * any pipe-segment matches a recognised rule.
     */
    protected static function stringContainsRule(string $rule): bool
    {
        if (str_contains($rule, '|')) {
            foreach (explode('|', $rule) as $part) {
                if (static::parse($part) !== null) {
                    return true;
                }
            }

            return false;
        }

        return static::parse($rule) !== null;
    }
}
