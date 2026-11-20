<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\FastCheck;

/**
 * Input checks that copy Laravel's ValidatesAttributes, for the fast-check
 * closures.
 *
 * @internal
 */
final class LaravelInput
{
    /**
     * Laravel's digits and digits_between accept only a string or a number.
     *
     * @phpstan-assert-if-true string|int|float $value
     */
    public static function isDigitsInput(mixed $value): bool
    {
        return is_string($value) || is_numeric($value);
    }

    /**
     * The timestamp for a date check, or false. With the `date` rule Laravel
     * also requires a real calendar date: strtotime() reads 'b' or 'now' as
     * a time, but date_parse() finds no year, month and day in them.
     */
    public static function timestamp(string $value, bool $isDate): int|false
    {
        $timestamp = strtotime($value);

        if ($timestamp === false || ! $isDate) {
            return $timestamp;
        }

        $date = date_parse($value);

        return is_int($date['year']) && is_int($date['month']) && is_int($date['day'])
            && checkdate($date['month'], $date['day'], $date['year']) ? $timestamp : false;
    }

    /**
     * Laravel's not_in negates its `in`. A scalar fails when it is listed, and
     * null reads as `''`, as Laravel casts it. Under
     * `array`, an array fails when every value is listed, so an empty array
     * fails too; one with a nested array passes. An element that cannot be cast
     * to a string fails here, so Laravel decides.
     *
     * @param  list<string>  $notIn
     */
    public static function failsNotIn(mixed $value, array $notIn, bool $isArray): bool
    {
        if (is_scalar($value) || $value === null) {
            return in_array((string) $value, $notIn, true);
        }

        if (! $isArray || ! is_array($value)) {
            return false;
        }

        foreach ($value as $element) {
            if (is_array($element)) {
                return false;
            }

            if ($element !== null && ! is_scalar($element)) {
                return true;
            }
        }

        return array_diff($value, $notIn) === [];
    }
}
