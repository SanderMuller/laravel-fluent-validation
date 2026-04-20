<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;

/**
 * Per-item pre-evaluation of Laravel's presence-conditional rules
 * (`required_with` / `required_without` / `required_with_all` /
 * `required_without_all`). Given an item and its rule set, decides
 * for each rule whether to drop it (inactive), rewrite it to plain
 * `required` (active without a custom message override — unlocks
 * fast-check), or leave it intact (active with an override — the
 * original rule name must survive so translator lookups still fire).
 *
 * Moved out of `RuleSet` as part of the 1.16 baseline-reduction sprint
 * to reduce `RuleSet`'s cognitive complexity.
 *
 * @internal Implementation detail of `RuleSet`. Not part of the public API.
 */
final class PresenceConditionalReducer
{
    /** @var list<string> Ordered longest-prefix-first for `str_starts_with` disambiguation. */
    private const RULE_NAMES = [
        'required_without_all',
        'required_with_all',
        'required_without',
        'required_with',
    ];

    /**
     * Cheap pre-check: does any rule in the set contain a presence
     * conditional? Used by `RuleSet::validateItems` to decide whether
     * the per-item reducer path must engage.
     *
     * @param  array<string, mixed>  $itemRules
     */
    public static function hasAny(array $itemRules): bool
    {
        foreach ($itemRules as $rule) {
            if (is_string($rule) && self::stringContainsPresenceRule($rule)) {
                return true;
            }

            if (is_array($rule)) {
                foreach ($rule as $sub) {
                    if (is_string($sub) && self::stringContainsPresenceRule($sub)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Rewrite presence-conditional rules for a single field against
     * item data. Handles pipe-joined strings and list-of-rules shape.
     *
     * @param  array<string, mixed>   $itemData
     * @param  array<string, string>  $itemMessages
     */
    public static function apply(mixed $rule, string $field, array $itemData, array $itemMessages): mixed
    {
        if (is_string($rule)) {
            if (! str_contains($rule, '|')) {
                $rewritten = self::rewriteOne($rule, $field, $itemData, $itemMessages);

                return $rewritten ?? '';
            }

            $parts = [];
            foreach (explode('|', $rule) as $part) {
                $rewritten = self::rewriteOne($part, $field, $itemData, $itemMessages);
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
                $rewritten = self::rewriteOne($sub, $field, $itemData, $itemMessages);
                if ($rewritten !== null) {
                    $out[] = $rewritten;
                }

                continue;
            }

            $out[] = $sub;
        }

        return $out;
    }

    private static function stringContainsPresenceRule(string $rule): bool
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
     * presence conditionals, otherwise null. Longest-prefix match resolves
     * `required_with_all:x` to `required_with_all` rather than `required_with`.
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
     * collapse to plain `required` when active and no custom message
     * overrides the original rule name, otherwise return unchanged.
     *
     * Covers both single- and multi-param forms of all four rules.
     *
     * @param  array<string, mixed>   $itemData
     * @param  array<string, string>  $itemMessages
     */
    private static function rewriteOne(string $rule, string $field, array $itemData, array $itemMessages): ?string
    {
        $parsed = self::parse($rule);
        if ($parsed === null) {
            return $rule;
        }

        [$ruleName, $rawParam] = $parsed;

        // Malformed rule (`required_with_all:` with no params): Laravel's
        // behavior here is quirky and tied to implicit-rule short-circuits —
        // safest course is to leave intact and let Laravel decide.
        if ($rawParam === '') {
            return $rule;
        }

        // Match Laravel's `ValidationRuleParser::parseParameters`: `str_getcsv`
        // with CSV semantics (handles quoted commas, preserves `null` for
        // empty slots between commas).
        $params = str_getcsv($rawParam, ',', '"', '\\');

        if (! self::ruleActivates($ruleName, $params, $itemData)) {
            return null;
        }

        if (self::hasCustomMessage($field, $ruleName, $itemMessages)) {
            return $rule;
        }

        return 'required';
    }

    /**
     * Activation predicate.
     *
     * | rule                    | active when                    |
     * |-------------------------|--------------------------------|
     * | `required_with`         | ANY of `$params` present       |
     * | `required_without`      | ANY of `$params` absent        |
     * | `required_with_all`     | ALL of `$params` present       |
     * | `required_without_all`  | ALL of `$params` absent        |
     *
     * @param  list<?string>          $params    May contain `null` entries when `str_getcsv`
     *                                           resolved an empty slot (matches Laravel's
     *                                           `Arr::get($data, null)` → full-item semantics).
     * @param  array<string, mixed>   $itemData
     */
    private static function ruleActivates(string $ruleName, array $params, array $itemData): bool
    {
        $anyPresent = false;
        $allPresent = true;

        foreach ($params as $param) {
            if (self::fieldPresent($itemData, $param)) {
                $anyPresent = true;
            } else {
                $allPresent = false;
            }
        }

        return match ($ruleName) {
            'required_with' => $anyPresent,
            'required_without' => ! $allPresent,
            'required_with_all' => $allPresent,
            'required_without_all' => ! $anyPresent,
            default => false,
        };
    }

    /**
     * Mirror Laravel's `validateRequired` — null, trim-empty string, empty
     * Countable, and missing UploadedFile path all count as absent. The
     * dependent-field parameter may be a nested path
     * (`required_without:profile.birthdate`), so resolve via `data_get`
     * instead of direct array-key lookup.
     *
     * @param  array<string, mixed>  $itemData
     */
    private static function fieldPresent(array $itemData, ?string $field): bool
    {
        $marker = new \stdClass();
        // A `null` key mirrors `Arr::get($data, null)` and resolves to the
        // full item (that's how Laravel treats a null parameter slot).
        $value = $field === null ? $itemData : data_get($itemData, $field, $marker);

        if ($value === $marker || $value === null) {
            return false;
        }

        if (is_string($value) && trim($value) === '') {
            return false;
        }

        if (is_countable($value) && count($value) < 1) {
            return false;
        }

        if ($value instanceof UploadedFile) {
            return (string) $value->getPath() !== '';
        }

        return true;
    }

    /**
     * Detect custom user-supplied messages for the original rule name so
     * the rewrite path doesn't bypass a `{field}.required_without`-style
     * override at message-formatting time.
     *
     * @param  array<string, string>  $itemMessages
     */
    private static function hasCustomMessage(string $field, string $ruleName, array $itemMessages): bool
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
