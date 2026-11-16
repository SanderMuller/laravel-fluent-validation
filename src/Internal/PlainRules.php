<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

/**
 * Expanded rules that Laravel's setRules() only splits on `|`: string rules
 * on concrete attributes. A wildcard key needs the data to expand, an
 * escaped dot needs a placeholder, and a rule object or array needs
 * preparing.
 *
 * @internal Implementation detail of `HasFluentRules`.
 */
final class PlainRules
{
    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, string>|null
     */
    public static function of(array $rules): ?array
    {
        $plain = [];

        foreach ($rules as $attribute => $rule) {
            // Interpolated: an integer-like key arrives as an int.
            if (! is_string($rule) || strpbrk("{$attribute}", '*\\') !== false) {
                return null;
            }

            $plain[$attribute] = $rule;
        }

        return $plain;
    }
}
