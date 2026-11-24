<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use stdClass;
use Stringable;

/**
 * Gives the data of Laravel's validated() before it replaces the key
 * placeholders. Laravel reads and sets each rule key with `data_get()` and
 * `Arr::set()`. This walks the arrays directly and leaves other keys and
 * values to `data_get()`.
 *
 * @internal Implementation detail of `OptimizedValidator`.
 */
final class ValidatedRuleKeys
{
    /**
     * @param  array<array-key, mixed>  $rules  the exploded rules
     * @param  array<array-key, mixed>  $data  the parsed data
     * @return array<array-key, mixed>
     */
    public static function data(array $rules, array $data, bool $excludeUnvalidatedArrayKeys): array
    {
        $parentKeys = $excludeUnvalidatedArrayKeys ? self::parentKeys($rules) : [];
        $missing = new stdClass();
        $results = [];

        foreach ($rules as $key => $attributeRules) {
            $value = self::valueAt($data, (string) $key, $missing);

            if (isset($parentKeys[$key]) && $value !== null && self::holdsArrayRule($attributeRules)) {
                continue;
            }

            if ($value !== $missing) {
                self::setAt($results, (string) $key, $value);
            }
        }

        return $results;
    }

    /**
     * Does a key of the data, at any depth, contain one of the strings?
     *
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $needles
     */
    public static function holdsKey(array $data, array $needles): bool
    {
        if (! array_is_list($data)) {
            $keys = implode("\n", array_keys($data));

            foreach ($needles as $needle) {
                if (str_contains($keys, $needle)) {
                    return true;
                }
            }
        }

        foreach ($data as $value) {
            if (is_array($value) && self::holdsKey($value, $needles)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The rule keys that have a rule key below them, as Laravel finds them.
     *
     * @param  array<array-key, mixed>  $rules
     * @return array<array-key, true>
     */
    private static function parentKeys(array $rules): array
    {
        $parentKeys = [];

        foreach (array_keys($rules) as $key) {
            $key = (string) $key;

            // A known parent key has its own parent keys in the list already.
            while (($dot = strrpos($key, '.')) !== false && ! isset($parentKeys[$key = substr($key, 0, $dot)])) {
                $parentKeys[$key] = true;
            }
        }

        return $parentKeys;
    }

    /**
     * Does the rule list hold `array` or `list`, as Laravel's in_array() finds it?
     */
    private static function holdsArrayRule(mixed $rules): bool
    {
        if (! is_array($rules)) {
            return false;
        }

        foreach ($rules as $rule) {
            $name = $rule instanceof Stringable ? (string) $rule : $rule;

            if ($name === 'array' || $name === 'list') {
                return true;
            }
        }

        return false;
    }

    /**
     * The value as `data_get()` reads it, or `$missing`.
     *
     * @param  array<array-key, mixed>  $data
     */
    private static function valueAt(array $data, string $key, stdClass $missing): mixed
    {
        // data_get() reads `*`, `{first}`, `{last}` and their escaped forms in its own way.
        if (strpbrk($key, '*{\\') !== false) {
            return data_get($data, $key, $missing);
        }

        $value = $data;

        foreach (explode('.', $key) as $segment) {
            if (! is_array($value)) {
                return data_get($data, $key, $missing);
            }

            if (! array_key_exists($segment, $value)) {
                return $missing;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Sets the value as `Arr::set()` does.
     *
     * @param  array<array-key, mixed>  $results
     */
    private static function setAt(array &$results, string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $last = array_pop($segments);
        $target = &$results;

        foreach ($segments as $segment) {
            if (! isset($target[$segment]) || ! is_array($target[$segment])) {
                $target[$segment] = [];
            }

            $target = &$target[$segment];
        }

        $target[$last] = $value;
    }
}
