<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Illuminate\Support\Str;

/**
 * Replaces each `*` in the parameters of a dependent rule with the wildcard
 * keys of the attribute, so `exclude_if:items.*.type,a` on `items.1.size`
 * becomes `exclude_if:items.1.type,a`. Laravel does this itself only when the
 * validator knows the wildcard pattern of the attribute, and a validator that
 * `Validator::make()` builds from the output of `RuleSet::prepare()` does not.
 *
 * Mirrors `Validator::getExplicitKeys()` and `replaceAsterisksInParameters()`:
 * the first pattern that lists the attribute gives the keys, and every
 * parameter starts again at the first key.
 *
 * @internal
 */
final class DependentParameterKeys
{
    /**
     * @param  array<string, mixed>  $rules  compiled rules, keyed by expanded attribute
     * @param  array<string, list<string>>  $implicitAttributes  wildcard pattern => expanded attributes
     * @return array<string, mixed>
     */
    public static function resolve(array $rules, array $implicitAttributes): array
    {
        if ($implicitAttributes === []) {
            return $rules;
        }

        $dependentRules = null;
        $resolved = [];

        foreach ($implicitAttributes as $pattern => $attributes) {
            // A key may hold an escaped dot (`\.`), as WildcardExpander writes it.
            $regex = '/^' . str_replace('\*', '((?:[^.\\\\]|\\\\.)*)', preg_quote($pattern, '/')) . '/';

            foreach ($attributes as $attribute) {
                if (isset($resolved[$attribute]) || ! isset($rules[$attribute])) {
                    continue;
                }

                $resolved[$attribute] = true;
                $rule = $rules[$attribute];

                if ((is_string($rule) && ! str_contains($rule, '*')) || preg_match($regex, $attribute, $keys) !== 1) {
                    continue;
                }

                $dependentRules ??= array_flip(WildcardReferenceRewriter::dependentRules());
                $rules[$attribute] = self::resolveRule($rule, array_values(array_slice($keys, 1)), $dependentRules);
            }
        }

        return $rules;
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, int>  $dependentRules
     */
    private static function resolveRule(mixed $rule, array $keys, array $dependentRules): mixed
    {
        if (is_string($rule)) {
            if (! str_contains($rule, '*')) {
                return $rule;
            }

            $parts = array_map(static fn (string $part): string => self::resolveString($part, $keys, $dependentRules), explode('|', $rule));

            // Laravel splits a rule string on `|` again, so a key with a pipe needs the list form.
            return str_contains(implode('', $keys), '|') ? $parts : implode('|', $parts);
        }

        if (! is_array($rule)) {
            return $rule;
        }

        foreach ($rule as $index => $part) {
            if (is_string($part)) {
                $rule[$index] = self::resolveString($part, $keys, $dependentRules);
            } elseif (is_array($part) && isset($part[0]) && is_string($part[0]) && isset($dependentRules[Str::studly(trim($part[0]))])) {
                // ['exclude_if', 'items.*.type', 'a'] => ['exclude_if', 'items.1.type', 'a']
                $rule[$index] = array_map(static fn (mixed $parameter): mixed => is_string($parameter) ? self::replaceAsterisks($parameter, $keys) : $parameter, $part);
            }
        }

        return $rule;
    }

    /**
     * One rule in string form, such as `exclude_if:items.*.type,a`.
     *
     * @param  list<string>  $keys
     * @param  array<string, int>  $dependentRules
     */
    private static function resolveString(string $rule, array $keys, array $dependentRules): string
    {
        $pieces = explode(':', $rule, 2);

        if (! isset($pieces[1]) || ! str_contains($pieces[1], '*') || ! isset($dependentRules[Str::studly(trim($pieces[0]))])) {
            return $rule;
        }

        $parameters = array_map(static fn (?string $parameter): string => self::quote(self::replaceAsterisks($parameter ?? '', $keys)), str_getcsv($pieces[1], escape: '\\'));

        return $pieces[0] . ':' . implode(',', $parameters);
    }

    /**
     * Laravel reads the parameters with str_getcsv(), so a key with a comma or
     * a quote needs quotes to stay one parameter.
     */
    private static function quote(string $parameter): string
    {
        return strpbrk($parameter, ',"') === false ? $parameter : '"' . str_replace('"', '""', $parameter) . '"';
    }

    /**
     * Laravel uses vsprintf(), which also reads a `%` in the parameter. This
     * replaces only the `*` characters, and leaves any `*` without a key.
     *
     * @param  list<string>  $keys
     */
    private static function replaceAsterisks(string $parameter, array $keys): string
    {
        $offset = 0;

        foreach ($keys as $key) {
            $position = strpos($parameter, '*', $offset);

            if ($position === false) {
                break;
            }

            $parameter = substr_replace($parameter, $key, $position, 1);
            $offset = $position + strlen($key);
        }

        return $parameter;
    }
}
