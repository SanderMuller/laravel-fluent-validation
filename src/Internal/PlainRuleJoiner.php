<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\NotIn;

/**
 * Joins a field's rule list into one pipe string (`['nullable', 'string']`
 * becomes `nullable|string`) so the per-item fast checks can compile it.
 * Laravel parses both forms the same way.
 *
 * Only plain strings and `Rule::in` / `Rule::notIn` are joined. Other
 * Stringable rules (`Rule::requiredIf`, `Rule::unique`, ...) evaluate state
 * or feed the batch verifier as objects, so a list that holds one stays a list.
 * A token with a literal `|` (for example `regex:/^(a|b)$/`) or a `boolean`
 * token also keeps the list.
 *
 * @internal Implementation detail of `ItemValidator`.
 */
final class PlainRuleJoiner
{
    /**
     * @param  array<string, mixed>  $itemRules
     * @param  array<string, mixed>  $conditionalFields  fields with exclude_* rules; the reducer joins those per item
     * @return array<string, mixed>
     */
    public static function joinAll(array $itemRules, array $conditionalFields): array
    {
        foreach ($itemRules as $field => $rules) {
            if (! is_array($rules) || $rules === [] || isset($conditionalFields[$field])) {
                continue;
            }

            $joined = self::join($rules);

            if ($joined !== null) {
                $itemRules[$field] = $joined;
            }
        }

        return $itemRules;
    }

    /** @param  array<int|string, mixed>  $rules */
    private static function join(array $rules): ?string
    {
        $parts = [];

        foreach ($rules as $rule) {
            if ($rule instanceof In || $rule instanceof NotIn) {
                $rule = (string) $rule;
            }

            // `boolean` stays in Laravel's per-item validator: Laravel's
            // shouldConvertToBoolean reads it there when a sibling's
            // required_if / exclude_if tuple names this field.
            if (! is_string($rule) || $rule === 'boolean' || str_contains($rule, '|')) {
                return null;
            }

            $parts[] = $rule;
        }

        return implode('|', $parts);
    }
}
