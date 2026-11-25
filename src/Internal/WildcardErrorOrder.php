<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

/**
 * Puts per-item errors in the order Laravel reports them. Laravel expands
 * each wildcard rule in rule order, so `items.1.x` comes before `items.0.y`
 * when `items.*.x` comes before `items.*.y`, and the attributes of one rule
 * key in the order of {@see WildcardExpansionOrder}.
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
     * @param  array<string, mixed>  $data
     * @return array<string, list<string>>
     */
    public static function merge(array $topErrors, array $wildcardErrors, array $wildcardKeys, bool $stopOnFirstFailure, array $data): array
    {
        $wildcardErrors = self::sort($wildcardErrors, $wildcardKeys, $data);

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
     * A stable sort by the rule key each attribute comes from, and then by
     * Laravel's expansion order within one rule key.
     *
     * @param  array<string, list<string>>  $errors
     * @param  list<string>  $wildcardKeys
     * @param  array<string, mixed>  $data
     * @return array<string, list<string>>
     */
    private static function sort(array $errors, array $wildcardKeys, array $data): array
    {
        if (count($errors) < 2) {
            return $errors;
        }

        $sorted = [];

        foreach (self::byRule($errors, $wildcardKeys) as $rule => $ruleErrors) {
            $attributes = array_map(strval(...), array_keys($ruleErrors));

            foreach (isset($wildcardKeys[$rule]) ? WildcardExpansionOrder::ofAttributes($attributes, $wildcardKeys[$rule], $data) : $attributes as $attribute) {
                $sorted[$attribute] = $ruleErrors[$attribute];
            }
        }

        return $sorted;
    }

    /**
     * The errors per rule key index, in rule order.
     *
     * @param  array<string, list<string>>  $errors
     * @param  list<string>  $wildcardKeys
     * @return array<int, array<string, list<string>>>
     */
    private static function byRule(array $errors, array $wildcardKeys): array
    {
        // Numeric item keys written as `*`. Without a numeric segment in a
        // rule key, one shape means one rule key.
        $shapes = preg_replace('/\.\d+(?=\.|$)/', '.*', array_keys($errors));
        $byShape = preg_grep('/(^|\.)\d+(\.|$)/', $wildcardKeys) === [];
        // No two keys expand to one attribute (see WildcardShape), so an
        // attribute with numeric item keys finds its rule key without a regex.
        $byKey = $byShape ? array_flip($wildcardKeys) : [];
        $patterns = array_map(
            static fn (string $key): string => '/^' . str_replace('\*', '[^.]*', preg_quote($key, '/')) . '\z/',
            $wildcardKeys,
        );
        /** @var array<int, array<string, list<string>>> $byRule */
        $byRule = [];

        foreach (array_keys($errors) as $position => $attribute) {
            $rule = $byKey[$shapes[$position]] ?? self::firstMatch((string) $attribute, $patterns);
            $byRule[$rule][$attribute] = $errors[$attribute];
        }

        ksort($byRule);

        return $byRule;
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
}
