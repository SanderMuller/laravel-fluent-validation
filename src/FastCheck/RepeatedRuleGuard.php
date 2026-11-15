<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\FastCheck;

/**
 * A compiled config holds one value per key, but Laravel applies every rule
 * in the string: `in:a,b|in:b,c` accepts only `b`. A part that would replace
 * a value an earlier part set leaves the rule to Laravel. Size bounds are
 * not listed: the parser intersects them.
 *
 * @internal
 */
final class RepeatedRuleGuard
{
    private const SINGLE_VALUE_KEYS = [
        'digits', 'digitsMin', 'digitsMax', 'in', 'notIn', 'regex', 'notRegex', 'dateFormat',
        'after', 'before', 'afterOrEqual', 'beforeOrEqual', 'dateEquals',
        'afterField', 'beforeField', 'afterOrEqualField', 'beforeOrEqualField', 'dateEqualsField',
        'sameField', 'differentField', 'gtField', 'gteField', 'ltField', 'lteField',
    ];

    /**
     * @param  array<string, mixed>  $config  the config before the part
     * @param  array<string, mixed>|null  $result  the config after the part
     * @return array<string, mixed>|null
     */
    public static function check(array $config, ?array $result): ?array
    {
        if ($result === null) {
            return null;
        }

        foreach (self::SINGLE_VALUE_KEYS as $key) {
            if (($config[$key] ?? null) !== null && $result[$key] !== $config[$key]) {
                return null;
            }
        }

        return $result;
    }
}
