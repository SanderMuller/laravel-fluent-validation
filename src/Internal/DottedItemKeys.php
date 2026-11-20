<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

/**
 * Laravel does not resolve a wildcard in a dependent rule for an item whose
 * key contains a dot, so a per-item validator would decide such a rule
 * differently. The fully expanded validator matches Laravel.
 *
 * @internal
 */
final class DottedItemKeys
{
    /** @param  array<int|string, mixed>  $items */
    public static function exist(array $items): bool
    {
        if (array_is_list($items)) {
            return false;
        }

        foreach (array_keys($items) as $key) {
            if (is_string($key) && str_contains($key, '.')) {
                return true;
            }
        }

        return false;
    }
}
