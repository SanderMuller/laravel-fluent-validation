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
 * A token with a literal `|` (for example `regex:/^(a|b)$/`) also keeps the
 * list, and so does a rule in `isReadByDependents()` on a field that another
 * rule depends on.
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
        $dependencies = self::dependencies($itemRules);

        foreach ($itemRules as $field => $rules) {
            if (! is_array($rules) || $rules === [] || isset($conditionalFields[$field])) {
                continue;
            }

            $joined = self::join($rules, isset($dependencies[$field]));

            if ($joined !== null) {
                $itemRules[$field] = $joined;
            }
        }

        return $itemRules;
    }

    /** @param  array<int|string, mixed>  $rules */
    private static function join(array $rules, bool $isDependency): ?string
    {
        $parts = [];

        foreach ($rules as $rule) {
            if ($rule instanceof In || $rule instanceof NotIn) {
                $rule = (string) $rule;
            }

            if (! is_string($rule) || ($isDependency && self::isReadByDependents($rule)) || str_contains($rule, '|')) {
                return null;
            }

            $parts[] = $rule;
        }

        return implode('|', $parts);
    }

    /**
     * A rule Laravel reads from a field that another rule names: `boolean`
     * (shouldConvertToBoolean, for required_if:flag,true) and `date_format`
     * (after:start compares in start's format). Such a rule must stay in
     * Laravel's per-item validator.
     */
    public static function isReadByDependents(string $rule): bool
    {
        return $rule === 'boolean' || str_starts_with($rule, 'date_format:');
    }

    /**
     * Every field that a rule in the group names as its first parameter
     * (`required_if:flag,true`, `['exclude_if', 'flag', 'true']`, `gte:start`).
     * Laravel's dependent rules take the dependency as the first parameter.
     *
     * @param  array<string, mixed>  $itemRules
     * @return array<string, true>
     */
    private static function dependencies(array $itemRules): array
    {
        $dependencies = [];

        foreach ($itemRules as $rules) {
            foreach (is_string($rules) ? explode('|', $rules) : (is_array($rules) ? $rules : []) as $rule) {
                if (is_array($rule) && isset($rule[1]) && is_string($rule[1])) {
                    $dependencies[$rule[1]] = true;
                } elseif (is_string($rule) && str_contains($rule, ':')) {
                    $dependencies[explode(',', explode(':', $rule, 2)[1], 2)[0]] = true;
                }
            }
        }

        return $dependencies;
    }
}
