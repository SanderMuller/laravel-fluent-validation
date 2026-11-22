<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use SanderMuller\FluentValidation\Internal\AsteriskPlaceholder;

class WildcardExpander
{
    /**
     * Expand a wildcard pattern against actual data.
     *
     * Instead of flattening the entire data array with Arr::dot() and
     * regex-matching every key (Laravel's O(n²) approach), this traverses
     * the data structure directly — O(n) where n = matching paths.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function expand(string $pattern, array $data): array
    {
        if (! str_contains($pattern, '*')) {
            return [$pattern];
        }

        $segments = explode('.', $pattern);
        $wildcard = array_search('*', $segments, true);

        if ($wildcard === false || count($segments) > 50 || in_array('*', array_slice($segments, $wildcard + 1), true)) {
            return self::resolve($segments, $data, []);
        }

        return self::expandOneWildcard($segments, $wildcard, $data);
    }

    /**
     * A pattern with one `*` gives one path for each key at the `*`, whatever
     * the item holds below it.
     *
     * @param  list<string>  $segments
     * @return list<string>
     */
    private static function expandOneWildcard(array $segments, int $wildcard, mixed $current): array
    {
        for ($position = 0; $position < $wildcard; $position++) {
            if (! is_array($current) || ! array_key_exists($segments[$position], $current)) {
                return [];
            }

            $current = $current[$segments[$position]];
        }

        if (! is_array($current)) {
            return [];
        }

        $prefix = $wildcard === 0 ? '' : implode('.', array_slice($segments, 0, $wildcard)) . '.';
        $suffix = $wildcard === count($segments) - 1 ? '' : '.' . implode('.', array_slice($segments, $wildcard + 1));
        $paths = [];

        foreach (array_keys($current) as $key) {
            // Escaped as in a rule key, so a key such as `2.5` or `c*d` stays one key.
            $paths[] = $prefix . (is_int($key) ? $key : self::escapeKey($key)) . $suffix;
        }

        return $paths;
    }

    /**
     * @param  list<string>  $segments
     * @param  list<string>  $path
     * @param  bool  $afterWildcard  Whether we've passed through a * segment
     * @return list<string>
     */
    private static function resolve(array $segments, mixed $current, array $path, bool $afterWildcard = false): array
    {
        if (count($path) > 50) {
            return [];
        }

        if ($segments === []) {
            return [implode('.', $path)];
        }

        $segment = $segments[0];
        $remaining = array_slice($segments, 1);

        if ($segment === '*') {
            if (! is_array($current)) {
                return [];
            }

            $paths = [];

            foreach (array_keys($current) as $key) {
                // Escaped as in a rule key, so a key such as `2.5` or `c*d` stays one key.
                array_push($paths, ...self::resolve($remaining, $current[$key], [...$path, is_int($key) ? (string) $key : self::escapeKey($key)], true));
            }

            return $paths;
        }

        if (! is_array($current) || ! array_key_exists($segment, $current)) {
            // Before any wildcard: the path doesn't exist in the data at all.
            // After a wildcard: the key is missing from this item — still emit
            // the path so rules like `required` can validate against it.
            // But only if the remaining segments have no more wildcards —
            // we can't resolve wildcards without data to iterate.
            if (! $afterWildcard || in_array('*', $remaining, true)) {
                return [];
            }

            return [implode('.', [...$path, $segment, ...$remaining])];
        }

        return self::resolve($remaining, $current[$segment], [...$path, $segment], $afterWildcard);
    }

    /**
     * Escapes a data key as a rule key: a dot as `\.`, and an asterisk as
     * Laravel's placeholder, as Laravel reads a `*` in a rule key as a wildcard.
     */
    private static function escapeKey(string $key): string
    {
        if (str_contains($key, '*')) {
            $key = str_replace('*', AsteriskPlaceholder::value(), $key);
        }

        return str_replace('.', '\\.', $key);
    }
}
