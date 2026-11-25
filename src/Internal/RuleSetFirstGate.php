<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Decides whether a pass in RuleSet's own engine is a pass in Laravel's
 * validator for the compiled rules of a form request.
 *
 * - RuleSet removes an excluded field before its rules run. Laravel runs the
 *   rules that come before the exclude rule, and they can add an error.
 * - A rule object that reads the data or the validator can see other data in
 *   RuleSet's per-item validator.
 * - A database rule would run its query twice when the request fails: once
 *   in RuleSet and once on the full path.
 * - A bare name in a wildcard rule, such as `same:code`, means the top-level
 *   field in a form request, as in Laravel. RuleSet reads it as a field of
 *   the item.
 *
 * @internal Implementation detail of `HasFluentRules`.
 */
final class RuleSetFirstGate
{
    /** Rules that add no error before an exclude rule. */
    private const NO_ERROR = ['bail' => true, 'nullable' => true, 'sometimes' => true];

    /** Rules that read a parameter as a date when it parses as one, else as a field. */
    private const DATE_RULES = ['after' => true, 'after_or_equal' => true, 'before' => true, 'before_or_equal' => true, 'date_equals' => true];

    /**
     * @param  array<array-key, mixed>  $compiledRules
     */
    public static function allows(array $compiledRules): bool
    {
        $fieldLocalRules = new FieldLocalRules();

        foreach ($compiledRules as $key => $rule) {
            $key = (string) $key;

            if (! self::allowsKey($key) || ! self::allowsRule($rule) || (str_contains($key, '*') && self::namesTopLevelField($rule, $fieldLocalRules))) {
                return false;
            }
        }

        return true;
    }

    /**
     * RuleSet rejects a wildcard key without a parent, such as `*`. A key with
     * an escaped dot has segments that `RuleOrderedData` cannot split.
     */
    private static function allowsKey(string $key): bool
    {
        return (! str_contains($key, '*') || str_contains($key, '.*')) && ! str_contains($key, '\\');
    }

    private static function allowsRule(mixed $rule): bool
    {
        $other = false;

        foreach (self::parts($rule) as $part) {
            if ($part instanceof DataAwareRule || $part instanceof ValidatorAwareRule || $part instanceof Exists || $part instanceof Unique) {
                return false;
            }

            $name = self::name($part);

            if ($name === 'exists' || $name === 'unique') {
                return false;
            }

            if (str_starts_with($name, 'exclude')) {
                if ($other) {
                    return false;
                }

                continue;
            }

            $other = $other || ! isset(self::NO_ERROR[$name]);
        }

        return true;
    }

    private static function namesTopLevelField(mixed $rule, FieldLocalRules $fieldLocalRules): bool
    {
        foreach (self::parts($rule) as $part) {
            $isDateRule = isset(self::DATE_RULES[self::name($part)]);

            foreach ($fieldLocalRules->references($part) as $field) {
                if (! str_contains($field, '*') && (! $isDateRule || ! DateLikePaths::parses($field))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<mixed>
     */
    private static function parts(mixed $rule): array
    {
        $parts = [];

        foreach (is_array($rule) ? $rule : [$rule] as $part) {
            // Splitting a regex on `|` only gives names that are no exclude rule.
            array_push($parts, ...(is_string($part) ? explode('|', $part) : [$part]));
        }

        return $parts;
    }

    private static function name(mixed $part): string
    {
        $part = is_array($part) ? ($part[0] ?? null) : $part;

        return is_string($part) ? strtolower(trim(explode(':', $part, 2)[0])) : '';
    }
}
