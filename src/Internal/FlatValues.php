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
