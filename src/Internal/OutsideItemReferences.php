<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * Finds wildcard groups whose rules name a field that the per-item validator
 * cannot read as Laravel does. The per-item validator sees only the item's
 * data and reads a bare name such as `after:order_date` as a sibling. When the
 * top-level data has that field, Laravel reads the top-level field instead.
 * A path into another wildcard (`same:other.*.x`), and a sibling path whose
 * stripped name Laravel parses as a date (`after:items.*.now` becomes
 * `after:now`), also change meaning per item. `in_array` always reads its
 * path from the full data. RuleSet validates such a group with full expansion.
 *
 * @internal Implementation detail of `RuleSet`.
 */
final class OutsideItemReferences
{
    /** Rules that Laravel first reads as a date, then as a field. */
    private const DATE_RULES = ['After' => true, 'AfterOrEqual' => true, 'Before' => true, 'BeforeOrEqual' => true, 'DateEquals' => true];

    /**
     * @param  array<string, mixed>  $groupRules  the group's rules before the per-item rewrite
     * @param  array<string, mixed>  $data  the data under validation
     */
    public static function exist(array $groupRules, string $parent, array $data): bool
    {
        $candidates = [];

        foreach (self::ruleParts($groupRules) as [$name, $parameters]) {
            if ($name === 'InArray') {
                return true;
            }

            if (self::namesOutside($name, $parameters, $parent . '.*.', $data)) {
                $candidates[$name] = true;
            }
        }

        if ($candidates === []) {
            return false;
        }

        // Only the parameters of dependent rules name fields. `unique` is a
        // dependent rule, but its parameters are a table and a column.
        $fieldRules = array_diff_key(array_flip([...WildcardReferenceRewriter::dependentRules(), 'DateEquals']), ['Unique' => true]);

        return array_intersect_key($candidates, $fieldRules) !== [];
    }

    /**
     * @param  array<string, mixed>  $groupRules
     * @return iterable<array{0: string, 1: list<string>}>
     */
    private static function ruleParts(array $groupRules): iterable
    {
        foreach ($groupRules as $rule) {
            if (is_object($rule) && method_exists($rule, 'compiledRules')) {
                $rule = $rule->compiledRules();
            }

            // One part at a time, so an object in a rule list hides only itself.
            foreach (is_array($rule) ? array_map(static fn (mixed $part): array => [$part], $rule) : [$rule] as $part) {
                yield from FieldLocalRules::parts($part) ?? [];
            }
        }
    }

    /**
     * @param  list<string>  $parameters
     * @param  array<string, mixed>  $data
     */
    private static function namesOutside(string $name, array $parameters, string $prefix, array $data): bool
    {
        $count = isset(WildcardReferenceRewriter::VALUE_CONDITIONALS[$name]) ? 1 : count($parameters);

        foreach (array_slice($parameters, 0, $count) as $parameter) {
            if (self::isOutside($name, trim($parameter), $prefix, $data)) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, mixed>  $data */
    private static function isOutside(string $name, string $field, string $prefix, array $data): bool
    {
        $isDateRule = isset(self::DATE_RULES[$name]);

        // The per-item rewrite strips the prefix of a dependent rule's field.
        if (str_starts_with($field, $prefix)) {
            return $isDateRule && $name !== 'DateEquals' && self::parsesAsDate(substr($field, strlen($prefix)));
        }

        if ($isDateRule && self::parsesAsDate($field)) {
            return false;
        }

        return str_contains($field, '*') || Arr::has($data, $field);
    }

    /** Mirrors Laravel's getDateTime(). */
    private static function parsesAsDate(string $value): bool
    {
        try {
            return (bool) @Date::parse($value);
        } catch (Throwable) {
            return false;
        }
    }
}
