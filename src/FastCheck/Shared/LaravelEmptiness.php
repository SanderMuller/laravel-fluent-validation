<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\FastCheck\Shared;

use Symfony\Component\HttpFoundation\File\File;

/**
 * Mirrors Laravel's `validateRequired` definition of "empty":
 *   - null
 *   - string whose `trim()` is ''
 *   - array or Countable with count() === 0
 *   - File / UploadedFile whose path string is empty
 *
 * Used by presence-conditional gates (both sibling presence and target
 * required check) and by bare `prohibited`.
 *
 * @internal
 */
final class LaravelEmptiness
{
    public static function isEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return $value === [];
        }

        if ($value instanceof \Countable) {
            return count($value) === 0;
        }

        if ($value instanceof File) {
            return (string) $value->getPath() === '';
        }

        return false;
    }
}
