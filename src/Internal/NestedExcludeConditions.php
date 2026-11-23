<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

/**
 * @internal Implementation detail of `NestedExcludeSplit`.
 */
final class NestedExcludeConditions
{
    /**
     * Splits the exclude conditions of a nested field into the ones on the
     * item and the ones on the same nested item, relative to it. Null when a
     * condition reads a split field or another nested item.
     *
     * @param  list<array{action: string, field: string, values: list<string>}>  $conditions
     * @param  array<string, true>  $split
     * @return array{0: list<array{action: string, field: string, values: list<string>}>, 1: list<array{action: string, field: string, values: list<string>}>}|null
     */
    public static function byScope(array $conditions, string $parent, array $split): ?array
    {
        $byScope = [[], []];
        $prefix = "{$parent}.*.";

        foreach ($conditions as $condition) {
            $field = $condition['field'];
            $relative = str_starts_with($field, $prefix) ? substr($field, strlen($prefix)) : $field;

            if (isset($split[$field]) || str_contains($relative, '*')) {
                return null;
            }

            $byScope[$relative === $field ? 0 : 1][] = ['field' => $relative] + $condition;
        }

        return $byScope;
    }
}
