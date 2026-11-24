<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

/**
 * Finds the expanded attributes whose rules Laravel reads to validate another
 * attribute: the fields that dependent rules name, when they have a rule that
 * Laravel reads. `required_if:items.0.flag,true` reads the `boolean` of
 * items.0.flag, `after:items.0.start` reads its `date_format`. A fast check
 * must not remove those rules from the validator while a dependent attribute
 * is still validated.
 *
 * Every expanded attribute of a pattern has the same rules, so the first one
 * stands for the pattern.
 *
 * @internal Implementation detail of `PreparesOptimizedRules`.
 */
final class ReferencedAttributes
{
    /**
     * Each referenced attribute, with the attributes whose rules name it, or
     * false when those are not known.
     *
     * @param  array<string, mixed>  $rules  expanded rules
     * @param  array<string, list<string>>  $implicitAttributes  pattern => expanded attributes
     * @return array<string, list<string>|false>
     */
    public static function withDependents(array $rules, array $implicitAttributes): array
    {
        $patternOf = [];
        $sources = [];

        foreach ($implicitAttributes as $pattern => $attributes) {
            $sources[] = [$pattern, $attributes];

            foreach ($attributes as $attribute) {
                $patternOf[$attribute] = $pattern;
            }
        }

        foreach (array_keys($rules) as $attribute) {
            if (! isset($patternOf[$attribute])) {
                $sources[] = [null, [$attribute]];
            }
        }

        $fieldLocalRules = new FieldLocalRules();
        $referenced = [];

        foreach ($sources as [$pattern, $dependents]) {
            $source = $dependents[0] ?? null;

            if ($source === null || ! array_key_exists($source, $rules)) {
                continue;
            }

            foreach ($fieldLocalRules->references($rules[$source]) as $field) {
                // A wildcard reference names a pattern; Laravel resolves it per attribute.
                $attributes = $implicitAttributes[$field] ?? (isset($patternOf[$field]) ? $implicitAttributes[$patternOf[$field]] : [$field]);

                if (FieldLocalRules::hasRuleReadByDependents($rules[$attributes[0]] ?? null)) {
                    $referenced = self::addDependents($referenced, $attributes, $field, $pattern, $dependents);
                }
            }
        }

        return $referenced;
    }

    /**
     * @param  array<string, list<string>|false>  $referenced
     * @param  list<string>  $attributes  the referenced attributes
     * @param  list<string>  $dependents  the attributes of the pattern whose rule names the field
     * @return array<string, list<string>|false>
     */
    private static function addDependents(array $referenced, array $attributes, string $field, ?string $pattern, array $dependents): array
    {
        $paired = str_contains($field, '*') ? self::pairByKeys($field, $pattern, $dependents) : null;

        foreach ($attributes as $attribute) {
            $known = $referenced[$attribute] ?? [];

            if ($known === false || $paired === false) {
                $referenced[$attribute] = false;

                continue;
            }

            $referenced[$attribute] = [...$known, ...($paired === null ? $dependents : $paired[$attribute] ?? [])];
        }

        return $referenced;
    }

    /**
     * The dependents per attribute that the field names for them, with each
     * `*` replaced by the keys of the dependent, as Laravel replaces them.
     * False when Laravel would not resolve the field like this.
     *
     * @param  list<string>  $dependents
     * @return array<string, list<string>>|false
     */
    private static function pairByKeys(string $field, ?string $pattern, array $dependents): array|false
    {
        if ($pattern === null || str_contains($field, '%')) {
            return false;
        }

        $patternSegments = explode('.', $pattern);
        $wildcards = array_keys($patternSegments, '*', true);

        if (substr_count($field, '*') > count($wildcards)) {
            return false;
        }

        $paired = [];

        foreach ($dependents as $dependent) {
            $segments = explode('.', $dependent);

            if (count($segments) !== count($patternSegments)) {
                return false;
            }

            $keys = array_map(static fn (int $index): string => $segments[$index], $wildcards);
            $paired[vsprintf(str_replace('*', '%s', $field), $keys)][] = $dependent;
        }

        return $paired;
    }
}
