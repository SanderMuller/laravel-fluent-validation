<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

/**
 * The parts of flat values that {@see RuleKeyPruner} keeps: values whose
 * kept keys are known before the walk sees them.
 *
 * @internal Implementation detail of `RuleSet`.
 */
final class FlatValues
{
    /**
     * The kept part of each item when every item keeps only the named keys.
     *
     * @param  array<array-key, mixed>  $value
     * @param  array<array-key, true>  $keys
     */
    public static function items(array $value, array $keys): mixed
    {
        if ($value !== [] && self::allHoldOnly($value, $keys)) {
            return $value;
        }

        if (! array_is_list($value) && self::hasDottedKey($value)) {
            return Pruned::EscapedKey;
        }

        $kept = $value;

        foreach ($value as $key => $item) {
            if (! is_array($item) || $item === []) {
                unset($kept[$key]);
            } elseif (array_diff_key($item, $keys) !== []) {
                $part = array_intersect_key($item, $keys);

                if ($part === []) {
                    unset($kept[$key]);
                } else {
                    $kept[$key] = $part;
                }
            }
        }

        return $kept === [] ? Pruned::None : $kept;
    }

    /**
     * The kept part of each record when every record keeps its flat fields
     * and the items of its flat lists: {@see RuleKeyNode::$flatLists}.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>  $value
     * @param  array<array-key, mixed>  $fields  the keys each record keeps
     * @param  array<array-key, array{array<array-key, true>, bool}>  $lists
     * @return array<TKey, mixed>|Pruned
     */
    public static function records(array $value, array $fields, array $lists): array|Pruned
    {
        if ($value !== [] && self::recordsHoldOnly($value, $fields, $lists)) {
            return $value;
        }

        if (! array_is_list($value) && self::hasDottedKey($value)) {
            return Pruned::EscapedKey;
        }

        $kept = $value;

        foreach ($value as $index => $record) {
            $part = is_array($record) ? self::record($record, $fields, $lists) : Pruned::None;

            if ($part === Pruned::EscapedKey) {
                return $part;
            }

            if ($part === Pruned::None) {
                unset($kept[$index]);
            } elseif ($part !== $record) {
                $kept[$index] = $part;
            }
        }

        return $kept === [] ? Pruned::None : $kept;
    }

    /**
     * @param  array<array-key, mixed>  $record
     * @param  array<array-key, mixed>  $fields
     * @param  array<array-key, array{array<array-key, true>, bool}>  $lists
     */
    private static function record(array $record, array $fields, array $lists): mixed
    {
        $kept = array_diff_key($record, $fields) === [] ? $record : array_intersect_key($record, $fields);

        foreach ($lists as $key => [$keys, $keepsUnfilled]) {
            if (! array_key_exists($key, $record)) {
                continue;
            }

            $list = $record[$key];
            $part = is_array($list) && $list !== [] ? self::items($list, $keys) : ($keepsUnfilled ? $list : Pruned::None);

            if ($part === Pruned::EscapedKey) {
                return $part;
            }

            if ($part === Pruned::None) {
                unset($kept[$key]);
            } elseif ($part !== $list) {
                $kept[$key] = $part;
            }
        }

        return $kept === [] ? Pruned::None : $kept;
    }

    /**
     * Does each record hold only its fields, and each item of its lists
     * only the item keys? See {@see allHoldOnly()}.
     *
     * @param  array<array-key, mixed>  $records
     * @param  array<array-key, mixed>  $fields
     * @param  array<array-key, array{array<array-key, true>, bool}>  $lists
     */
    private static function recordsHoldOnly(array $records, array $fields, array $lists): bool
    {
        if (! self::allHoldOnly($records, $fields)) {
            return false;
        }

        foreach ($lists as $key => [$keys]) {
            $union = [];

            foreach ($records as $record) {
                // A null or empty list takes the exact walk, which applies its rule.
                if (is_array($record) && array_key_exists($key, $record) && (! is_array($record[$key]) || ! self::collectKeys($record[$key], $union))) {
                    return false;
                }
            }

            if (array_diff_key($union, $keys) !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * Is each element a filled array that holds only the keys? The keys of
     * all elements are gathered into one array first, so one compare checks
     * them all. False also when this check cannot tell: an empty list, or a
     * list with other keys.
     *
     * @param  array<array-key, mixed>  $list
     * @param  array<array-key, mixed>  $keys
     */
    private static function allHoldOnly(array $list, array $keys): bool
    {
        $union = [];

        return self::collectKeys($list, $union) && array_diff_key($union, $keys) === [];
    }

    /**
     * Adds the keys of each element to the union, when the value is a filled
     * list of filled arrays.
     *
     * @param  array<array-key, mixed>  $list
     * @param  array<array-key, mixed>  $union
     *
     * @param-out array<array-key, mixed>  $union
     */
    private static function collectKeys(array $list, array &$union): bool
    {
        if ($list === [] || ! array_is_list($list)) {
            return false;
        }

        foreach ($list as $element) {
            if (! is_array($element) || $element === []) {
                return false;
            }

            $union += $element;
        }

        return true;
    }

    /**
     * The named keys of a value, or Pruned::None when it has none of them.
     *
     * @param  array<array-key, mixed>  $value
     * @param  array<array-key, true>  $keys
     */
    public static function keepKeys(array $value, array $keys): mixed
    {
        if (array_diff_key($value, $keys) !== []) {
            $value = array_intersect_key($value, $keys);
        }

        return $value === [] ? Pruned::None : $value;
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    public static function hasDottedKey(array $value): bool
    {
        if (array_is_list($value)) {
            return false;
        }

        foreach (array_keys($value) as $key) {
            if (is_string($key) && str_contains($key, '.')) {
                return true;
            }
        }

        return false;
    }
}
