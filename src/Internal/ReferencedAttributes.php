<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

/**
 * Finds the expanded attributes whose rules Laravel reads to validate another
 * attribute: the fields that dependent rules name. `required_if:items.0.flag,true`
 * reads the `boolean` of items.0.flag, `after:items.0.start` reads its
 * `date_format`. A fast check must not remove those rules from the validator.
 *
 * Every expanded attribute of a pattern has the same rules, so the first one
 * stands for the pattern.
 *
 * @internal Implementation detail of `PreparesOptimizedRules`.
 */
final class ReferencedAttributes
{
    /**
     * @param  array<string, mixed>  $rules  expanded rules
     * @param  array<string, list<string>>  $implicitAttributes  pattern => expanded attributes
     * @return array<string, true>
     */
    public static function find(array $rules, array $implicitAttributes): array
    {
        $patternOf = [];
        $sources = [];

        foreach ($implicitAttributes as $pattern => $attributes) {
            $sources[] = $attributes[0] ?? null;

            foreach ($attributes as $attribute) {
                $patternOf[$attribute] = $pattern;
            }
        }

        foreach (array_keys($rules) as $attribute) {
            if (! isset($patternOf[$attribute])) {
                $sources[] = $attribute;
            }
        }

        $fieldLocalRules = new FieldLocalRules();
        $referenced = [];

        foreach ($sources as $source) {
            if ($source === null || ! array_key_exists($source, $rules)) {
                continue;
            }

            foreach ($fieldLocalRules->references($rules[$source]) as $field) {
                // A wildcard reference names a pattern; Laravel resolves it per attribute.
                $attributes = $implicitAttributes[$field] ?? (isset($patternOf[$field]) ? $implicitAttributes[$patternOf[$field]] : [$field]);
                $referenced += array_fill_keys($attributes, true);
            }
        }

        return $referenced;
    }
}
