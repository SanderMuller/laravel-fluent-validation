<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Illuminate\Support\Arr;

/**
 * Removes the data of excluded attributes as `Arr::forget()` does, with one
 * walk per parent path instead of one per attribute.
 *
 * @internal Implementation detail of `OptimizedValidator`.
 */
final class ExcludedData
{
    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $attributes
     */
    public static function forget(array &$data, array $attributes): void
    {
        /** @var array<string, array<array-key, mixed>|false> $parents */
        $parents = [];

        foreach ($attributes as $attribute) {
            $dot = strrpos($attribute, '.');

            // Arr::forget() removes a top-level key with a dot in it first.
            if ($dot === false || array_key_exists($attribute, $data)) {
                Arr::forget($data, $attribute);

                continue;
            }

            $parentPath = substr($attribute, 0, $dot);

            if (! array_key_exists($parentPath, $parents)) {
                $parents[$parentPath] = false;
                $parent = &self::arrayAt($data, $parentPath);

                if (is_array($parent)) {
                    $parents[$parentPath] = &$parent;
                }

                unset($parent);
            }

            if ($parents[$parentPath] === false) {
                // A missing path, or one through an ArrayAccess object.
                Arr::forget($data, $attribute);

                continue;
            }

            unset($parents[$parentPath][substr($attribute, $dot + 1)]);
        }
    }

    /**
     * A reference to the array at the path, or to null when a segment is
     * missing or is not an array.
     *
     * @param  array<array-key, mixed>  $data
     */
    private static function &arrayAt(array &$data, string $path): mixed
    {
        $node = &$data;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($node) || ! isset($node[$segment])) {
                $missing = null;

                return $missing;
            }

            $node = &$node[$segment];
        }

        return $node;
    }
}
