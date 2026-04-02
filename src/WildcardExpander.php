<?php

declare(strict_types=1);

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
     * @return list<string>
     */
    private static function resolve(array $segments, mixed $current, array $path): array
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
                array_push($paths, ...self::resolve($remaining, $current[$key], [...$path, (string) $key]));
            }

            return $paths;
        }

        if (! is_array($current) || ! array_key_exists($segment, $current)) {
            return [];
        }

        return self::resolve($remaining, $current[$segment], [...$path, $segment]);
    }
}
