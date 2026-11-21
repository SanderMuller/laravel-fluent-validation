<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Closure;
use Illuminate\Support\Arr;
use SanderMuller\FluentValidation\FastCheckCompiler;

/**
 * Splits item rules with an exclude rule below a nested wildcard. The reducer
 * validates every other field; Laravel validates only the fields with such an
 * exclude rule, together with the fields whose rules those read. That is
 * safe only when no other field interacts with the split fields through an
 * exclusion: no exclude rule on an ancestor, no split field above another
 * field, and no rule that can read any field.
 *
 * @internal
 */
final readonly class NestedExcludeSplit
{
    /** Rules that Laravel never reads on another field, so a read field with only these needs no rules. */
    private const NOT_READ_BY_OTHERS = ['Required', 'String', 'In', 'NotIn', 'Min', 'Max', 'Bail', 'Nullable', 'Sometimes', 'Filled', 'Present'];

    /**
     * @param  array<string, mixed>  $reducerRules
     * @param  array<string, list<array{action: string, field: string, values: list<string>}>>  $reducerConditionals
     * @param  array<string, mixed>  $nestedRules  the split fields and the fields their rules read, in rule order
     * @param  list<array{0: string, 1: string, 2: list<array{action: string, field: string, values: list<string>}>, 3: Closure(mixed): bool}>|null  $decidable  per split field: the nested parent, the path below it, the exclude conditions and the check of the other rules
     */
    private function __construct(
        public array $reducerRules,
        public array $reducerConditionals,
        public array $nestedRules,
        private string $splitAttribute,
        private ?array $decidable,
    ) {}

    /**
     * @param  array<string, mixed>  $itemRules
     * @param  array<string, list<array{action: string, field: string, values: list<string>}>>  $conditionalFields
     */
    public static function for(array $itemRules, array $conditionalFields): ?self
    {
        $split = self::splitFields($conditionalFields);

        if ($split === [] || ! self::isIsolated($itemRules, $split)) {
            return null;
        }

        $nestedRules = array_filter(
            array_intersect_key($itemRules, self::withReadFields($itemRules, $split, $conditionalFields)),
            static fn (mixed $rule, string $field): bool => isset($split[$field]) || ! self::isNotReadByOthers($rule),
            ARRAY_FILTER_USE_BOTH,
        );

        if (FieldLocalRules::canReadAnyField($nestedRules)) {
            return null;
        }

        $patterns = array_map(static fn (string $field): string => str_replace('\*', '[^.]+', preg_quote($field, '/')), array_keys($split));

        return new self(
            array_diff_key($itemRules, $split),
            array_diff_key($conditionalFields, $split),
            $nestedRules,
            '/^(?:' . implode('|', $patterns) . ')$/',
            self::decidable($nestedRules, $split, $conditionalFields),
        );
    }

    /**
     * Decides the split fields of an item without Laravel: each exclude
     * condition for each nested item, then a fast check of the kept values.
     *
     * @param  array<string, mixed>  $item
     * @return list<string>|null  the excluded attributes; null when Laravel must validate the item
     */
    public function decide(array $item): ?array
    {
        if ($this->decidable === null) {
            return null;
        }

        $excluded = [];

        foreach ($this->decidable as [$parent, $path, $conditions, $check]) {
            $nested = Arr::get($item, $parent);

            foreach (is_array($nested) ? array_keys($nested) : [] as $key) {
                $attribute = "{$parent}.{$key}.{$path}";
                $concrete = array_map(static fn (array $condition): array => ['field' => str_replace('*', (string) $key, $condition['field'])] + $condition, $conditions);

                if (ItemRuleCompiler::fieldIsExcluded($concrete, $item, [])) {
                    $excluded[] = $attribute;
                } elseif (! $check(Arr::get($item, $attribute)) && (Arr::has($item, $attribute) || ! $check(null))) {
                    return null;
                }
            }
        }

        return $excluded;
    }

    /**
     * The split fields that {@see decide()} can decide: one wildcard, exclude
     * conditions on fields without rules that Laravel reads, exclude rules
     * before every other rule, and fast-checkable other rules.
     *
     * @param  array<string, mixed>  $nestedRules
     * @param  array<string, true>  $split
     * @param  array<string, list<array{action: string, field: string, values: list<string>}>>  $conditionalFields
     * @return list<array{0: string, 1: string, 2: list<array{action: string, field: string, values: list<string>}>, 3: Closure(mixed): bool}>|null
     */
    private static function decidable(array $nestedRules, array $split, array $conditionalFields): ?array
    {
        if (count($nestedRules) !== count($split)) {
            return null;
        }

        $fields = [];

        foreach (array_keys($split) as $field) {
            $conditions = $conditionalFields[$field] ?? [];
            $check = self::excludesFirst($nestedRules[$field]) ? ExcludeRuleStripper::strip($nestedRules[$field]) : null;
            $check = is_string($check) ? FastCheckCompiler::compile($check) : null;

            if (! $check instanceof Closure || substr_count($field, '*') !== 1 || ! str_contains($field, '.*.')) {
                return null;
            }

            foreach ($conditions as $condition) {
                if (substr_count($condition['field'], '*') > 1 || isset($split[$condition['field']])) {
                    return null;
                }
            }

            [$parent, $path] = explode('.*.', $field, 2);
            $fields[] = [$parent, $path, $conditions, $check];
        }

        return $fields;
    }

    /**
     * Laravel runs the rules before an exclude rule even when it excludes the
     * field, so only `bail` and `nullable` may come before the last one, and
     * every exclude rule must be an `exclude_if` or `exclude_unless`.
     */
    private static function excludesFirst(mixed $rule): bool
    {
        $names = array_column(FieldLocalRules::parts($rule) ?? [['']], 0);
        $last = 0;

        foreach ($names as $position => $name) {
            if (str_starts_with($name, 'Exclude')) {
                if (! in_array($name, ['ExcludeIf', 'ExcludeUnless'], true)) {
                    return false;
                }

                $last = $position;
            }
        }

        foreach (array_slice($names, 0, $last) as $name) {
            if (! in_array($name, ['Bail', 'Nullable', 'ExcludeIf', 'ExcludeUnless'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a concrete attribute, such as `answers.0.action.link`, belongs
     * to a split field.
     */
    public function isSplitAttribute(string $attribute): bool
    {
        return preg_match($this->splitAttribute, $attribute) === 1;
    }

    /**
     * @param  array<string, list<array{action: string, field: string, values: list<string>}>>  $conditionalFields
     * @return array<string, true>
     */
    private static function splitFields(array $conditionalFields): array
    {
        $split = [];

        foreach ($conditionalFields as $field => $conditions) {
            foreach ($conditions as $condition) {
                if (str_contains($condition['field'], '*')) {
                    $split[$field] = true;
                }
            }
        }

        return $split;
    }

    /**
     * @param  array<string, mixed>  $itemRules
     * @param  array<string, true>  $split
     */
    private static function isIsolated(array $itemRules, array $split): bool
    {
        foreach ($itemRules as $field => $rule) {
            $field = (string) $field;

            if (str_contains($field, '\\')) {
                return false;
            }

            foreach (array_keys($split) as $splitField) {
                if (str_starts_with($field, $splitField . '.')) {
                    return false;
                }

                if (str_starts_with($splitField, $field . '.') && self::mayExclude($rule)) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function isNotReadByOthers(mixed $rule): bool
    {
        $parts = FieldLocalRules::parts($rule);

        if ($parts === null) {
            return false;
        }

        foreach ($parts as [$name]) {
            if (! in_array($name, self::NOT_READ_BY_OTHERS, true)) {
                return false;
            }
        }

        return true;
    }

    private static function mayExclude(mixed $rule): bool
    {
        $parts = FieldLocalRules::parts($rule);

        if ($parts === null) {
            return true;
        }

        foreach ($parts as [$name]) {
            if (str_starts_with($name, 'Exclude')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The split fields and, transitively, the fields their rules and exclude
     * conditions name: Laravel reads the rules of those fields too.
     *
     * @param  array<string, mixed>  $itemRules
     * @param  array<string, true>  $split
     * @param  array<string, list<array{action: string, field: string, values: list<string>}>>  $conditionalFields
     * @return array<string, true>
     */
    private static function withReadFields(array $itemRules, array $split, array $conditionalFields): array
    {
        $references = new FieldLocalRules();
        $keep = $split;
        $pending = array_keys($split);

        while ($pending !== []) {
            $field = array_pop($pending);
            $reads = [...$references->references($itemRules[$field] ?? null), ...array_column($conditionalFields[$field] ?? [], 'field')];

            foreach ($reads as $read) {
                if (! isset($keep[$read]) && array_key_exists($read, $itemRules)) {
                    $keep[$read] = true;
                    $pending[] = $read;
                }
            }
        }

        return $keep;
    }
}
