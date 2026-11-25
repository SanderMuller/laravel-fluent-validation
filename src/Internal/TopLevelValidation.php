<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ConditionalRules;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\ValidationRuleParser;
use Illuminate\Validation\Validator as BaseValidator;

/**
 * Validates the rules without a wildcard, which Laravel runs before every
 * wildcard rule.
 *
 * @internal Implementation detail of `RuleSet`.
 */
final class TopLevelValidation
{
    /**
     * Validate top-level rules with fast checks alone. Returns the validated
     * data, or null when Laravel must run: no fast checks, a slow rule, a
     * failed check (Laravel builds the error messages), or a dotted key
     * (children() needs Laravel's nested lookup and validated-data shaping).
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $compiled
     * @return array<string, mixed>|null
     */
    public static function fastValidated(ItemRuleCompiler $compiler, array $data, array $compiled): ?array
    {
        foreach (array_keys($compiled) as $key) {
            // PHP stores a key such as '1' as an integer.
            if (str_contains(strval($key), '.')) {
                return null;
            }
        }

        [$fastChecks, $slowRules] = $compiler->buildFastChecks($compiled);

        if ($slowRules !== [] || $fastChecks === []) {
            return null;
        }

        foreach ($fastChecks as $check) {
            if (! $check($data)) {
                return null;
            }
        }

        return array_intersect_key($data, $compiled);
    }

    /**
     * The validated data, the errors, the failed validator and the
     * attributes an exclude rule removed. With stopOnFirstFailure a failure
     * throws, because Laravel then reports no wildcard error.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rules  compiled
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>, 2: ValidatorContract|null, 3: list<string>}
     *
     * @throws ValidationException
     */
    public static function run(array $data, array $rules, array $messages, array $attributes, bool $stopOnFirstFailure): array
    {
        $validator = Validator::make($data, $rules, $messages, $attributes)->stopOnFirstFailure($stopOnFirstFailure);

        if (! $validator->fails()) {
            /** @var array<string, mixed> $validated */
            $validated = $validator->validated();

            return [$validated, [], null, self::excludedAttributes($validator)];
        }

        if ($stopOnFirstFailure) {
            throw new ValidationException($validator);
        }

        /** @var array<string, list<string>> $errors */
        $errors = $validator->errors()->toArray();

        return [[], $errors, $validator, self::excludedAttributes($validator)];
    }

    /**
     * @return list<string>
     */
    private static function excludedAttributes(ValidatorContract $validator): array
    {
        if (! $validator instanceof BaseValidator) {
            return [];
        }

        return array_values((fn (): array => $this->excludeAttributes)->call($validator));
    }

    /**
     * The flattened rules for a fully expanded validator: the top-level rules
     * and the wildcard rules of the given groups, in rule order.
     *
     * @param  array<string, mixed>  $flatRules
     * @param  array<string, array<string, mixed>>  $wildcardGroups
     * @return array<string, mixed>
     */
    public static function standardRules(array $flatRules, array $wildcardGroups): array
    {
        return array_filter(
            $flatRules,
            static fn (string $field): bool => ! str_contains($field, '*') || isset($wildcardGroups[substr($field, 0, (int) strpos($field, '.*'))]),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Throws the errors. After a top-level failure it throws the top-level
     * validator with the wildcard errors added, so `check()` still gives
     * access to its failed rules.
     *
     * @param  array<string, list<string>>  $errors  the top-level errors first
     *
     * @throws ValidationException
     */
    public static function fail(array $errors, ?ValidatorContract $topValidator): never
    {
        $validator = $topValidator ?? Validator::make([], []);
        $bag = $validator->errors();
        $known = $bag->toArray();

        foreach ($errors as $field => $fieldErrors) {
            foreach (array_slice($fieldErrors, count($known[$field] ?? [])) as $fieldError) {
                $bag->add($field, $fieldError);
            }
        }

        throw new ValidationException($validator);
    }

    /**
     * Rules that limit the size or type of a value. They read nothing else
     * and run no query, so they can run here and again in Laravel's validator.
     */
    private const STRUCTURAL_RULES = ['Array', 'List', 'Required', 'Filled', 'Present', 'Nullable', 'Sometimes', 'Bail', 'Min', 'Max', 'Size', 'Between'];

    /**
     * The errors of the structural rules on a group's parent or a key above
     * it, for {@see groupsToValidate()}. Other rules, such as a closure or a
     * database rule, run only once, in Laravel's validator.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rules  compiled
     * @param  array<string, array<string, mixed>>  $wildcardGroups
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     * @return array<string, list<string>>
     */
    public static function parentErrors(array $data, array $rules, array $wildcardGroups, array $messages, array $attributes): array
    {
        $parentRules = [];

        foreach ($rules as $key => $rule) {
            $isParent = array_filter(
                array_keys($wildcardGroups),
                static fn (int|string $parent): bool => (string) $parent === (string) $key || str_starts_with((string) $parent, $key . '.'),
            ) !== [];
            $structural = $isParent ? self::structuralParts($data, (string) $key, $rule) : [];

            if ($structural !== []) {
                $parentRules[$key] = $structural;
            }
        }

        if ($parentRules === []) {
            return [];
        }

        /** @var array<string, list<string>> */
        return Validator::make($data, $parentRules, $messages, $attributes)->errors()->toArray();
    }

    /**
     * The structural parts of a rule, read with Laravel's own parser. A
     * conditional rule with a closure condition is left out, so the closure
     * runs only once, in Laravel's validator.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function structuralParts(array $data, string $key, mixed $rule): array
    {
        /** @var array<string, list<mixed>> $parsed */
        $parsed = (new ValidationRuleParser($data))->explode(ValidationRuleParser::filterConditionalRules([$key => self::withoutClosureConditions($rule)], $data))->rules;

        return array_values(array_filter(
            $parsed[$key] ?? [],
            static fn (mixed $part): bool => is_string($part) && in_array(ValidationRuleParser::parse($part)[0], self::STRUCTURAL_RULES, true),
        ));
    }

    private static function withoutClosureConditions(mixed $rule): mixed
    {
        $hasClosure = static fn (mixed $part): bool => $part instanceof ConditionalRules
            && is_callable((fn (): mixed => $this->condition)->call($part));

        if ($hasClosure($rule)) {
            return [];
        }

        return is_array($rule) ? array_values(array_filter($rule, static fn (mixed $part): bool => ! $hasClosure($part))) : $rule;
    }

    /**
     * The items without those a top-level exclude rule removed, such as
     * `items.0`. Laravel skips the rules below an excluded attribute.
     *
     * @param  array<array-key, mixed>  $items
     * @param  list<string>  $excluded  the attributes the top-level validator excluded
     * @return array<array-key, mixed>
     */
    public static function withoutExcludedItems(array $items, string $parent, array $excluded): array
    {
        foreach ($excluded as $attribute) {
            $index = str_starts_with($attribute, $parent . '.') ? substr($attribute, strlen($parent) + 1) : '';

            if ($index !== '' && ! str_contains($index, '.')) {
                unset($items[$index]);
            }
        }

        return $items;
    }

    /**
     * Did a top-level exclude rule remove an attribute inside an item, such
     * as `items.0.tags.1`? The per-item validator cannot skip its rules.
     *
     * @param  list<string>  $excluded  the attributes the top-level validator excluded
     */
    public static function excludesInsideItem(array $excluded, string $parent): bool
    {
        foreach ($excluded as $attribute) {
            if (str_starts_with($attribute, $parent . '.') && str_contains(substr($attribute, strlen($parent) + 1), '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The groups to validate per item. A group whose parent, or a key above
     * it, failed a top-level rule is left out: a parent that fails `max` can
     * hold any number of items, and each could run a database query.
     *
     * @param  array<string, array<string, mixed>>  $wildcardGroups
     * @param  array<string, list<string>>  $errors
     * @return array<string, array<string, mixed>>
     */
    public static function groupsToValidate(array $wildcardGroups, array $errors): array
    {
        if ($errors === []) {
            return $wildcardGroups;
        }

        return array_filter(
            $wildcardGroups,
            static fn (string $parent): bool => array_filter(
                array_keys($errors),
                static fn (int|string $key): bool => $key === $parent || str_starts_with($parent, $key . '.'),
            ) === [],
            ARRAY_FILTER_USE_KEY,
        );
    }
}
