<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Validation\ConditionalRules;
use Illuminate\Validation\NestedRules;
use Illuminate\Validation\Rules\ExcludeIf;
use ReflectionProperty;
use SanderMuller\FluentValidation\FastCheckCompiler;

/**
 * Adds the data of a wildcard group to the validated data when no top-level
 * rule covers the group's parent. Laravel's `validated()` shapes that data.
 *
 * @internal Implementation detail of `RuleSet`.
 *
 * @phpstan-type ExcludedFields ExcludedFieldTree|false
 */
final class WildcardValidatedData
{
    /**
     * Soft cap on the tree cache, as in {@see FastCheckCompiler}: the names
     * can come from request data, so the cache could grow without bound on a
     * long-lived worker.
     */
    private const TREE_CACHE_MAX = 1024;

    private const TREE_CACHE_KEY_MAX = 2048;

    /**
     * Trees per set of excluded field names.
     *
     * @var array<string, ExcludedFields>
     */
    private static array $trees = [];

    /**
     * @param  array<string, mixed>  $validated  the validated top-level data
     * @param  array<string, array<string, mixed>>  $groups  {@see uncovered()}
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function add(array $validated, array $groups, array $data): array
    {
        $rules = [];

        foreach ($groups as $parent => $groupRules) {
            foreach ($groupRules as $key => $rule) {
                $rules[$parent . '.*' . ($key === '*' ? '' : '.' . $key)] = $rule;
            }
        }

        if ($rules === []) {
            return $validated;
        }

        // The data passed validation, so an empty message bag lets validated()
        // shape the data without running the rules again.
        $validator = Validator::make($data, $rules);
        (new ReflectionProperty($validator, 'messages'))->setValue($validator, new MessageBag());

        /** @var array<string, mixed> */
        return array_replace_recursive($validated, $validator->validated());
    }

    /**
     * The wildcard groups with no top-level rule for the parent or one of its
     * ancestors, with each group's rules compiled. Such a rule puts the
     * parent in the validated data, or excludes it.
     *
     * A top-level rule below the parent, such as `items.0.secret`, joins the
     * group when it may exclude a field, so the group gets full expansion.
     *
     * @param  array<string, mixed>  $topRules  compiled
     * @param  array<string, array<string, mixed>>  $wildcardGroups
     * @return array<string, array<string, mixed>>
     */
    public static function uncovered(array $topRules, array $wildcardGroups): array
    {
        $uncovered = [];

        foreach ($wildcardGroups as $parent => $rules) {
            $rules = array_map(
                static fn (mixed $rule): mixed => is_object($rule) && method_exists($rule, 'compiledRules') ? $rule->compiledRules() : $rule,
                $rules,
            );

            foreach ($topRules as $key => $rule) {
                if ($key === $parent || str_starts_with($parent, $key . '.')) {
                    continue 2;
                }

                if (str_starts_with($key, $parent . '.') && self::excludes([$rule])) {
                    $rules[substr($key, strlen($parent) + 1)] = $rule;
                }
            }

            $uncovered[$parent] = $rules;
        }

        return $uncovered;
    }

    /**
     * Laravel removes an excluded field from the validated data, and only the
     * per-item validator knows which fields it excluded. A group without a
     * parent rule then needs full expansion.
     *
     * @param  array<string, array<string, mixed>>  $uncoveredGroups
     */
    public static function excludesFields(array $uncoveredGroups, string $parent): bool
    {
        return self::excludes($uncoveredGroups[$parent] ?? []);
    }

    /**
     * Remove the fields the per-item validator excluded from the validated
     * data, which holds a covered group's parent as the input gave it.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<string, array<int|string, ExcludedFields>>  $excludedFields  per parent, per item index
     * @return array<string, mixed>
     */
    public static function withoutExcluded(array $validated, array $excludedFields): array
    {
        foreach ($excludedFields as $parent => $byIndex) {
            $path = explode('.', $parent);
            $items = $byIndex === [] ? null : self::valueAt($validated, $path);
            if (is_array($items)) {
                $validated = self::replaceAt($validated, $path, self::withoutItemFields($items, $byIndex));
            }
        }

        return $validated;
    }

    /**
     * @param  array<mixed, mixed>  $items
     * @param  array<int|string, ExcludedFields>  $byIndex
     * @return array<mixed, mixed>
     */
    private static function withoutItemFields(array $items, array $byIndex): array
    {
        foreach ($byIndex as $index => $fields) {
            if ($fields === false || ! isset($items[$index]) || ! is_array($items[$index])) {
                continue;
            }

            $item = array_diff_key($items[$index], $fields->keys);

            foreach ($fields->children as $key => $child) {
                if (isset($item[$key]) && is_array($item[$key])) {
                    $item[$key] = $child->children === [] ? array_diff_key($item[$key], $child->keys) : $child->removeFrom($item[$key]);
                }
            }

            $items[$index] = $item;
        }

        return $items;
    }

    /**
     * The tree of the fields an item excluded, keyed by field; false when
     * no field can be removed.
     *
     * @param  array<array-key, mixed>  $excluded
     * @return ExcludedFields
     */
    public static function excludedTree(array $excluded): ExcludedFieldTree|false
    {
        if ($excluded === []) {
            return false;
        }

        $key = implode("\0", array_keys($excluded));

        // A name with a NUL byte could give two sets one key. Names can come
        // from request data, so a long key stays out of the cache.
        if (strlen($key) > self::TREE_CACHE_KEY_MAX || substr_count($key, "\0") !== count($excluded) - 1) {
            return self::buildTree($excluded);
        }

        if (isset(self::$trees[$key])) {
            return self::$trees[$key];
        }

        if (count(self::$trees) >= self::TREE_CACHE_MAX) {
            self::$trees = [];
        }

        return self::$trees[$key] = self::buildTree($excluded);
    }

    /**
     * @param  array<array-key, mixed>  $excluded
     * @return ExcludedFields
     */
    private static function buildTree(array $excluded): ExcludedFieldTree|false
    {
        $paths = [];

        foreach (array_keys($excluded) as $field) {
            $name = (string) $field;

            // The reducer decides a field below a nested wildcard for the
            // whole item, not for each nested item, so it removes nothing.
            // A field with an escaped dot has no plain path to remove.
            if (! str_contains($name, '*') && ! str_contains($name, '\\')) {
                $paths[] = explode('.', $name);
            }
        }

        return ExcludedFieldTree::fromPaths($paths) ?? false;
    }

    /**
     * Read a value by path segments, the same way {@see replaceAt()} writes it.
     *
     * @param  array<mixed, mixed>  $data
     * @param  list<string>  $path
     */
    private static function valueAt(array $data, array $path): mixed
    {
        foreach ($path as $segment) {
            if (! is_array($data) || ! array_key_exists($segment, $data)) {
                return null;
            }

            $data = $data[$segment];
        }

        return $data;
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>  $data
     * @param  list<string>  $path
     * @param  array<mixed, mixed>  $value
     * @return array<TKey, mixed>
     */
    private static function replaceAt(array $data, array $path, array $value): array
    {
        $key = array_shift($path);

        if ($key === null || ! array_key_exists($key, $data)) {
            return $data;
        }

        $data[$key] = $path === [] ? $value : self::replaceAt(is_array($data[$key]) ? $data[$key] : [], $path, $value);

        return $data;
    }

    /**
     * Can a rule exclude its attribute?
     *
     * @param  array<array-key, mixed>  $rules
     */
    public static function excludes(array $rules): bool
    {
        foreach ($rules as $rule) {
            foreach (self::ruleParts($rule) as $part) {
                if (self::mayExclude($part)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** A conditional or nested rule can add an exclude rule only at validation time. */
    private static function mayExclude(mixed $part): bool
    {
        if ($part instanceof ExcludeIf || $part instanceof ConditionalRules || $part instanceof NestedRules) {
            return true;
        }

        // Laravel reads `exclude_if`, `excludeIf` and `ExcludeIf` as one rule.
        return is_string($part) && str_starts_with(Str::studly(explode(':', $part, 2)[0]), 'Exclude');
    }

    /** @return list<mixed> */
    private static function ruleParts(mixed $rule): array
    {
        if (is_string($rule)) {
            return explode('|', $rule);
        }

        if (! is_array($rule)) {
            return [$rule];
        }

        // A tuple such as ['exclude_unless', 'items.*.type', 'box'] names its rule first.
        return array_map(
            static fn (mixed $part): mixed => is_array($part) && is_string($part[0] ?? null) ? $part[0] : $part,
            array_values($rule),
        );
    }
}
