<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use SanderMuller\FluentValidation\PresenceConditionalReducer;
use SanderMuller\FluentValidation\ValueConditionalReducer;

/**
 * Chooses the field whose value decides how an item's rules reduce, so
 * `ItemValidator` reduces once per distinct value instead of once per item.
 *
 * @internal Implementation detail of `ItemValidator`.
 */
final class DispatchField
{
    /**
     * The field every exclude condition reads, when the presence and value
     * conditionals read only that field too. Otherwise two items with the
     * same value can still reduce differently, and null turns caching off.
     *
     * @param  array<string, list<array{action: string, field: string, values: list<string>}>>  $conditionalFields
     * @param  array<string, mixed>  $itemRules
     */
    public static function find(ItemRuleCompiler $compiler, array $conditionalFields, array $itemRules): ?string
    {
        $field = $compiler->findCommonDispatchField($conditionalFields);

        // key() reads a top-level key only.
        if ($field === null || str_contains($field, '.')) {
            return null;
        }

        $dependencies = [...PresenceConditionalReducer::dependencies($itemRules), ...ValueConditionalReducer::dependencies($itemRules)];

        return array_diff($dependencies, [$field]) === [] ? $field : null;
    }

    /**
     * Cache key for the item's dispatch value, or null when the item must be
     * reduced on its own. The type is part of the key: an absent field, null,
     * '' and 0 can reduce differently under required_if / required_with.
     *
     * @param  array<string, mixed>  $itemData
     */
    public static function key(array $itemData, string $field): ?string
    {
        if (! array_key_exists($field, $itemData)) {
            return 'absent';
        }

        $value = $itemData[$field];

        return is_scalar($value) || $value === null
            ? get_debug_type($value) . ':' . $value
            : null;
    }
}
