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
