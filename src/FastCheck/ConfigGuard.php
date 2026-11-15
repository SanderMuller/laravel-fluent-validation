<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\FastCheck;

/**
 * Leaves a rule to Laravel when its compiled config cannot express it.
 *
 * - A config holds one value per key, but Laravel applies every rule in the
 *   string: `in:a,b|in:b,c` accepts only `b`. Size bounds are not listed:
 *   the parser intersects them.
 * - With `date_format`, Laravel compares dates in that format; the closure
 *   checks only the format.
 *
 * @internal
 */
final class ConfigGuard
{
    private const SINGLE_VALUE_KEYS = [
        'digits', 'digitsMin', 'digitsMax', 'in', 'notIn', 'regex', 'notRegex', 'dateFormat',
        'after', 'before', 'afterOrEqual', 'beforeOrEqual', 'dateEquals',
        'afterField', 'beforeField', 'afterOrEqualField', 'beforeOrEqualField', 'dateEqualsField',
        'sameField', 'differentField', 'gtField', 'gteField', 'ltField', 'lteField',
    ];

    private const DATE_LITERAL_KEYS = ['after', 'before', 'afterOrEqual', 'beforeOrEqual', 'dateEquals'];

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

        if ($result['dateFormat'] === null) {
            return $result;
        }

        foreach (self::DATE_LITERAL_KEYS as $key) {
            if ($result[$key] !== null) {
                return null;
            }
        }

        return $result;
    }
}
