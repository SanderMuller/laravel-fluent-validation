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
 * @internal Implementation detail of `RuleSet`.
 *
 * @phpstan-type Pattern array{0: int, 1: list<string>, 2: bool}
 */
final class RuleOrderedData
{
    /**
     * A rule key with an escaped dot has segments this class cannot split,
     * so the data then keeps the order of the input.
     *
     * @param  array<array-key, mixed>  $validated  RuleSet's validated data
     * @param  array<array-key, mixed>  $compiledRules  the rules before the wildcard expansion, in rule order
     * @param  bool  $dropsUnknownFields  whether RuleSet leaves out the keys no rule validates, whatever the factory does
     * @return array<array-key, mixed>
     */
    public static function of(array $validated, array $compiledRules, bool $dropsUnknownFields): array
    {
        foreach (array_keys($compiledRules) as $key) {
            if (str_contains((string) $key, '\\')) {
                return $validated;
            }
        }

        $parents = $dropsUnknownFields || NativeValidatedData::factoryExcludesUnvalidatedKeys() ? self::parentKeys(array_keys($compiledRules)) : [];
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

            $touches[] = [$segment, $segments, $skipsArray];

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
            $shared === [] ? self::fixedKeys($touches) : null,
        );
    }

    /**
     * The named keys in rule order, when the rule keys of each key follow
     * each other. The data then cannot change which key comes first.
     *
     * @param  list<array{0: string, 1: list<string>, 2: bool}>  $touches
     * @return list<string>|null
     */
    private static function fixedKeys(array $touches): ?array
    {
        $keys = [];
        $previous = null;

        foreach ($touches as [$segment]) {
            if ($segment !== $previous && isset($keys[$segment])) {
                return null;
            }

            $keys[$segment] = true;
            $previous = $segment;
        }

        return array_map(strval(...), array_keys($keys));
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

        $keys = $plan->keys ?? RuleOrderTouches::firstKeys($node, $plan);

        // Laravel sets every child whole, so only the order of the keys can change.
        if ((! $plan->star instanceof RuleOrderPlan || $plan->star->whole) && $plan->own === [] && $keys === array_keys($node)) {
            return $node;
        }

        $ordered = [];

        foreach ($keys as $key) {
            if (! array_key_exists($key, $node)) {
                continue;
            }

            $value = $node[$key];
            $childPlan = $plan->own[$key] ?? $plan->star;
            $ordered[$key] = is_array($value) && $childPlan instanceof RuleOrderPlan ? self::order($value, $childPlan) : $value;
        }

        // A key that no rule key reaches keeps its place after the others.
        return count($ordered) === count($node) ? $ordered : $ordered + $node;
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
        if (array_keys($node) === $fields) {
            return $node;
        }

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
