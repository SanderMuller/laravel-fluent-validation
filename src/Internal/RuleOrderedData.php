<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

/**
 * Puts RuleSet's validated data, which keeps the order of the input, in the
 * key order of Laravel's validated(). Laravel sets the keys rule key by rule
 * key, and only where the data holds the whole path, so a key comes where
 * the first rule key that reaches it puts it.
 *
 * A pattern holds the position of a rule key, the rest of its segments,
 * and whether Laravel skips the array it ends at.
 *
 * @internal Implementation detail of `PrevalidatedValidator`.
 *
 * @phpstan-type Pattern array{0: int, 1: list<string>, 2: bool}
 */
final class RuleOrderedData
{
    /**
     * @param  array<array-key, mixed>  $validated  RuleSet's validated data
     * @param  array<array-key, mixed>  $compiledRules  the rules before the wildcard expansion, in rule order
     * @return array<array-key, mixed>
     */
    public static function of(array $validated, array $compiledRules, bool $excludeUnvalidatedArrayKeys): array
    {
        $parents = $excludeUnvalidatedArrayKeys ? self::parentKeys(array_keys($compiledRules)) : [];
        $patterns = [];
        // Laravel keeps the keys without a wildcard in their place, before the attributes a wildcard adds.
        $keys = array_keys($compiledRules);
        $keys = [...array_filter($keys, static fn (int|string $key): bool => ! str_contains((string) $key, '*')), ...array_filter($keys, static fn (int|string $key): bool => str_contains((string) $key, '*'))];

        foreach ($keys as $index => $key) {
            $key = (string) $key;
            // Laravel leaves out an array with rules below it: those rules give its keys.
            $patterns[] = [$index, explode('.', $key), isset($parents[$key]) && self::holdsArrayRule($compiledRules[$key])];
        }

        return self::order($validated, self::plan($patterns));
    }

    /**
     * @param  list<Pattern>  $patterns
     */
    private static function plan(array $patterns): RuleOrderPlan
    {
        $fields = self::fields($patterns);

        if ($fields !== null) {
            return new RuleOrderPlan($fields);
        }

        $whole = false;
        $touches = [];
        $shared = [];
        $own = [];

        foreach ($patterns as [$index, $segments, $skipsArray]) {
            $segment = array_shift($segments);

            if ($segment === null) {
                // Laravel sets the whole array, in the order of the input.
                $whole = $whole || ! $skipsArray;

                continue;
            }

            $touches[] = [$segment, $segments];

            if ($segment === '*') {
                $shared[] = [$index, $segments, $skipsArray];
            } else {
                $own[$segment][] = [$index, $segments, $skipsArray];
            }
        }

        return new RuleOrderPlan(
            null,
            $whole,
            $touches,
            $shared === [] ? null : self::plan($shared),
            array_map(static fn (array $keyPatterns): RuleOrderPlan => self::plan(self::merged($shared, $keyPatterns)), $own),
        );
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @return array<array-key, mixed>
     */
    private static function order(array $node, RuleOrderPlan $plan): array
    {
        if ($plan->fields !== null) {
            return self::orderFields($node, $plan->fields);
        }

        if ($plan->whole) {
            return $node;
        }

        $ordered = [];

        foreach (self::firstKeys($node, $plan) as $key) {
            $value = $node[$key];
            $childPlan = $plan->own[$key] ?? $plan->star;
            $ordered[$key] = is_array($value) && $childPlan instanceof RuleOrderPlan ? self::order($value, $childPlan) : $value;
        }

        // A key that no rule key reaches keeps its place after the others.
        return count($ordered) === count($node) ? $ordered : $ordered + $node;
    }

    /**
     * The keys of the node in the order Laravel sets them. The touches come
     * in rule order, so a key keeps its first place.
     *
     * @param  array<array-key, mixed>  $node
     * @return list<array-key>
     */
    private static function firstKeys(array $node, RuleOrderPlan $plan): array
    {
        $first = [];

        foreach ($plan->touches as [$segment, $rest]) {
            foreach ($segment === '*' ? array_keys($node) : (array_key_exists($segment, $node) ? [$segment] : []) as $key) {
                if (! isset($first[$key]) && ($rest === [] || self::reaches($node[$key], $rest))) {
                    $first[$key] = true;
                }
            }
        }

        return array_keys($first);
    }

    /**
     * The fields in rule order when each pattern names one field and ends
     * there, the common shape of an item.
     *
     * @param  list<Pattern>  $patterns
     * @return list<string>|null
     */
    private static function fields(array $patterns): ?array
    {
        $fields = [];

        foreach ($patterns as [, $segments]) {
            if (count($segments) !== 1 || $segments[0] === '*') {
                return null;
            }

            $fields[] = $segments[0];
        }

        return $fields === [] ? null : $fields;
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $fields
     * @return array<array-key, mixed>
     */
    private static function orderFields(array $node, array $fields): array
    {
        $ordered = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $node)) {
                $ordered[$field] = $node[$field];
            }
        }

        return count($ordered) === count($node) ? $ordered : $ordered + $node;
    }

    /**
     * @param  list<Pattern>  $shared
     * @param  list<Pattern>  $own
     * @return list<Pattern>
     */
    private static function merged(array $shared, array $own): array
    {
        if ($shared === []) {
            return $own;
        }

        $patterns = [...$shared, ...$own];
        usort($patterns, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $patterns;
    }

    /**
     * @param  non-empty-list<string>  $segments
     */
    private static function reaches(mixed $value, array $segments): bool
    {
        if (! is_array($value)) {
            return false;
        }

        $segment = array_shift($segments);

        if ($segment !== '*') {
            return array_key_exists($segment, $value) && ($segments === [] || self::reaches($value[$segment], $segments));
        }

        foreach ($value as $child) {
            if ($segments === [] || self::reaches($child, $segments)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array-key>  $keys
     * @return array<string, true>
     */
    private static function parentKeys(array $keys): array
    {
        $parents = [];

        foreach ($keys as $key) {
            for ($path = (string) $key; ($dot = strrpos($path, '.')) !== false;) {
                $path = substr($path, 0, $dot);
                $parents[$path] = true;
            }
        }

        return $parents;
    }

    private static function holdsArrayRule(mixed $rules): bool
    {
        foreach (is_array($rules) ? $rules : [$rules] as $rule) {
            if (is_string($rule) && array_intersect(explode('|', $rule), ['array', 'list']) !== []) {
                return true;
            }
        }

        return false;
    }
}
