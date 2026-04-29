<?php declare(strict_types=1);

use Illuminate\Validation\Concerns\ValidatesAttributes;

/**
 * Laravel's `integer:strict` is honored by `validateInteger` only on
 * Laravel 12.23+ (it gained an `array $parameters` argument there). On
 * older Laravel the modifier is silently ignored — `validateInteger`
 * runs `filter_var(..., FILTER_VALIDATE_INT)` and accepts numeric
 * strings. Tests that assert strict-mode rejection through Laravel's
 * outer validator must skip on the older path.
 */
function laravelSupportsIntegerStrict(): bool
{
    $reflection = new ReflectionMethod(
        ValidatesAttributes::class,
        'validateInteger'
    );

    return count($reflection->getParameters()) >= 3;
}
