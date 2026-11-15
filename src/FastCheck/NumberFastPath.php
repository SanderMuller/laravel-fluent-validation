<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\FastCheck;

use Closure;

/**
 * A shortcut for number rules such as `required|integer|min:1`. When the
 * rule has no checks beyond presence, `numeric` / `integer` and size bounds,
 * an int value (or a float, without `integer`) passes every type check and
 * its size is the value itself, so only the bounds remain. Every other value
 * goes to the full check.
 *
 * @internal
 */
final class NumberFastPath
{
    private const ALLOWED_KEYS = ['required', 'nullable', 'numeric', 'integer', 'integer.strict', 'min', 'max'];

    /**
     * @param  array<string, mixed>  $config
     * @param  Closure(mixed): bool  $check  the full check for the config
     * @return Closure(mixed): bool
     */
    public static function wrap(array $config, Closure $check): Closure
    {
        if (! self::applies($config)) {
            return $check;
        }

        $integer = $config['integer'] === true;
        $min = is_int($config['min']) || is_float($config['min']) ? $config['min'] : -INF;
        $max = is_int($config['max']) || is_float($config['max']) ? $config['max'] : INF;

        return static function (mixed $value) use ($integer, $min, $max, $check): bool {
            if (is_int($value) || (is_float($value) && ! $integer && is_finite($value))) {
                return $value >= $min && $value <= $max;
            }

            return $check($value);
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function applies(array $config): bool
    {
        if ($config['numeric'] !== true && $config['integer'] !== true) {
            return false;
        }

        $initial = RuleConfigBuilder::initialConfig();

        foreach ($config as $key => $value) {
            if (! in_array($key, self::ALLOWED_KEYS, true) && $value !== ($initial[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
