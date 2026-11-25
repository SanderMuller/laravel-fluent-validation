<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Illuminate\Support\Arr;

/**
 * The order in which Laravel expands the attributes of one wildcard rule key.
 * `Arr::dot()` lists a value that is not a non-empty array at its place in
 * the data, and Laravel adds the other attributes of a key that ends in `*`
 * after them. For another key it sets every attribute to null first, so the
 * order is the data order.
 *
 * @internal Implementation detail of `RuleSet`.
 */
final class WildcardExpansionOrder
{
    /**
     * The paths of `WildcardExpander::expand()` in Laravel's order.
     *
     * @param  list<string>  $paths  the paths that `WildcardExpander::expand()` gives for the rule key
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    public static function ofPaths(array $paths, string $ruleKey, array $data): array
    {
        if (count($paths) < 2 || ! str_ends_with($ruleKey, '*')) {
            return $paths;
        }

        // The walk gives the values in the order of the paths.
        $values = self::walk(explode('.', $ruleKey), $data, false);

        return count($values) === count($paths) ? self::arraysLast($paths, $values) : self::ofAttributes($paths, $ruleKey, $data);
    }

    /**
     * Some attributes of the rule key, in data order, in Laravel's order.
     *
     * @param  list<string>  $attributes
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    public static function ofAttributes(array $attributes, string $ruleKey, array $data): array
    {
        if (count($attributes) < 2 || ! str_ends_with($ruleKey, '*')) {
            return $attributes;
        }

        return self::arraysLast($attributes, array_map(static fn (string $attribute): mixed => Arr::get($data, $attribute), $attributes));
    }

    /**
     * @param  list<string>  $attributes
     * @param  list<mixed>  $values  the value of each attribute
     * @return list<string>
     */
    private static function arraysLast(array $attributes, array $values): array
    {
        $leaves = [];
        $arrays = [];

        foreach ($attributes as $position => $attribute) {
            if (is_array($values[$position]) && $values[$position] !== []) {
                $arrays[] = $attribute;
            } else {
                $leaves[] = $attribute;
            }
        }

        return $arrays === [] ? $attributes : [...$leaves, ...$arrays];
    }

    /**
     * @param  list<string>  $segments
     * @return list<mixed>
     */
    private static function walk(array $segments, mixed $current, bool $afterWildcard): array
    {
        if ($segments === []) {
            return [$current];
        }

        $segment = array_shift($segments);

        if ($segment === '*') {
            if (! is_array($current)) {
                return [];
            }

            if ($segments === []) {
                return array_values($current);
            }

            $values = [];

            foreach ($current as $item) {
                array_push($values, ...self::walk($segments, $item, true));
            }

            return $values;
        }

        if (is_array($current) && array_key_exists($segment, $current)) {
            return self::walk($segments, $current[$segment], $afterWildcard);
        }

        // WildcardExpander still lists a missing key after a wildcard.
        return $afterWildcard && ! in_array('*', $segments, true) ? [null] : [];
    }
}
