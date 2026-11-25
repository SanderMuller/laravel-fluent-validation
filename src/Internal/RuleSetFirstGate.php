<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use DateTime;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ParameterizedRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Validation\Rules\ArrayRule;
use Illuminate\Validation\Rules\Date;
use Illuminate\Validation\Rules\ExcludeIf;
use Illuminate\Validation\Rules\ExcludeUnless;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Numeric;
use Illuminate\Validation\Rules\ProhibitedIf;
use Illuminate\Validation\Rules\ProhibitedUnless;
use Illuminate\Validation\Rules\RequiredIf;
use Illuminate\Validation\Rules\RequiredUnless;
use Illuminate\Validation\Rules\StringRule;
use Illuminate\Validation\Rules\Unique;
use Stringable;

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
 * - A field name without `*` in a wildcard rule, such as `same:code`, means
 *   the top-level field in Laravel. When the data has no such top-level
 *   field, RuleSet reads the name as a field of the item.
 *
 * @internal Implementation detail of `HasFluentRules`.
 */
final class RuleSetFirstGate
{
    /** Rules that add no error before an exclude rule. */
    private const NO_ERROR = ['bail' => true, 'nullable' => true, 'sometimes' => true];

    /** Rules that read a parameter as a date when it parses as one, else as a field. */
    private const DATE_RULES = ['after' => true, 'after_or_equal' => true, 'before' => true, 'before_or_equal' => true, 'date_equals' => true];

    /** Laravel rule objects that run a callback to build their string. None of them names a field. */
    private const CALLBACK_RULES = [
        ExcludeIf::class => true, ExcludeUnless::class => true,
        ProhibitedIf::class => true, ProhibitedUnless::class => true,
        RequiredIf::class => true, RequiredUnless::class => true,
    ];

    /**
     * @param  array<array-key, mixed>  $compiledRules
     */
    public static function allows(array $compiledRules): bool
    {
        $fieldLocalRules = new FieldLocalRules();

        foreach ($compiledRules as $key => $rule) {
            $key = (string) $key;

            if (! self::allowsKey($key) || ! self::allowsRule($rule) || (str_contains($key, '*') && self::namesTopLevelField($rule, $fieldLocalRules, $compiledRules))) {
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

    /**
     * @param  array<array-key, mixed>  $compiledRules
     */
    private static function namesTopLevelField(mixed $rule, FieldLocalRules $fieldLocalRules, array $compiledRules): bool
    {
        $reads = [];

        foreach (self::parts($rule) as $object) {
            $parts = self::asLaravelReads($object);

            if ($parts === null) {
                return true;
            }

            array_push($reads, ...$parts);
        }

        $format = self::dateFormat($reads);

        foreach ($reads as $part) {
            $isDateRule = isset(self::DATE_RULES[self::name($part)]);

            foreach ($fieldLocalRules->references([$part]) as $field) {
                if (! str_contains($field, '*') && (! $isDateRule || ! self::isLiteralDate($field, $format, $compiledRules))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether Laravel and RuleSet both read a date rule's parameter as the
     * same literal date. With a `date_format` rule, Laravel parses it in the
     * `date_format` of a field with the same name: a top-level field in
     * Laravel, a field of the item in RuleSet.
     *
     * @param  array<array-key, mixed>  $compiledRules
     */
    private static function isLiteralDate(string $value, ?string $format, array $compiledRules): bool
    {
        if ($format === null) {
            return DateLikePaths::parses($value);
        }

        foreach (array_keys($compiledRules) as $key) {
            $key = (string) $key;

            if ($key === $value || str_ends_with($key, '.*.' . $value)) {
                return false;
            }
        }

        return DateTime::createFromFormat('!' . $format, $value) !== false || DateLikePaths::parses($value);
    }

    /**
     * The first `date_format` parameter, as Laravel's `getDateFormat()` reads it.
     *
     * @param  list<mixed>  $reads
     */
    private static function dateFormat(array $reads): ?string
    {
        foreach ($reads as $part) {
            if (self::name($part) !== 'date_format') {
                continue;
            }

            if (is_string($part)) {
                return str_getcsv(explode(':', $part, 2)[1] ?? '', escape: '\\')[0];
            }

            return is_array($part) && is_string($part[1] ?? null) ? $part[1] : null;
        }

        return null;
    }

    /**
     * The rules in one part as Laravel's rule parser reads them, so a field
     * name inside a tuple or a Laravel rule object is found too. Null when
     * only user code can tell: a custom rule object that Laravel reads as a
     * string or a tuple, or a tuple with an object parameter.
     *
     * @return list<mixed>|null
     */
    private static function asLaravelReads(mixed $part): ?array
    {
        if (is_array($part)) {
            return self::tupleAsLaravelReads($part);
        }

        if (! $part instanceof Stringable || isset(self::CALLBACK_RULES[$part::class])) {
            return [$part];
        }

        if (! str_starts_with($part::class, 'Illuminate\\Validation\\Rules\\')) {
            return null;
        }

        if ($part instanceof ParameterizedRule) {
            return self::tupleAsLaravelReads($part->toArray());
        }

        if ($part instanceof ArrayRule || $part instanceof Date || $part instanceof Numeric || $part instanceof StringRule) {
            return explode('|', (string) $part);
        }

        return [(string) $part];
    }

    /**
     * @param  array<mixed>  $tuple
     * @return list<mixed>|null
     */
    private static function tupleAsLaravelReads(array $tuple): ?array
    {
        foreach ($tuple as $value) {
            if (is_object($value)) {
                return null;
            }
        }

        return [array_map(static fn (mixed $value): mixed => is_scalar($value) || $value === null ? (string) $value : $value, $tuple)];
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
