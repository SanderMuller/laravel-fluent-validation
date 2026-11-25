<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Illuminate\Support\Arr;

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
     * The result of {@see resolvedPathParsesAsDate()} per field, for the
     * call of {@see existWithPrefix()} that runs: two rules often name the
     * same field.
     *
     * @var array<string, bool>
     */
    private static array $resolvedDates = [];

    /**
     * @param  array<string, mixed>  $groupRules  the group's rules before the per-item rewrite
     * @param  array<string, mixed>  $data  the data under validation
     */
    public static function exist(array $groupRules, string $parent, array $data): bool
    {
        return self::existWithPrefix($groupRules, $parent . '.*.', $data);
    }

    /**
     * Does the rule on `items.*` name another field? Laravel reads a field
     * of the item there too, which a validator of the item value alone
     * cannot see.
     *
     * @param  array<string, mixed>  $itemRule
     * @param  array<string, mixed>  $data
     */
    public static function fromItemRule(array $itemRule, array $data): bool
    {
        return self::existWithPrefix($itemRule, null, $data);
    }

    /**
     * @param  array<string, mixed>  $groupRules
     * @param  array<string, mixed>  $data
     */
    private static function existWithPrefix(array $groupRules, ?string $prefix, array $data): bool
    {
        $candidates = [];
        self::$resolvedDates = [];

        foreach (self::ruleParts($groupRules) as [$name, $parameters]) {
            if ($name === 'InArray') {
                return true;
            }

            if (self::namesOutside($name, $parameters, $prefix, $data)) {
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
    private static function namesOutside(string $name, array $parameters, ?string $prefix, array $data): bool
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
    private static function isOutside(string $name, string $field, ?string $prefix, array $data): bool
    {
        $isDateRule = isset(self::DATE_RULES[$name]);

        // The per-item rewrite strips the prefix of a dependent rule's field.
        if ($prefix !== null && str_starts_with($field, $prefix)) {
            return $isDateRule && $name !== 'DateEquals' && (DateLikePaths::parses(substr($field, strlen($prefix))) || (self::$resolvedDates[$field] ??= self::resolvedPathParsesAsDate($field, $data)));
        }

        if ($isDateRule && DateLikePaths::parses($field)) {
            return false;
        }

        return str_contains($field, '*') || Arr::has($data, $field);
    }

    /**
     * Does a path that Laravel resolves with the keys of the data parse as a
     * date, such as `x.now.from` for `x.*.from`?
     *
     * @param  array<string, mixed>  $data
     */
    private static function resolvedPathParsesAsDate(string $field, array $data): bool
    {
        $star = (int) strrpos($field, '*');
        $suffix = substr($field, $star + 1);

        foreach (self::resolvedPrefixes(explode('.', substr($field, 0, $star + 1)), $data) as $path) {
            if (DateLikePaths::parses($path . $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The paths of the data that the segments match, with each `*` replaced
     * by a key.
     *
     * @param  list<string>  $segments
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    private static function resolvedPrefixes(array $segments, array $data): array
    {
        $nodes = [['', $data]];

        foreach ($segments as $segment) {
            $next = [];

            foreach ($nodes as [$path, $node]) {
                foreach (self::children($node, $segment) as $key => $child) {
                    $next[] = [$path === '' ? (string) $key : $path . '.' . $key, $child];
                }
            }

            $nodes = $next;
        }

        return array_column($nodes, 0);
    }

    /**
     * The children of a node that a segment matches: all for `*`.
     *
     * @return array<array-key, mixed>
     */
    private static function children(mixed $node, string $segment): array
    {
        if (! is_array($node) || $segment === '*') {
            return is_array($node) ? $node : [];
        }

        return array_key_exists($segment, $node) ? [$segment => $node[$segment]] : [];
    }
}
