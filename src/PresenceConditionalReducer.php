<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Illuminate\Http\UploadedFile;
use SanderMuller\FluentValidation\Internal\AbstractConditionalReducer;
use stdClass;

/**
 * Per-item pre-evaluation of Laravel's presence-conditional rules
 * (`required_with` / `required_without` / `required_with_all` /
 * `required_without_all`). Given an item and its rule set, decides
 * for each rule whether to drop it (inactive), rewrite it to plain
 * `required` (active without a custom message override — unlocks
 * fast-check), or leave it intact (active with an override — the
 * original rule name must survive so translator lookups still fire).
 *
 * @internal Implementation detail of `RuleSet`. Not part of the public API.
 */
final class PresenceConditionalReducer extends AbstractConditionalReducer
{
    /**
     * Longest-prefix-first order for `str_starts_with` disambiguation so
     * `required_with_all:x` resolves to `required_with_all` not `required_with`.
     *
     * @var list<string>
     */
    private const RULE_NAMES = [
        'required_without_all',
        'required_with_all',
        'required_without',
        'required_with',
    ];

    /**
     * Rewrite presence-conditional rules for a single field against
     * item data. Handles pipe-joined strings and list-of-rules shape.
     *
     * @param  array<string, mixed>   $itemData
     * @param  array<string, string>  $itemMessages
     */
    public static function apply(mixed $rule, string $field, array $itemData, array $itemMessages): mixed
    {
        return self::applyTemplate(
            $rule,
            static fn (string $r): ?string => self::rewriteOne($r, $field, $itemData, $itemMessages),
        );
    }

    /**
     * Return `[ruleName, rawParam]` when `$rule` is one of the recognized
     * presence conditionals, otherwise null. Longest-prefix match resolves
     * `required_with_all:x` to `required_with_all` rather than `required_with`.
     *
     * @return array{0: string, 1: string}|null
     */
    protected static function parse(string $rule): ?array
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
        $marker = new stdClass();
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
}
