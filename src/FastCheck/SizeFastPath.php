<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\FastCheck;

use Closure;

/**
 * A shortcut for plain size rules such as `required|integer|min:1`,
 * `string|max:255` or `array|min:1`. When the rule has no checks beyond
 * presence, one type and size bounds, a value of that type passes every
 * type check and only its size is left to compare: the number itself, the
 * string length or the element count. Empty strings and arrays, and every
 * other value, go to the full check.
 *
 * @internal
 */
final class SizeFastPath
{
    private const ALLOWED_KEYS = ['required', 'nullable', 'numeric', 'integer', 'integer.strict', 'string', 'array', 'min', 'max'];

    /**
     * @param  array<string, mixed>  $config
     * @param  Closure(mixed): bool  $check  the full check for the config
     * @return Closure(mixed): bool
     */
    public static function wrap(array $config, Closure $check): Closure
    {
        $type = self::type($config);

        if ($type === null) {
            return $check;
        }

        $min = is_int($config['min']) || is_float($config['min']) ? $config['min'] : -INF;
        $max = is_int($config['max']) || is_float($config['max']) ? $config['max'] : INF;

        return match ($type) {
            'integer' => static fn (mixed $value): bool => is_int($value) ? $value >= $min && $value <= $max : $check($value),
            'numeric' => self::numeric($min, $max, $check),
            'string' => self::string($min, $max, $check),
            default => self::array($min, $max, $check),
        };
    }

    /**
     * @param  Closure(mixed): bool  $check
     * @return Closure(mixed): bool
     */
    private static function numeric(int|float $min, int|float $max, Closure $check): Closure
    {
        return static fn (mixed $value): bool => is_int($value) || (is_float($value) && is_finite($value))
            ? $value >= $min && $value <= $max
            : $check($value);
    }

    /**
     * @param  Closure(mixed): bool  $check
     * @return Closure(mixed): bool
     */
    private static function string(int|float $min, int|float $max, Closure $check): Closure
    {
        return static function (mixed $value) use ($min, $max, $check): bool {
            if (! is_string($value) || $value === '') {
                return $check($value);
            }

            $length = mb_strlen($value);

            return $length >= $min && $length <= $max;
        };
    }

    /**
     * @param  Closure(mixed): bool  $check
     * @return Closure(mixed): bool
     */
    private static function array(int|float $min, int|float $max, Closure $check): Closure
    {
        return static function (mixed $value) use ($min, $max, $check): bool {
            if (! is_array($value) || $value === []) {
                return $check($value);
            }

            $count = count($value);

            return $count >= $min && $count <= $max;
        };
    }

    /**
     * The one type the rule checks, or null when the shortcut does not apply.
     *
     * @param  array<string, mixed>  $config
     */
    private static function type(array $config): ?string
    {
        $initial = RuleConfigBuilder::initialConfig();

        foreach ($config as $key => $value) {
            if (! in_array($key, self::ALLOWED_KEYS, true) && $value !== ($initial[$key] ?? null)) {
                return null;
            }
        }

        $types = array_keys(array_filter([
            'integer' => $config['integer'] === true,
            'numeric' => $config['numeric'] === true && $config['integer'] !== true,
            'string' => $config['string'] === true,
            'array' => $config['array'] === true,
        ]));

        return count($types) === 1 ? $types[0] : null;
    }
}
