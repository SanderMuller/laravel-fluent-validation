<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\FastCheck;

use Closure;

/**
 * Guards a fast check that compares a date with another field. The check
 * reads an unparseable reference as 0. Laravel parses an empty string as
 * now and a number with Carbon, so for those values the check fails and
 * Laravel decides.
 *
 * @internal
 */
final class DateReference
{
    private const FIELD_KEYS = ['afterField', 'afterOrEqualField', 'beforeField', 'beforeOrEqualField', 'dateEqualsField'];

    /**
     * @param  array<string, mixed>  $config
     * @param  Closure(mixed, array<string, mixed>): bool  $check
     * @return Closure(mixed, array<string, mixed>): bool
     */
    public static function guard(array $config, Closure $check): Closure
    {
        $fields = array_values(array_filter(
            array_map(static fn (string $key): mixed => $config[$key] ?? null, self::FIELD_KEYS),
            is_string(...),
        ));

        if ($fields === []) {
            return $check;
        }

        return static function (mixed $value, array $item) use ($fields, $check): bool {
            foreach ($fields as $field) {
                $reference = $item[$field] ?? null;

                if ($reference === '' || is_int($reference) || is_float($reference)) {
                    return false;
                }
            }

            return $check($value, $item);
        };
    }
}
