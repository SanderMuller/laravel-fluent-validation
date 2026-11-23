<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

/**
 * Keeps the parts of the data that Laravel's `Validator::validated()` keeps
 * for a tree of rule keys. Laravel keeps the whole value of each attribute a
 * rule names. It skips an attribute with an `array` or `list` rule and a
 * value when a rule names an attribute below it: those rules keep the parts
 * they validate.
 *
 * The walk carries the excluded fields below each value: null when none is,
 * true when the value itself is. A kept part is the input array itself when
 * nothing in it is dropped, so valid data costs no copy.
 *
 * @internal Implementation detail of `RuleSet`.
 */
final class RuleKeyPruner
{
    /**
     * The kept part of the data, or null when a data key below a wildcard
     * holds a dot: Laravel escapes such a key.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>  $data
     * @return array<TKey, mixed>|null
     */
    public static function prune(RuleKeyNode $root, array $data, ?ExcludedFieldTree $excluded): ?array
    {
        $kept = self::pruneEach($root, $data, $excluded);

        if ($kept === Pruned::EscapedKey) {
            return null;
        }

        return $kept === Pruned::None ? [] : $kept;
    }

    /**
     * The kept part of a value, Pruned::None when no part is kept, or Pruned::EscapedKey
     * for a dotted data key below a wildcard.
     */
    private static function pruneValue(RuleKeyNode $node, mixed $value, ExcludedFieldTree|true|null $excluded): mixed
    {
        if ($node->keepsAnyValueWhole) {
            return $value;
        }

        if ($node->arrayRule === true && ($value === null || ! $node->hasRuleBelow($value, $excluded))) {
            return $value;
        }

        if (! is_array($value)) {
            return Pruned::None;
        }

        return match (true) {
            $node->itemKeys !== false => FlatValues::items($value, $node->itemKeys),
            $node->wholeKeys !== false => FlatValues::keepKeys($value, $node->wholeKeys),
            ! $node->wildcard instanceof RuleKeyNode => self::pruneNamed($node, $value, $excluded),
            $node->keepsItemsWhole => FlatValues::hasDottedKey($value) ? Pruned::EscapedKey : ($value === [] ? Pruned::None : $value),
            default => self::pruneEach($node, $value, $excluded),
        };
    }

    /**
     * {@see pruneValue()}, with the common cases first. Without excluded
     * fields, a record goes to its fields, and flat items and flat fields
     * have a rule below them.
     */
    private static function pruneChild(RuleKeyNode $node, mixed $value, ExcludedFieldTree|true|null $excluded): mixed
    {
        if ($node->keepsAnyValueWhole) {
            return $value;
        }

        if ($excluded !== null || ! is_array($value)) {
            return self::pruneValue($node, $value, $excluded);
        }

        return match (true) {
            $node->prunesByName => self::pruneNamed($node, $value, null),
            $node->itemKeys !== false && $value !== [] => FlatValues::items($value, $node->itemKeys),
            $node->wholeKeys !== false => FlatValues::keepKeys($value, $node->wholeKeys),
            default => self::pruneValue($node, $value, null),
        };
    }

    /**
     * The kept part of a value without a wildcard below the node.
     *
     * @param  array<array-key, mixed>  $value
     */
    private static function pruneNamed(RuleKeyNode $node, array $value, ExcludedFieldTree|true|null $excluded): mixed
    {
        $kept = array_diff_key($value, $node->children) === [] ? $value : array_intersect_key($value, $node->children);

        foreach ($node->nested as $key => $child) {
            if (! array_key_exists($key, $value)) {
                continue;
            }

            $part = $excluded === null && $child->itemKeys !== false && is_array($value[$key]) && $value[$key] !== []
                ? FlatValues::items($value[$key], $child->itemKeys)
                : self::pruneChild($child, $value[$key], $excluded instanceof ExcludedFieldTree ? $excluded->below($key) : $excluded);

            if ($part === Pruned::EscapedKey) {
                return $part;
            }

            if ($part === Pruned::None) {
                unset($kept[$key]);
            } elseif ($part !== $value[$key]) {
                $kept[$key] = $part;
            }
        }

        return $kept === [] ? Pruned::None : $kept;
    }

    /**
     * The kept part of each element, matched by name and by wildcard.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>  $value
     * @return array<TKey, mixed>|Pruned
     */
    private static function pruneEach(RuleKeyNode $node, array $value, ExcludedFieldTree|true|null $excluded): array|Pruned
    {
        $wildcard = $node->wildcard;
        $onlyWildcard = $node->children === [] ? $wildcard : null;

        if ($excluded === null && $onlyWildcard instanceof RuleKeyNode && $onlyWildcard->flatLists !== false) {
            return FlatValues::records($value, $onlyWildcard->children, $onlyWildcard->flatLists);
        }

        $kept = $value;

        foreach ($value as $key => $child) {
            if ($wildcard instanceof RuleKeyNode && is_string($key) && str_contains($key, '.')) {
                return Pruned::EscapedKey;
            }

            $below = $excluded instanceof ExcludedFieldTree ? $excluded->below($key) : $excluded;
            $part = match (true) {
                ! $onlyWildcard instanceof RuleKeyNode => self::pruneElement($node, $key, $child, $below),
                $below === null && $onlyWildcard->prunesByName && is_array($child) => self::pruneNamed($onlyWildcard, $child, null),
                default => self::pruneChild($onlyWildcard, $child, $below),
            };

            if ($part === Pruned::EscapedKey) {
                return $part;
            }

            if ($part === Pruned::None) {
                unset($kept[$key]);
            } elseif ($part !== $child) {
                $kept[$key] = $part;
            }
        }

        return $kept === [] ? Pruned::None : $kept;
    }

    /**
     * The kept part of an element: a key that a named child and the wildcard
     * both match gets their merged rules, as Laravel merges them.
     */
    private static function pruneElement(RuleKeyNode $node, int|string $key, mixed $value, ExcludedFieldTree|true|null $excluded): mixed
    {
        $target = $node->merged[$key] ?? $node->children[$key] ?? $node->wildcard;

        return $target instanceof RuleKeyNode ? self::pruneChild($target, $value, $excluded) : Pruned::None;
    }
}
