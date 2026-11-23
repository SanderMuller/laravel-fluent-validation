<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

/**
 * Finds rule sets the per-item validator cannot split by item. Laravel
 * merges the rules of every key that expands to one attribute, so such rule
 * sets need Laravel's fully expanded validator. A rule on `items.*` next to
 * field rules splits: see `WildcardGroupPasses`.
 *
 * @internal Implementation detail of `RuleSet`.
 */
final class WildcardShape
{
    /**
     * Soft cap on the result cache. A rule set built from request data could
     * otherwise grow it without bound on a long-lived worker.
     */
    private const CACHE_MAX = 256;

    /** @var array<string, bool> */
    private static array $results = [];

    /**
     * @param  list<string>  $topKeys
     * @param  list<string>  $wildcardKeys  the wildcard rule keys, in rule order
     */
    public static function needsFullExpansion(array $topKeys, array $wildcardKeys): bool
    {
        $key = serialize([$topKeys, $wildcardKeys]);

        if (isset(self::$results[$key])) {
            return self::$results[$key];
        }

        if (count(self::$results) >= self::CACHE_MAX) {
            self::$results = [];
        }

        return self::$results[$key] = self::splitsBadly($topKeys, $wildcardKeys);
    }

    /**
     * @param  list<string>  $topKeys
     * @param  list<string>  $wildcardKeys
     */
    private static function splitsBadly(array $topKeys, array $wildcardKeys): bool
    {
        foreach ($wildcardKeys as $key) {
            // `items.*.*` puts a wildcard directly below the item.
            if (str_starts_with(substr($key, (int) strpos($key, '.*') + 2), '.*')) {
                return true;
            }
        }

        return self::overlap($wildcardKeys) || self::expandsTo($topKeys, $wildcardKeys);
    }

    /**
     * Can one attribute match two of the keys?
     *
     * @param  list<string>  $keys
     */
    private static function overlap(array $keys): bool
    {
        $segments = array_map(static fn (string $key): array => explode('.', $key), $keys);
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
     * Is a top-level key one of the attributes a wildcard key expands to?
     *
     * @param  list<string>  $topKeys
     * @param  list<string>  $wildcardKeys
     */
    private static function expandsTo(array $topKeys, array $wildcardKeys): bool
    {
        $wildcardSegments = array_map(static fn (string $key): array => explode('.', $key), $wildcardKeys);

        foreach ($topKeys as $topKey) {
            $segments = explode('.', $topKey);

            foreach ($wildcardSegments as $wildcard) {
                if (self::segmentsOverlap($segments, $wildcard)) {
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
