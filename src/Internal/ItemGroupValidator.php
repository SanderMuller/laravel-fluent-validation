<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Illuminate\Validation\Validator as BaseValidator;

/**
 * Validates the items of a wildcard group. Gives the group to
 * {@see ItemValidator} unless an exclude rule depends on a field below a
 * nested wildcard: that condition holds for each nested item, so the reducer
 * cannot decide it for the whole item. Laravel then validates each item with
 * the full rules and decides every exclude rule, in rule order.
 *
 * @internal
 *
 * @phpstan-import-type ExcludedFields from WildcardValidatedData
 */
final readonly class ItemGroupValidator
{
    public function __construct(
        private bool $stopOnFirstFailure,
        private ItemRuleCompiler $compiler,
        private ItemErrorCollector $errors,
    ) {}

    /**
     * @param  array<int|string, mixed>  $items
     * @param  array<string, mixed>  $itemRules
     * @param  array<string, string>  $itemMessages
     * @param  array<string, string>  $itemAttributes
     * @param  array<int|string, ExcludedFields>  $excludedFields  receives, per item index, the fields an exclude rule removed
     * @return array<string, list<string>>
     */
    public function validate(array $items, array $itemRules, array $itemMessages, array $itemAttributes, string $parent, bool $isScalar, array &$excludedFields = []): array
    {
        $conditionalFields = $this->compiler->analyzeConditionals($itemRules);

        if (! $this->excludesBelowNestedWildcard($conditionalFields)) {
            return (new ItemValidator($this->stopOnFirstFailure, $this->compiler, $this->errors))
                ->validate($items, $itemRules, $itemMessages, $itemAttributes, $parent, $isScalar, $conditionalFields, $excludedFields);
        }

        /** @var array<string, list<string>> $errors */
        $errors = [];
        $names = new ItemAttributeNames($isScalar, array_map(strval(...), array_keys($itemRules)), $parent, $itemMessages, $itemAttributes);

        foreach ($items as $index => $item) {
            /** @var array<string, mixed> $itemData */
            $itemData = $isScalar ? ['_v' => $item] : (is_array($item) ? $item : []);
            $validator = ItemValidator::makeItemValidator($itemData, $itemRules, $itemMessages, $itemAttributes);
            $validator->stopOnFirstFailure($this->stopOnFirstFailure);
            $names->forItem($validator, $index, $itemData);

            $passes = $validator->passes();
            $excluded = WildcardValidatedData::splitExcluded(array_flip($this->excludedAttributes($validator)));

            if ($excluded !== false) {
                $excludedFields[$index] = $excluded;
            }

            if (! $passes) {
                $this->errors->collectErrors($validator, $parent, $index, $isScalar, $errors);

                if ($this->stopOnFirstFailure) {
                    return $errors;
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, list<array{action: string, field: string, values: list<string>}>>  $conditionalFields
     */
    private function excludesBelowNestedWildcard(array $conditionalFields): bool
    {
        foreach ($conditionalFields as $conditions) {
            foreach ($conditions as $condition) {
                if (str_contains($condition['field'], '*')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function excludedAttributes(BaseValidator $validator): array
    {
        return (fn (): array => $this->excludeAttributes)->call($validator);
    }
}
