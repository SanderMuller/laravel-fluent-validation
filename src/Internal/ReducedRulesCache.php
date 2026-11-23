<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use SanderMuller\FluentValidation\PresenceConditionalReducer;
use SanderMuller\FluentValidation\ValueConditionalReducer;

/**
 * Reduces an item's rules once per distinct reduction, for a group without a
 * single dispatch field. Two items reduce the same when the exclude rules
 * remove the same fields and each field a presence or value conditional
 * reads holds the same value.
 *
 * @internal Implementation detail of `ItemValidator`.
 */
final class ReducedRulesCache
{
    /** @var array<string, array{0: array<string, mixed>, 1: string}> */
    private array $rules = [];

    /**
     * @param  array<string, mixed>  $itemRules
     * @param  array<string, list<array{action: string, field: string, values: list<string>}>>  $conditionalFields
     * @param  array<string, string>  $itemMessages
     * @param  array<string, true>  $siblingFields
     * @param  list<string>|null  $dependencies  null when an item cannot be keyed
     * @param  array<string, list<array{action: string, field: string, values: list<string>}>>  $keyedConditionals  the conditional fields whose outcome the dependency values do not decide
     */
    private function __construct(
        private readonly ItemRuleCompiler $compiler,
        private readonly array $itemRules,
        private readonly array $conditionalFields,
        private readonly array $itemMessages,
        private readonly array $siblingFields,
        private readonly ?array $dependencies,
        private readonly array $keyedConditionals,
    ) {}

    /**
     * @param  array<string, mixed>  $itemRules
     * @param  array<string, list<array{action: string, field: string, values: list<string>}>>  $conditionalFields
     * @param  array<string, string>  $itemMessages
     * @param  array<string, true>  $siblingFields
     */
    public static function for(ItemRuleCompiler $compiler, array $itemRules, array $conditionalFields, array $itemMessages, array $siblingFields): self
    {
        $dependencies = self::dependencies($itemRules);
        // A field whose conditions read only dependency fields has its outcome in their values.
        $keyedConditionals = array_filter($conditionalFields, static fn (array $conditions): bool => array_diff(array_column($conditions, 'field'), $dependencies ?? []) !== []);

        return new self($compiler, $itemRules, $conditionalFields, $itemMessages, $siblingFields, $dependencies, $keyedConditionals);
    }

    /**
     * The reduced rules and their {@see ItemRuleCompiler::ruleCacheKey()}.
     *
     * @param  array<string, mixed>  $itemData
     * @return array{0: array<string, mixed>, 1: string}
     */
    public function reduce(array $itemData): array
    {
        $key = $this->dependencies === null ? null : $this->key($itemData, $this->dependencies);

        if ($key === null) {
            return $this->reduceItem($itemData);
        }

        return $this->rules[$key] ??= $this->reduceItem($itemData);
    }

    /**
     * @param  array<string, mixed>  $itemData
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function reduceItem(array $itemData): array
    {
        $rules = $this->compiler->reduceRulesForItem($this->itemRules, $itemData, $this->conditionalFields, $this->itemMessages, $this->siblingFields);

        return [$rules, $this->compiler->ruleCacheKey($rules)];
    }

    /**
     * The fields the presence and value conditionals read, when each one is a
     * top-level key; null otherwise.
     *
     * @param  array<string, mixed>  $itemRules
     * @return list<string>|null
     */
    private static function dependencies(array $itemRules): ?array
    {
        $fields = array_values(array_unique([...PresenceConditionalReducer::dependencies($itemRules), ...ValueConditionalReducer::dependencies($itemRules)]));

        foreach ($fields as $field) {
            if (str_contains($field, '.') || str_contains($field, '*')) {
                return null;
            }
        }

        return $fields;
    }

    /**
     * Which keyed conditional fields an exclude rule removes, then the value
     * of each dependency; null when a value cannot be part of a key.
     *
     * @param  array<string, mixed>  $itemData
     * @param  list<string>  $dependencies
     */
    private function key(array $itemData, array $dependencies): ?string
    {
        $key = '';

        foreach ($this->keyedConditionals as $conditions) {
            $key .= ItemRuleCompiler::fieldIsExcluded($conditions, $itemData, $this->itemRules) ? '1' : '0';
        }

        foreach ($dependencies as $field) {
            $value = DispatchField::key($itemData, $field);

            if ($value === null) {
                return null;
            }

            $key .= '|' . strlen($value) . ':' . $value;
        }

        return $key;
    }
}
