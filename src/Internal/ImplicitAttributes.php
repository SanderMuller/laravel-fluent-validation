<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Closure;
use Illuminate\Validation\Validator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Gives a validator the wildcard pattern of each expanded attribute, which
 * Laravel needs for dependent rules and `distinct`. An expanded attribute
 * escapes a dot in a data key as `\.`, and Laravel stores that attribute with
 * a placeholder for the dot, so the map must use the placeholder too.
 *
 * @internal
 */
final class ImplicitAttributes
{
    /**
     * @param  array<string, list<string>>  $implicitAttributes  wildcard pattern => expanded attributes
     */
    public static function set(Validator $validator, array $implicitAttributes): void
    {
        $encode = self::encoder($validator);

        foreach ($implicitAttributes as $pattern => $attributes) {
            $implicitAttributes[$pattern] = array_map(static fn (string $attribute): string => str_contains($attribute, '\\.') ? $encode($attribute) : $attribute, $attributes);
        }

        (new ReflectionProperty(Validator::class, 'implicitAttributes'))->setValue($validator, $implicitAttributes);
    }

    /**
     * What the validator writes in an attribute for an escaped dot (`\.`).
     */
    public static function dotPlaceholder(Validator $validator): string
    {
        return self::encoder($validator)('\\.');
    }

    /**
     * Laravel 12.0 keeps the dot placeholder per validator; later versions
     * encode it with a static method.
     *
     * @return Closure(string): string
     */
    private static function encoder(Validator $validator): Closure
    {
        if ((new ReflectionClass(Validator::class))->hasMethod('encodeAttributeWithPlaceholder')) {
            $method = new ReflectionMethod(Validator::class, 'encodeAttributeWithPlaceholder');

            return static function (string $attribute) use ($method): string {
                $encoded = $method->invoke(null, $attribute);

                return is_string($encoded) ? $encoded : $attribute;
            };
        }

        $placeholder = (new ReflectionProperty(Validator::class, 'dotPlaceholder'))->getValue($validator);

        return static fn (string $attribute): string => is_string($placeholder) ? str_replace('\\.', $placeholder, $attribute) : $attribute;
    }
}
