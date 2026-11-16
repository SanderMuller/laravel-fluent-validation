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
 * Finds which fields' rules Laravel reads to validate a field. Laravel reads
 * the field's own rules, and the rules of each field that a dependent rule
 * names: `after:start` reads the `date_format` of start, `required_if:flag,true`
 * reads the `boolean` of flag. So a failing item can send Laravel only the
 * fields that failed, plus the fields they name.
 *
 * A custom extension or a `ValidatorAwareRule` can read any rule through the
 * validator, so a rule set with one keeps all fields.
 *
 * @internal Implementation detail of `ItemValidator`.
 */
final class FieldLocalRules
{
    /** @var array<string, int> studly names of the rules whose parameters name fields */
    private readonly array $dependentRules;

    public function __construct()
    {
        $this->dependentRules = array_flip([...WildcardReferenceRewriter::dependentRules(), 'DateEquals']);
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    public static function canReadAnyField(array $rules): bool
    {
        foreach ($rules as $rule) {
            $parts = self::parts($rule);

            if ($parts === null) {
                return true;
            }

            foreach ($parts as [$name]) {
                if ($name !== '' && ! method_exists(BaseValidator::class, 'validate' . $name)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The fields that the dependent rules in one field's rule name.
     *
     * @return list<string>
     */
    public function references(mixed $rule): array
    {
        $fields = [];

        foreach (self::parts($rule) ?? [] as [$name, $parameters]) {
            if (isset($this->dependentRules[$name])) {
                $count = isset(WildcardReferenceRewriter::VALUE_CONDITIONALS[$name]) ? 1 : count($parameters);
                $fields = [...$fields, ...array_slice($parameters, 0, $count)];
            }
        }

        return $fields;
    }

    /**
     * Each rule as a studly name and its parameters, or null for an object
     * that may read the validator.
     *
     * @return list<array{0: string, 1: list<string>}>|null
     */
    private static function parts(mixed $rule): ?array
    {
        $parts = [];

        foreach (is_array($rule) ? $rule : [$rule] as $part) {
            if (is_string($part)) {
                foreach (is_string($rule) ? explode('|', $part) : [$part] as $token) {
                    $pieces = explode(':', $token, 2);
                    $parts[] = [Str::studly(trim($pieces[0])), isset($pieces[1]) ? self::strings(str_getcsv($pieces[1], escape: '\\')) : []];
                }
            } elseif (is_array($part) && isset($part[0]) && is_string($part[0])) {
                $parts[] = [Str::studly(trim($part[0])), self::strings(array_slice($part, 1))];
            } elseif (! self::isLocalObject($part)) {
                return null;
            }
        }

        return $parts;
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        return array_values(array_filter($values, is_string(...)));
    }

    private static function isLocalObject(mixed $part): bool
    {
        return $part instanceof In
            || $part instanceof NotIn
            || $part instanceof Closure
            || ($part instanceof ValidationRule && ! $part instanceof ValidatorAwareRule);
    }
}
