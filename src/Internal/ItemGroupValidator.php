<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Validation\Validator as BaseValidator;
use SanderMuller\FluentValidation\FastCheckCompiler;

/**
 * Validates the items of a wildcard group with {@see ItemValidator}. An exclude
 * rule that depends on a field below a nested wildcard holds for each nested
 * item, so the reducer cannot decide it for the whole item. An item that holds
 * such nested items goes to Laravel with the full rules instead, and Laravel
 * decides every exclude rule, in rule order. An item without them expands no
 * nested field, so the reducer still decides it.
 *
 * @internal
 *
 * @phpstan-import-type ExcludedFields from WildcardValidatedData
 */
final readonly class ItemGroupValidator
{
    public function __construct(
        private ItemRuleCompiler $compiler,
        private ItemErrorCollector $errors,
        private ?string $stopAt = null,
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

        if ($isScalar && $conditionalFields === [] && $this->scalarItemsPass($items, $itemRules)) {
            return [];
        }

        $nestedParents = $this->nestedConditionParents($conditionalFields);
        $itemValidator = new ItemValidator($this->compiler, $this->errors, $this->stopAt);

        if ($nestedParents === []) {
            return $itemValidator->validate($items, $itemRules, $itemMessages, $itemAttributes, $parent, $isScalar, $conditionalFields, $excludedFields);
        }

        $split = $nestedParents === null || $isScalar || $this->hasDottedNestedKeys($items, $nestedParents)
            ? null
            : NestedExcludeSplit::for($itemRules, $conditionalFields);

        if ($split instanceof NestedExcludeSplit && $nestedParents !== null) {
            return $this->validateSplit($split, $items, $itemMessages, $itemAttributes, $parent, $nestedParents, $itemValidator, $excludedFields);
        }

        $names = new ItemAttributeNames($isScalar, array_map(strval(...), array_keys($itemRules)), $parent, $itemMessages, $itemAttributes);
        /** @var array<string, list<string>> $errors */
        $errors = [];

        foreach ($items as $index => $item) {
            /** @var array<string, mixed> $itemData */
            $itemData = $isScalar ? ['_v' => $item] : (is_array($item) ? $item : []);

            if ($this->validateWhole($index, $itemData, $itemRules, $itemMessages, $itemAttributes, $parent, $isScalar, $names, $excludedFields, $errors)) {
                return $errors;
            }
        }

        return $errors;
    }

    /**
     * Does each item pass a scalar rule that compiles to one value check?
     * Such a rule reads no other field, so Laravel has nothing left to check.
     *
     * @param  array<int|string, mixed>  $items
     * @param  array<string, mixed>  $itemRules
     */
    private function scalarItemsPass(array $items, array $itemRules): bool
    {
        $rule = count($itemRules) === 1 ? $itemRules['_v'] ?? null : null;
        $check = is_string($rule) ? FastCheckCompiler::compile($rule) : null;

        if (! $check instanceof Closure) {
            return false;
        }

        foreach ($items as $item) {
            if (! $check($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The reducer validates every item without the split fields. Laravel then
     * validates the split fields of each item that holds nested items.
     *
     * @param  array<int|string, mixed>  $items
     * @param  array<string, string>  $itemMessages
     * @param  array<string, string>  $itemAttributes
     * @param  array<string, true>  $nestedParents
     * @param  array<int|string, ExcludedFields>  $excludedFields
     * @return array<string, list<string>>
     */
    private function validateSplit(NestedExcludeSplit $split, array $items, array $itemMessages, array $itemAttributes, string $parent, array $nestedParents, ItemValidator $itemValidator, array &$excludedFields): array
    {
        $reducedErrors = $itemValidator->validate($items, $split->reducerRules, $itemMessages, $itemAttributes, $parent, false, $split->reducerConditionals, $excludedFields);
        $names = new ItemAttributeNames(false, array_map(strval(...), array_keys($split->nestedRules)), $parent, $itemMessages, $itemAttributes);
        /** @var array<int|string, array<string, list<string>>> $nestedErrors */
        $nestedErrors = [];
        $validator = null;

        foreach ($items as $index => $item) {
            if (! is_array($item) || ! $this->holdsNestedItems($item, $nestedParents)) {
                continue;
            }

            /** @var array<string, mixed> $item */
            $decided = $split->decide($item);

            if ($decided !== null) {
                $this->recordExcluded($decided, $index, $excludedFields);

                continue;
            }

            if (! $validator instanceof BaseValidator) {
                $validator = ItemValidator::makeItemValidator($item, $split->nestedRules, $itemMessages, $itemAttributes);
            } else {
                // passes() does not reset the exclusions of the previous item.
                (fn () => $this->excludeAttributes = [])->call($validator);
                $validator->setData($item);
            }

            $names->forItem($validator, $index, $item);
            $validator->passes();
            $this->recordSplitExclusions($split, $validator, $index, $excludedFields);

            $errors = array_filter($validator->errors()->toArray(), $split->isSplitAttribute(...), ARRAY_FILTER_USE_KEY);

            foreach ($errors as $field => $fieldErrors) {
                /** @var list<string> $fieldErrors */
                $nestedErrors[$index]["{$parent}.{$index}.{$field}"] = $fieldErrors;
            }
        }

        return $this->withNestedErrors(array_keys($items), $parent, $reducedErrors, $nestedErrors);
    }

    /**
     * @param  array<int|string, ExcludedFields>  $excludedFields
     */
    private function recordSplitExclusions(NestedExcludeSplit $split, BaseValidator $validator, int|string $index, array &$excludedFields): void
    {
        $this->recordExcluded(array_values(array_filter($this->excludedAttributes($validator), $split->isSplitAttribute(...))), $index, $excludedFields);
    }

    /**
     * Adds excluded attributes of an item to the ones the reducer recorded.
     *
     * @param  list<string>  $attributes
     * @param  array<int|string, ExcludedFields>  $excludedFields
     */
    private function recordExcluded(array $attributes, int|string $index, array &$excludedFields): void
    {
        $excluded = WildcardValidatedData::excludedTree(array_flip($attributes));

        if ($excluded === false) {
            return;
        }

        $current = $excludedFields[$index] ?? false;
        $excludedFields[$index] = $current === false ? $excluded : $current->with($excluded);
    }

    /**
     * Adds each item's split-field errors after its reducer errors.
     *
     * @param  list<int|string>  $indexes
     * @param  array<string, list<string>>  $reducedErrors  in item order
     * @param  array<int|string, array<string, list<string>>>  $nestedErrors
     * @return array<string, list<string>>
     */
    private function withNestedErrors(array $indexes, string $parent, array $reducedErrors, array $nestedErrors): array
    {
        if ($nestedErrors === []) {
            return $reducedErrors;
        }

        $errors = [];
        $reducedKeys = array_keys($reducedErrors);
        $next = 0;

        foreach ($indexes as $index) {
            $prefix = "{$parent}.{$index}.";

            while (isset($reducedKeys[$next]) && str_starts_with($reducedKeys[$next], $prefix)) {
                $errors[$reducedKeys[$next]] = $reducedErrors[$reducedKeys[$next]];
                $next++;
            }

            $errors += $nestedErrors[$index] ?? [];
        }

        return $errors + $reducedErrors;
    }

    /**
     * Whether nested data holds a key with a dot. Laravel reports such a key
     * unescaped, so a split field could not be told from the key.
     *
     * @param  array<int|string, mixed>  $items
     * @param  array<string, true>  $nestedParents
     */
    private function hasDottedNestedKeys(array $items, array $nestedParents): bool
    {
        foreach ($items as $item) {
            foreach (array_keys($nestedParents) as $path) {
                $nested = is_array($item) ? Arr::get($item, $path) : null;

                if (is_array($nested) && self::holdsDottedKey($nested)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<int|string, mixed>  $data
     */
    private static function holdsDottedKey(array $data): bool
    {
        foreach ($data as $key => $value) {
            if ((is_string($key) && str_contains($key, '.')) || (is_array($value) && self::holdsDottedKey($value))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The item paths that hold the nested items of an exclude condition below
     * a nested wildcard; null when such a path cannot be read.
     *
     * @param  array<string, list<array{action: string, field: string, values: list<string>}>>  $conditionalFields
     * @return array<string, true>|null
     */
    private function nestedConditionParents(array $conditionalFields): ?array
    {
        $parents = [];

        foreach ($conditionalFields as $conditions) {
            foreach ($conditions as $condition) {
                if (! str_contains($condition['field'], '*')) {
                    continue;
                }

                $wildcard = strpos($condition['field'], '.*');

                if ($wildcard === false || str_contains($condition['field'], '\\')) {
                    return null;
                }

                $parents[substr($condition['field'], 0, $wildcard)] = true;
            }
        }

        return $parents;
    }

    /**
     * @param  array<array-key, mixed>  $itemData
     * @param  array<string, true>  $nestedParents
     */
    private function holdsNestedItems(array $itemData, array $nestedParents): bool
    {
        foreach (array_keys($nestedParents) as $path) {
            $nested = Arr::get($itemData, $path);

            if (is_array($nested) && $nested !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validates one item with the full rules in Laravel.
     *
     * @param  array<string, mixed>  $itemData
     * @param  array<string, mixed>  $itemRules
     * @param  array<string, string>  $itemMessages
     * @param  array<string, string>  $itemAttributes
     * @param  array<int|string, ExcludedFields>  $excludedFields
     * @param  array<string, list<string>>  $errors
     */
    private function validateWhole(int|string $index, array $itemData, array $itemRules, array $itemMessages, array $itemAttributes, string $parent, bool $isScalar, ItemAttributeNames $names, array &$excludedFields, array &$errors): bool
    {
        $validator = ItemValidator::makeItemValidator($itemData, $itemRules, $itemMessages, $itemAttributes);

        $names->forItem($validator, $index, $itemData);

        $passes = $validator->passes();
        $excluded = WildcardValidatedData::excludedTree(array_flip($this->excludedAttributes($validator)));

        if ($excluded !== false) {
            $excludedFields[$index] = $excluded;
        }

        return ! $passes && $this->errors->collectErrors($validator, $parent, $index, $isScalar, $errors, $this->stopAt);
    }

    /**
     * @return array<string, string>
     */
    private function excludedAttributes(BaseValidator $validator): array
    {
        return (fn (): array => $this->excludeAttributes)->call($validator);
    }
}
