<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

/**
 * Gives a path that more than one rule key matches the rules of each key, as
 * Laravel's rule parser does: a wildcard adds its rules to the path's earlier
 * rules, and an explicit key replaces them.
 *
 * @internal Implementation detail of `RuleSet::expand()`.
 */
final class ExpandedRuleMerger
{
    public static function merge(mixed $existing, mixed $rule): mixed
    {
        if ($existing === null) {
            return $rule;
        }

        return [...self::ruleList($existing), ...self::ruleList($rule)];
    }

    /**
     * A FluentRule object becomes its compiled rules, so a modifier such as
     * `nullable` applies to the whole merged list, as in Laravel.
     *
     * @return list<mixed>
     */
    private static function ruleList(mixed $rule): array
    {
        if (is_object($rule) && method_exists($rule, 'compiledRules')) {
            $rule = $rule->compiledRules();
        }

        if (is_string($rule)) {
            return explode('|', $rule);
        }

        return is_array($rule) ? array_values($rule) : [$rule];
    }
}
