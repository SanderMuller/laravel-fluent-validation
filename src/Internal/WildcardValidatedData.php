<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Validation\ConditionalRules;
use Illuminate\Validation\NestedRules;
use Illuminate\Validation\Rules\ExcludeIf;
use ReflectionProperty;

/**
 * Adds the data of a wildcard group to the validated data when no top-level
 * rule covers the group's parent. Laravel's `validated()` shapes that data.
 *
 * @internal Implementation detail of `RuleSet`.
 */
final class WildcardValidatedData
{
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
     * @param  list<string>  $topRuleKeys
     * @param  array<string, array<string, mixed>>  $wildcardGroups
     * @return array<string, array<string, mixed>>
     */
    public static function uncovered(array $topRuleKeys, array $wildcardGroups): array
    {
        $uncovered = [];

        foreach ($wildcardGroups as $parent => $rules) {
            $covered = array_filter($topRuleKeys, static fn (string $key): bool => $key === $parent || str_starts_with($parent, $key . '.'));

            if ($covered === []) {
                $uncovered[$parent] = array_map(
                    static fn (mixed $rule): mixed => is_object($rule) && method_exists($rule, 'compiledRules') ? $rule->compiledRules() : $rule,
                    $rules,
                );
            }
        }

        return $uncovered;
    }

    /**
     * Laravel removes an excluded field from the validated data, and only the
     * per-item validator knows which fields it excluded. The group then needs
     * full expansion.
     *
     * @param  array<string, array<string, mixed>>  $uncoveredGroups
     */
    public static function excludesFields(array $uncoveredGroups, string $parent): bool
    {
        foreach ($uncoveredGroups[$parent] ?? [] as $rule) {
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
