<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

/**
 * Puts per-item errors in the order Laravel reports them. Laravel expands
 * each wildcard rule in rule order, and the attributes of one rule in data
 * order, so `items.1.x` comes before `items.0.y` when `items.*.x` comes
 * before `items.*.y`. Each group collects its errors item by item in data
 * order, so the errors of one rule key are in order already.
 *
 * @internal Implementation detail of `RuleSet`.
 */
final class WildcardErrorOrder
{
    /**
     * The top-level errors, then the wildcard errors in Laravel's order. With
     * stopOnFirstFailure only the first failing wildcard attribute stays.
     *
     * @param  array<string, list<string>>  $topErrors
     * @param  array<string, list<string>>  $wildcardErrors
     * @param  list<string>  $wildcardKeys  the wildcard rule keys, in rule order
     * @return array<string, list<string>>
     */
    public static function merge(array $topErrors, array $wildcardErrors, array $wildcardKeys, bool $stopOnFirstFailure): array
    {
        $wildcardErrors = self::sort($wildcardErrors, $wildcardKeys);

        if ($stopOnFirstFailure) {
            $wildcardErrors = array_slice($wildcardErrors, 0, 1, true);
        }

        if ($topErrors === []) {
            return $wildcardErrors;
        }

        foreach ($wildcardErrors as $attribute => $attributeErrors) {
            $topErrors[$attribute] = [...$topErrors[$attribute] ?? [], ...$attributeErrors];
        }

        return $topErrors;
    }

    /**
     * Is an error on the first wildcard rule key? Laravel then reports no
     * attribute of a later group before it.
     *
     * @param  array<string, list<string>>  $errors
     * @param  list<string>  $wildcardKeys
     */
    public static function holdsFirstRule(array $errors, array $wildcardKeys): bool
    {
        return $errors !== [] && isset($wildcardKeys[0])
            && preg_grep('/^' . str_replace('\*', '[^.]*', preg_quote($wildcardKeys[0], '/')) . '\z/', array_keys($errors)) !== [];
    }

    /**
     * A stable sort by the rule key each attribute comes from.
     *
     * @param  array<string, list<string>>  $errors
     * @param  list<string>  $wildcardKeys
     * @return array<string, list<string>>
     */
    private static function sort(array $errors, array $wildcardKeys): array
    {
        if (count($errors) < 2) {
            return $errors;
        }

        // Numeric item keys written as `*`. Without a numeric segment in a
        // rule key, one shape means one rule key.
        $shapes = preg_replace('/\.\d+(?=\.|$)/', '.*', array_keys($errors));
        $byShape = preg_grep('/(^|\.)\d+(\.|$)/', $wildcardKeys) === [];

        if ($byShape && count(array_unique($shapes)) === 1) {
            return $errors;
        }

        $patterns = array_map(
            static fn (string $key): string => '/^' . str_replace('\*', '[^.]*', preg_quote($key, '/')) . '\z/',
            $wildcardKeys,
        );
        // With no two keys that expand to one attribute, an attribute with
        // numeric item keys finds its rule key without a regex per key.
        $byKey = $byShape && ! self::overlap($wildcardKeys) ? array_flip($wildcardKeys) : [];
        /** @var array<int, array<string, list<string>>> $byRule */
        $byRule = [];

        foreach (array_keys($errors) as $position => $attribute) {
            $rule = $byKey[$shapes[$position]] ?? self::firstMatch((string) $attribute, $patterns);
            $byRule[$rule][$attribute] = $errors[$attribute];
        }

        if (count($byRule) === 1) {
            return $errors;
        }

        ksort($byRule);
        $sorted = [];

        foreach ($byRule as $ruleErrors) {
            $sorted += $ruleErrors;
        }

        return $sorted;
    }

    /**
     * The index of the first key whose expansion holds the attribute, or the
     * number of keys when none does. Laravel lists an attribute where the
     * first key that expands to it puts it.
     *
     * @param  list<string>  $patterns
     */
    private static function firstMatch(string $attribute, array $patterns): int
    {
        foreach ($patterns as $index => $pattern) {
            if (preg_match($pattern, $attribute) === 1) {
                return $index;
            }
        }

        return count($patterns);
    }

    /**
     * Can one attribute match two of the keys?
     *
     * @param  list<string>  $wildcardKeys
     */
    private static function overlap(array $wildcardKeys): bool
    {
        $segments = array_map(static fn (string $key): array => explode('.', $key), $wildcardKeys);
        $count = count($segments);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (self::segmentsOverlap($segments[$i], $segments[$j])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function segmentsOverlap(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }

        foreach ($a as $depth => $segment) {
            if ($segment !== $b[$depth] && $segment !== '*' && $b[$depth] !== '*') {
                return false;
            }
        }

        return true;
    }
}
