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
}
