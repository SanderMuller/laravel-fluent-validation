<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Closure;
use Generator;
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
     * The fields a recognised rule's raw parameter names as dependencies.
     *
     * @return list<string>
     */
    abstract protected static function dependencyFields(string $rawParam): array;

    /**
     * Every field the recognised rules in the set depend on.
     *
     * @param  array<string, mixed>  $itemRules
     * @return list<string>
     */
    public static function dependencies(array $itemRules): array
    {
        $fields = [];

        foreach (self::recognisedRules($itemRules) as [, $rawParam]) {
            array_push($fields, ...static::dependencyFields($rawParam));
        }

        return $fields;
    }

    /**
     * `['required_if', 'type', 'image']` → `'required_if:type,image'` for a
     * recognised rule whose parameters are plain strings. A comma or quote
     * would parse differently in the string form, so such a tuple stays
     * with Laravel.
     */
    protected static function tupleAsString(mixed $rule): ?string
    {
        if (! is_array($rule) || ! isset($rule[0]) || ! is_string($rule[0]) || count($rule) < 2) {
            return null;
        }

        $parameters = [];

        foreach (array_slice($rule, 1) as $parameter) {
            if (! is_string($parameter) || str_contains($parameter, ',') || str_contains($parameter, '"')) {
                return null;
            }

            $parameters[] = $parameter;
        }

        $string = $rule[0] . ':' . implode(',', $parameters);

        return static::parse($string) !== null ? $string : null;
    }

    /**
     * Cheap pre-check: does any rule in the set carry a conditional this
     * reducer recognises? Used by callers to decide whether the per-item
     * reducer path must engage.
     *
     * @param  array<string, mixed>  $itemRules
     */
    public static function hasAny(array $itemRules): bool
    {
        return self::recognisedRules($itemRules)->valid();
    }

    /**
     * Each recognised rule as `[ruleName, rawParam]`. A string rule splits on
     * '|'; a list's elements are single rules, as Laravel parses them.
     *
     * @param  array<string, mixed>  $itemRules
     * @return Generator<int, array{0: string, 1: string}>
     */
    private static function recognisedRules(array $itemRules): Generator
    {
        foreach ($itemRules as $rule) {
            foreach (is_string($rule) ? explode('|', $rule) : (is_array($rule) ? $rule : []) as $part) {
                $part = static::tupleAsString($part) ?? $part;
                $parsed = is_string($part) ? static::parse($part) : null;

                if ($parsed !== null) {
                    yield $parsed;
                }
            }
        }
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

        return self::applyToList($rule, $rewriteOne);
    }

    /**
     * @param  array<int|string, mixed>  $rule
     * @param  Closure(string): ?string  $rewriteOne
     */
    private static function applyToList(array $rule, Closure $rewriteOne): mixed
    {
        $out = [];

        foreach ($rule as $sub) {
            $asString = static::tupleAsString($sub) ?? $sub;

            if (! is_string($asString)) {
                $out[] = $sub;

                continue;
            }

            $rewritten = $rewriteOne($asString);

            // Unchanged keeps the original element: a tuple whose custom
            // message must keep the rule stays a tuple.
            if ($rewritten !== null) {
                $out[] = $rewritten === $asString ? $sub : $rewritten;
            }
        }

        if ($out === array_values($rule)) {
            return $rule;
        }

        // A rewritten list of plain strings joins so it can fast-check.
        foreach ($out as $part) {
            if (! is_string($part) || str_contains($part, '|')) {
                return $out;
            }
        }

        return implode('|', $out);
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
}
