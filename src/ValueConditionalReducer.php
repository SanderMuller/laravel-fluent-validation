<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Illuminate\Support\Arr;
use SanderMuller\FluentValidation\Internal\AbstractConditionalReducer;
use SanderMuller\FluentValidation\Internal\ConditionalValueMatcher;

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
final class ValueConditionalReducer extends AbstractConditionalReducer
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
     * Rewrite value-conditional rules for a single field against item data.
     * Handles pipe-joined strings and list-of-rules shape.
     *
     * `$itemRules` is the full rule set for the item — needed so the reducer
     * can detect a `boolean` declaration on the dependent path and mirror
     * Laravel's `shouldConvertToBoolean`.
     *
     * @param  array<string, mixed>   $itemData
     * @param  array<string, string>  $itemMessages
     * @param  array<string, mixed>   $itemRules
     */
    public static function apply(mixed $rule, string $field, array $itemData, array $itemMessages, array $itemRules): mixed
    {
        return self::applyTemplate(
            $rule,
            static fn (string $r): ?string => self::rewriteOne($r, $field, $itemData, $itemMessages, $itemRules),
        );
    }

    /**
     * Return `[ruleName, rawParam]` when `$rule` is one of the recognized
     * value conditionals, otherwise null.
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

        /** @var list<?string> $rawValues */
        $rawValues = array_slice($params, 1);
        $match = ConditionalValueMatcher::matches($depPath, $rawValues, $itemData, $itemRules);

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
}
