<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

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

        return self::resolve(explode('.', $pattern), $data, []);
    }

    /**
     * @param  list<string>  $segments
     * @param  list<string>  $path
     * @param  bool  $afterWildcard  Whether we've passed through a * segment
     * @return list<string>
     */
    private static function resolve(array $segments, mixed $current, array $path, bool $afterWildcard = false): array
    {
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
                array_push($paths, ...self::resolve($remaining, $current[$key], [...$path, (string) $key], true));
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
}
