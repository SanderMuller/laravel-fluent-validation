<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

/**
 * Finds the keys of one level of the validated data in the order Laravel's
 * validated() sets them, from the rule keys that touch that level, see
 * {@see RuleOrderedData}.
 *
 * @internal Implementation detail of `RuleOrderedData`.
 */
final class RuleOrderTouches
{
    /**
     * The keys of the node in the order Laravel sets them. The touches come
     * in rule order, so a key keeps its first place. For a rule key that ends
     * in `*`, Laravel sets the attributes that hold a non-empty array after
     * the others, see {@see WildcardExpansionOrder}.
     *
     * @param  array<array-key, mixed>  $node
     * @return list<array-key>
     */
    public static function firstKeys(array $node, RuleOrderPlan $plan): array
    {
        $first = [];

        $count = count($node);

        foreach ($plan->touches as [$segment, $rest, $skipsArray]) {
            foreach (self::touchedKeys($node, $plan, $segment, $rest, $skipsArray, $first) as $key) {
                $first[$key] = true;
            }

            if (count($first) === $count) {
                break;
            }
        }

        return array_keys($first);
    }

    /**
     * The keys one rule key sets, in the order it sets them.
     *
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $rest
     * @param  array<array-key, true>  $placed  the keys an earlier rule key set
     * @return list<array-key>
     */
    private static function touchedKeys(array $node, RuleOrderPlan $plan, string $segment, array $rest, bool $skipsArray, array $placed): array
    {
        if ($segment === '*' && ! $skipsArray && count($rest) < 2 && ($rest[0] ?? '') !== '*') {
            return self::itemKeys($node, $rest[0] ?? null, $placed);
        }

        $keys = $segment === '*' ? array_keys($node) : (array_key_exists($segment, $node) ? [$segment] : []);
        $arraysLast = $segment === '*' && ($rest === [] || end($rest) === '*');
        $now = [];
        $later = [];

        foreach ($keys as $key) {
            if (isset($placed[$key]) || ! self::sets($node[$key], $rest, $skipsArray && self::setsBelow($plan->touches, (string) $key, $rest, $node[$key]))) {
                continue;
            }

            if ($arraysLast && ! self::holdsLeaf($node[$key], $rest)) {
                $later[] = $key;
            } else {
                $now[] = $key;
            }
        }

        return [...$now, ...$later];
    }

    /**
     * The keys `*` or `*.field` sets: with a field, the items that hold it;
     * without one, every item, with the non-empty arrays last.
     *
     * @param  array<array-key, mixed>  $node
     * @param  array<array-key, true>  $placed
     * @return list<array-key>
     */
    private static function itemKeys(array $node, ?string $field, array $placed): array
    {
        $now = [];
        $later = [];

        foreach ($node as $key => $value) {
            if (isset($placed[$key])) {
                continue;
            }

            if ($field !== null) {
                if (is_array($value) && array_key_exists($field, $value)) {
                    $now[] = $key;
                }
            } elseif (is_array($value) && $value !== []) {
                $later[] = $key;
            } else {
                $now[] = $key;
            }
        }

        return $later === [] ? $now : [...$now, ...$later];
    }

    /**
     * Does a deeper rule key set a value below the key? Laravel skips an
     * array only when an expanded rule key lies below it.
     *
     * @param  list<array{0: string, 1: list<string>, 2: bool}>  $touches
     * @param  list<string>  $rest
     */
    private static function setsBelow(array $touches, string $key, array $rest, mixed $value): bool
    {
        foreach ($touches as [$segment, $deeper]) {
            if (($segment === '*' || $segment === $key) && count($deeper) > count($rest) && self::sets($value, $deeper, false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does Laravel set a value at a path of the rest of the rule key? It
     * skips an array with rules below it, but sets null or an empty array.
     *
     * @param  list<string>  $segments
     */
    private static function sets(mixed $value, array $segments, bool $skipsArray): bool
    {
        if ($segments === []) {
            return ! $skipsArray || $value === null || $value === [];
        }

        if (! is_array($value)) {
            return false;
        }

        $segment = array_shift($segments);

        if ($segment !== '*') {
            return array_key_exists($segment, $value) && self::sets($value[$segment], $segments, $skipsArray);
        }

        foreach ($value as $child) {
            if (self::sets($child, $segments, $skipsArray)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does a path of the rest of the rule key end at a value that is not a
     * non-empty array? `Arr::dot()` lists such a value at its place.
     *
     * @param  list<string>  $segments
     */
    private static function holdsLeaf(mixed $value, array $segments): bool
    {
        if ($segments === []) {
            return ! is_array($value) || $value === [];
        }

        if (! is_array($value)) {
            return false;
        }

        $segment = array_shift($segments);

        if ($segment !== '*') {
            return array_key_exists($segment, $value) && self::holdsLeaf($value[$segment], $segments);
        }

        foreach ($value as $child) {
            if (self::holdsLeaf($child, $segments)) {
                return true;
            }
        }

        return false;
    }
}
