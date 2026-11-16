<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\NotIn;
use Illuminate\Validation\Validator as BaseValidator;

/**
 * Tells whether each field's Laravel verdict depends only on the item data
 * and the field's own rules. Then a failing item can send Laravel only the
 * fields whose fast check failed, plus the slow rules.
 *
 * A dependent rule (`gt:other`, `required_if:...`, `after:start`) can read
 * the rules of the field it names, so it disqualifies the rule set. So does
 * a custom extension or a `ValidatorAwareRule`, which can read the validator.
 *
 * @internal Implementation detail of `ItemValidator`.
 */
final class FieldLocalRules
{
    /**
     * @param  array<string, mixed>  $rules
     */
    public static function all(array $rules): bool
    {
        $dependent = array_flip([...WildcardReferenceRewriter::dependentRules(), 'DateEquals']);

        foreach ($rules as $rule) {
            foreach (self::names($rule) ?? ['Prohibits'] as $name) {
                $studly = Str::studly(trim(explode(':', $name, 2)[0]));

                if ($studly !== '' && (isset($dependent[$studly]) || ! method_exists(BaseValidator::class, 'validate' . $studly))) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * The rule names in one field's rule, or null for an object that may read the validator.
     *
     * @return list<string>|null
     */
    private static function names(mixed $rule): ?array
    {
        if (is_string($rule)) {
            return explode('|', $rule);
        }

        $names = [];

        foreach (is_array($rule) ? $rule : [$rule] as $part) {
            if (is_string($part)) {
                $names[] = $part;
            } elseif (is_array($part) && isset($part[0]) && is_string($part[0])) {
                $names[] = $part[0];
            } elseif (! self::isLocalObject($part)) {
                return null;
            }
        }

        return $names;
    }

    private static function isLocalObject(mixed $part): bool
    {
        return $part instanceof In
            || $part instanceof NotIn
            || $part instanceof Closure
            || ($part instanceof ValidationRule && ! $part instanceof ValidatorAwareRule);
    }
}
