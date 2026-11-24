<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Closure;
use SanderMuller\FluentValidation\FastCheck\SizeFastPath;

/**
 * {@see PathFastCheck::eachItemAll()} for fields that all have number
 * bounds: the loop compares a number itself, without a call to the field
 * check. Any other value goes to the field check.
 *
 * @internal Implementation detail of `PathFastCheck::eachItemAll()`.
 */
final class NumberBoundsCheck
{
    /**
     * @param  int|string  $parent  PHP stores a numeric segment as an int key
     * @param  array<array-key, array{bool, int|float, int|float}>  $bounds  {@see SizeFastPath::numberBounds()}
     * @param  array<array-key, Closure(mixed): bool>  $valueChecks
     * @return Closure(array<string, mixed>): bool
     */
    public static function eachItem(int|string $parent, array $bounds, array $valueChecks): Closure
    {
        $mins = [];
        $maxes = [];
        $floats = [];

        foreach ($bounds as $child => [$acceptsFloat, $min, $max]) {
            $mins[$child] = $min;

            if ($max !== INF) {
                $maxes[$child] = $max;
            }

            if ($acceptsFloat) {
                $floats[$child] = true;
            }
        }

        // The comparison with a maximum runs for each field of each item, so it is left out when no field has one.
        if ($maxes === []) {
            return static function (array $data) use ($parent, $mins, $floats, $valueChecks): bool {
                $items = $data[$parent] ?? null;

                return ! is_array($items) || self::aboveMinimums($items, $mins, $floats, $valueChecks);
            };
        }

        return static function (array $data) use ($parent, $mins, $maxes, $floats, $valueChecks): bool {
            $items = $data[$parent] ?? null;

            return ! is_array($items) || self::inBounds($items, $mins, $maxes, $floats, $valueChecks);
        };
    }

    /**
     * @param  array<array-key, mixed>  $items
     * @param  array<array-key, int|float>  $mins
     * @param  array<array-key, true>  $floats
     * @param  array<array-key, Closure(mixed): bool>  $valueChecks
     */
    private static function aboveMinimums(array $items, array $mins, array $floats, array $valueChecks): bool
    {
        foreach ($items as $item) {
            if (! is_array($item)) {
                return false;
            }

            foreach ($mins as $child => $min) {
                $value = $item[$child] ?? null;

                if (is_int($value) || (is_float($value) && isset($floats[$child]))) {
                    if ($value < $min) {
                        return false;
                    }
                } elseif (! $valueChecks[$child]($value)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  array<array-key, mixed>  $items
     * @param  array<array-key, int|float>  $mins
     * @param  array<array-key, int|float>  $maxes
     * @param  array<array-key, true>  $floats
     * @param  array<array-key, Closure(mixed): bool>  $valueChecks
     */
    private static function inBounds(array $items, array $mins, array $maxes, array $floats, array $valueChecks): bool
    {
        foreach ($items as $item) {
            if (! is_array($item)) {
                return false;
            }

            foreach ($mins as $child => $min) {
                $value = $item[$child] ?? null;

                if (is_int($value) || (is_float($value) && isset($floats[$child]))) {
                    if ($value < $min || $value > ($maxes[$child] ?? INF)) {
                        return false;
                    }
                } elseif (! $valueChecks[$child]($value)) {
                    return false;
                }
            }
        }

        return true;
    }
}
