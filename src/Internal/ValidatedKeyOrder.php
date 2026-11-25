<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

/**
 * Puts the top-level keys and some lists of RuleSet's validated data in the
 * key order of Laravel's validated(). A list is ordered when its order can
 * change the result: an explicit rule key names one of its items, which
 * Laravel sets first, or its first rule key ends in `*` and accepts an
 * array, so Laravel sets the items that hold a non-empty array last. The
 * fields inside an item keep the order of the input.
 *
 * @internal Implementation detail of `RuleSet`.
 *
 * @phpstan-import-type Pattern from RuleOrderedData
 */
final class ValidatedKeyOrder
{
    /** The rules that fail for every array, so a list under them holds no array. */
    private const ARRAY_REJECTING_RULES = ['string', 'integer', 'numeric', 'boolean'];

    /** The most orders {@see self::$steps} keeps. */
    private const STEPS_CACHE_SIZE = 64;

    /**
     * The paths to order and their rule keys, per rule signature.
     *
     * @var array<string, list<array{list<string>, list<array{0: string, 1: list<string>, 2: bool}>}>>
     */
    private static array $steps = [];

    /**
     * @param  array<array-key, mixed>  $validated
     * @param  array<array-key, mixed>  $compiledRules  the rules before the wildcard expansion, in rule order
     * @param  bool  $dropsUnknownFields  whether RuleSet leaves out the keys no rule validates, whatever the factory does
     * @return array<array-key, mixed>
     */
    public static function of(array $validated, array $compiledRules, bool $dropsUnknownFields): array
    {
        $excludesUnvalidated = $dropsUnknownFields || NativeValidatedData::factoryExcludesUnvalidatedKeys();
        $signature = self::signature($compiledRules, $excludesUnvalidated);

        if ($signature === null) {
            return $validated;
        }

        if (! isset(self::$steps[$signature]) && count(self::$steps) >= self::STEPS_CACHE_SIZE) {
            self::$steps = [];
        }

        foreach (self::$steps[$signature] ??= self::steps($compiledRules, $excludesUnvalidated) as [$path, $touches]) {
            $changed = false;
            $validated = self::orderAt($validated, $path, $touches, $changed);
        }

        return $validated;
    }

    /**
     * What decides the order: each rule key and its string rules. Null for a
     * rule key with an escaped dot, which RuleOrderedData cannot split.
     *
     * @param  array<array-key, mixed>  $compiledRules
     */
    private static function signature(array $compiledRules, bool $excludesUnvalidated): ?string
    {
        $signature = $excludesUnvalidated ? '1' : '0';

        foreach ($compiledRules as $key => $rules) {
            if (str_contains((string) $key, '\\')) {
                return null;
            }

            $strings = is_array($rules) ? array_filter($rules, is_string(...)) : (is_string($rules) ? [$rules] : []);
            $signature .= "\0" . $key . "\1" . implode('|', $strings);
        }

        return $signature;
    }

    /**
     * The lists to order, then the top level, each with the rule keys below it.
     *
     * @param  array<array-key, mixed>  $compiledRules
     * @return list<array{list<string>, list<array{0: string, 1: list<string>, 2: bool}>}>
     */
    private static function steps(array $compiledRules, bool $excludesUnvalidated): array
    {
        $patterns = RuleOrderedData::patterns($compiledRules, $excludesUnvalidated);
        $steps = [];

        foreach (self::listsToOrder($patterns, $compiledRules) as $list) {
            $steps[] = [$list, self::touches($patterns, $list)];
        }

        $steps[] = [[], self::touches($patterns, [])];

        return $steps;
    }

    /**
     * The lists whose order can differ from the input, by the rules alone.
     *
     * @param  list<Pattern>  $patterns
     * @param  array<array-key, mixed>  $compiledRules
     * @return list<list<string>>
     */
    private static function listsToOrder(array $patterns, array $compiledRules): array
    {
        $candidates = [];

        foreach ($patterns as [, $segments]) {
            foreach (array_keys($segments, '*', true) as $position) {
                $list = array_slice($segments, 0, $position);
                $candidates[implode('.', $list)] = $list;
            }
        }

        return array_values(array_filter(
            $candidates,
            static fn (array $list): bool => ! self::setsWhole($patterns, $list) && self::orderMatters(self::touches($patterns, $list), $list, $compiledRules),
        ));
    }

    /**
     * @param  list<array{0: string, 1: list<string>, 2: bool}>  $touches
     * @param  list<string>  $list
     * @param  array<array-key, mixed>  $compiledRules
     */
    private static function orderMatters(array $touches, array $list, array $compiledRules): bool
    {
        foreach ($touches as [$segment]) {
            if ($segment !== '*') {
                return true;
            }
        }

        [, $rest] = $touches[0] ?? ['', ['']];

        return ($rest === [] || end($rest) === '*')
            && ! self::rejectsArrays($compiledRules[implode('.', [...$list, '*', ...$rest])] ?? null);
    }

    /**
     * The rule keys below the list, as the segment at the list and the rest.
     *
     * @param  list<Pattern>  $patterns
     * @param  list<string>  $list
     * @return list<array{0: string, 1: list<string>, 2: bool}>
     */
    private static function touches(array $patterns, array $list): array
    {
        $touches = [];
        $depth = count($list);

        foreach ($patterns as [, $segments, $skipsArray]) {
            if (count($segments) > $depth && self::startsWith($segments, $list)) {
                $touches[] = [$segments[$depth], array_slice($segments, $depth + 1), $skipsArray];
            }
        }

        return $touches;
    }

    /**
     * Does a rule key name the list itself, without Laravel skipping it?
     * Laravel then sets the whole list, in the order of the input.
     *
     * @param  list<Pattern>  $patterns
     * @param  list<string>  $list
     */
    private static function setsWhole(array $patterns, array $list): bool
    {
        foreach ($patterns as [, $segments, $skipsArray]) {
            if (! $skipsArray && count($segments) === count($list) && self::startsWith($segments, $list)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $segments
     * @param  list<string>  $prefix
     */
    private static function startsWith(array $segments, array $prefix): bool
    {
        foreach ($prefix as $position => $segment) {
            if ($segment !== '*' && $segments[$position] !== '*' && $segments[$position] !== $segment) {
                return false;
            }
        }

        return true;
    }

    private static function rejectsArrays(mixed $rules): bool
    {
        foreach (is_array($rules) ? $rules : [$rules] as $rule) {
            if (is_string($rule) && array_intersect(explode('|', $rule), self::ARRAY_REJECTING_RULES) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Orders the keys of each node the path reaches.
     *
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $segments  the path to the nodes
     * @param  list<array{0: string, 1: list<string>, 2: bool}>  $touches
     * @return array<array-key, mixed>
     */
    private static function orderAt(array $node, array $segments, array $touches, bool &$changed): array
    {
        if ($segments === []) {
            return self::ordered($node, $touches, $changed);
        }

        $segment = array_shift($segments);

        foreach ($segment === '*' ? array_keys($node) : (array_key_exists($segment, $node) ? [$segment] : []) as $key) {
            if (! is_array($node[$key])) {
                continue;
            }

            $childChanged = false;
            $child = self::orderAt($node[$key], $segments, $touches, $childChanged);

            if ($childChanged) {
                $node[$key] = $child;
                $changed = true;
            }
        }

        return $node;
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  list<array{0: string, 1: list<string>, 2: bool}>  $touches
     * @return array<array-key, mixed>
     */
    private static function ordered(array $node, array $touches, bool &$changed): array
    {
        if (count($node) < 2) {
            return $node;
        }

        $keys = RuleOrderTouches::firstKeys($node, new RuleOrderPlan(null, false, $touches));

        if ($keys === array_keys($node)) {
            return $node;
        }

        $changed = true;
        $ordered = [];

        foreach ($keys as $key) {
            $ordered[$key] = $node[$key];
        }

        // A key that no rule key reaches keeps its place after the others.
        return $ordered + $node;
    }
}
