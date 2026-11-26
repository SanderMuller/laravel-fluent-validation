<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Illuminate\Validation\Validator;
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
        // The validator writes the same placeholder for a dot in a data key.
        $placeholder = (string) array_key_first($validator->parseData(['.' => null]));

        $encoded = [];

        // Laravel encodes the rule keys, so the patterns too.
        foreach ($implicitAttributes as $pattern => $attributes) {
            $encoded[str_replace('\\.', $placeholder, $pattern)] = str_contains(implode("\n", $attributes), '\\.')
                ? str_replace('\\.', $placeholder, $attributes)
                : $attributes;
        }

        (new ReflectionProperty(Validator::class, 'implicitAttributes'))->setValue($validator, $encoded);
    }
}
