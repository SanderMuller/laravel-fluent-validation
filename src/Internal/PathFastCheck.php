<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Closure;

/**
 * Wraps a compiled fast check so it reads its value from a path inside the
 * item: `['_v']` for a scalar each, `['style', 'top']` for a nested field.
 * Laravel escapes literal dots in data keys, so a dotted rule key only ever
 * matches the nested path; a missing segment reads as null, as in Laravel.
 *
 * @internal Implementation detail of `ItemRuleCompiler::buildFastChecks`.
 */
final class PathFastCheck
{
    /**
     * Split off the fields a fast check cannot read. A check reads a plain or
     * dotted field, the scalar each `*`, or `parent.*.child` with a flat
     * parent and a plain or dotted child. Any other wildcard field (`tags.*`,
     * `a.*.b.*`, a child with an escaped dot) goes to Laravel.
     *
     * @param  array<string, mixed>  $rules
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} readable rules, then the rest
     */
    public static function splitUnreadable(array $rules): array
    {
        $unreadable = array_filter($rules, static fn (int|string $field): bool => ! self::canRead((string) $field), ARRAY_FILTER_USE_KEY);

        return [array_diff_key($rules, $unreadable), $unreadable];
    }

    private static function canRead(string $field): bool
    {
        return match (substr_count($field, '*')) {
            0 => true,
            1 => $field === '*' || preg_match('/\A[^.]+\.\*\.[^.\\\\]+(\.[^.\\\\]+)*\z/', $field) === 1,
            default => false,
        };
    }

    /**
     * @param  list<string>  $path
     * @param  (Closure(mixed): bool)|null  $valueCheck
     * @param  (Closure(mixed, array<string, mixed>): bool)|null  $itemAwareCheck
     * @return Closure(array<string, mixed>): bool
     */
    public static function build(array $path, ?Closure $valueCheck, ?Closure $itemAwareCheck): Closure
    {
        if ($itemAwareCheck instanceof Closure) {
            return static fn (array $data): bool => $itemAwareCheck(self::read($data, $path), $data);
        }

        if (! $valueCheck instanceof Closure) {
            return static fn (array $data): bool => false;
        }

        return static fn (array $data): bool => $valueCheck(self::read($data, $path));
    }

    /**
     * Check `$child` in each element of `$parent`, as for `options.*.label` or
     * `answers.*.action.type`. A parent that is not an array has no elements.
     * An item-aware check gets the element as its item. A dotted child with a
     * sibling reference has no clear item context, so it returns null and
     * Laravel checks it.
     *
     * @param  (Closure(mixed): bool)|null  $valueCheck
     * @param  (Closure(mixed, array<string, mixed>): bool)|null  $itemAwareCheck
     * @return (Closure(array<string, mixed>): bool)|null
     */
    public static function eachItem(string $parent, string $child, ?Closure $valueCheck, ?Closure $itemAwareCheck): ?Closure
    {
        if ($itemAwareCheck instanceof Closure) {
            return str_contains($child, '.') ? null : self::eachItemAware($parent, $child, $itemAwareCheck);
        }

        if (! $valueCheck instanceof Closure) {
            return null;
        }

        if (str_contains($child, '.')) {
            return self::eachItemPath($parent, explode('.', $child), $valueCheck);
        }

        return static function (array $data) use ($parent, $child, $valueCheck): bool {
            $items = $data[$parent] ?? null;

            if (! is_array($items)) {
                return true;
            }

            foreach ($items as $item) {
                if (! is_array($item) || ! $valueCheck($item[$child] ?? null)) {
                    return false;
                }
            }

            return true;
        };
    }

    /**
     * The checks of {@see eachItem()} for several fields of the same items,
     * in one loop.
     *
     * @param  int|string  $parent  PHP stores a numeric segment as an int key
     * @param  array<array-key, Closure(mixed): bool>  $valueChecks  per field of the item
     * @return Closure(array<string, mixed>): bool
     */
    public static function eachItemAll(int|string $parent, array $valueChecks): Closure
    {
        return static function (array $data) use ($parent, $valueChecks): bool {
            $items = $data[$parent] ?? null;

            if (! is_array($items)) {
                return true;
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    return false;
                }

                foreach ($valueChecks as $child => $valueCheck) {
                    if (! $valueCheck($item[$child] ?? null)) {
                        return false;
                    }
                }
            }

            return true;
        };
    }

    /**
     * @param  Closure(mixed, array<string, mixed>): bool  $itemAwareCheck
     * @return Closure(array<string, mixed>): bool
     */
    private static function eachItemAware(string $parent, string $child, Closure $itemAwareCheck): Closure
    {
        return static function (array $data) use ($parent, $child, $itemAwareCheck): bool {
            $items = $data[$parent] ?? null;

            if (! is_array($items)) {
                return true;
            }

            foreach ($items as $item) {
                /** @var array<string, mixed> $item */
                if (! is_array($item) || ! $itemAwareCheck($item[$child] ?? null, $item)) {
                    return false;
                }
            }

            return true;
        };
    }

    /**
     * @param  list<string>  $path
     * @param  Closure(mixed): bool  $valueCheck
     * @return Closure(array<string, mixed>): bool
     */
    private static function eachItemPath(string $parent, array $path, Closure $valueCheck): Closure
    {
        return static function (array $data) use ($parent, $path, $valueCheck): bool {
            $items = $data[$parent] ?? null;

            if (! is_array($items)) {
                return true;
            }

            foreach ($items as $item) {
                if (! is_array($item) || ! $valueCheck(self::read($item, $path))) {
                    return false;
                }
            }

            return true;
        };
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $path
     */
    private static function read(array $data, array $path): mixed
    {
        $value = $data;

        foreach ($path as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
