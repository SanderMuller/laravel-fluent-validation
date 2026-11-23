<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

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
            if (str_contains($key, '.')) {
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
     * The validated data, the errors and the failed validator. With
     * stopOnFirstFailure a failure throws, because Laravel then reports no
     * wildcard error.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rules  compiled
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>, 2: ValidatorContract|null}
     *
     * @throws ValidationException
     */
    public static function run(array $data, array $rules, array $messages, array $attributes, bool $stopOnFirstFailure): array
    {
        $validator = Validator::make($data, $rules, $messages, $attributes)->stopOnFirstFailure($stopOnFirstFailure);

        if (! $validator->fails()) {
            /** @var array<string, mixed> $validated */
            $validated = $validator->validated();

            return [$validated, [], null];
        }

        if ($stopOnFirstFailure) {
            throw new ValidationException($validator);
        }

        /** @var array<string, list<string>> $errors */
        $errors = $validator->errors()->toArray();

        return [[], $errors, $validator];
    }

    /**
     * The flattened rules for a fully expanded validator: the top-level rules
     * first, then the wildcard rules of the given groups. Laravel lists the
     * attributes a wildcard rule expands to after every top-level attribute.
     *
     * @param  array<string, mixed>  $flatRules
     * @param  array<string, array<string, mixed>>  $wildcardGroups
     * @return array<string, mixed>
     */
    public static function standardRules(array $flatRules, array $wildcardGroups): array
    {
        $topRules = [];
        $wildcardRules = [];

        foreach ($flatRules as $field => $rule) {
            if (! str_contains($field, '*')) {
                $topRules[$field] = $rule;
            } elseif (isset($wildcardGroups[substr($field, 0, (int) strpos($field, '.*'))])) {
                $wildcardRules[$field] = $rule;
            }
        }

        return $topRules + $wildcardRules;
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
    private const STRUCTURAL_RULES = ['array', 'list', 'required', 'filled', 'present', 'nullable', 'sometimes', 'bail', 'min', 'max', 'size', 'between'];

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
            $structural = $isParent ? self::structuralParts($rule) : [];

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
     * @return list<string>
     */
    private static function structuralParts(mixed $rule): array
    {
        $parts = is_string($rule) ? explode('|', $rule) : (is_array($rule) ? $rule : []);

        return array_values(array_filter(
            $parts,
            static fn (mixed $part): bool => is_string($part) && in_array(explode(':', $part, 2)[0], self::STRUCTURAL_RULES, true),
        ));
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
