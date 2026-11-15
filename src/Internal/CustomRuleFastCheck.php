<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use SanderMuller\FluentValidation\FastCheckCompiler;

/**
 * Fast-checks a flat field whose rule list mixes fast-checkable strings with
 * plain `ValidationRule` objects, such as `['required', 'string', new
 * MinWords(100)]`. The check calls each object's `validate()` as Laravel's
 * InvokableValidationRule does, and only when Laravel would run it: the
 * field is present, not a blank string, and not null under `nullable`.
 *
 * An item that fails goes to Laravel for its messages, so an object rule
 * runs twice for that item.
 *
 * Objects that read the validator or its data (`DataAwareRule`,
 * `ValidatorAwareRule`) and implicit rules (an `implicit` property, as
 * InvokableValidationRule::make() reads it) stay with Laravel.
 *
 * @internal Implementation detail of `ItemRuleCompiler::buildFastChecks`.
 */
final class CustomRuleFastCheck
{
    /**
     * @param  array<string, mixed>  $rules
     * @return array{0: list<Closure(array<string, mixed>): bool>, 1: array<string, mixed>} checks, then the other rules
     */
    public static function split(array $rules): array
    {
        $checks = [];

        foreach ($rules as $field => $rule) {
            $check = is_array($rule) ? self::build((string) $field, $rule) : null;

            if ($check instanceof Closure) {
                $checks[] = $check;
                unset($rules[$field]);
            }
        }

        return [$checks, $rules];
    }

    /**
     * @param  array<int|string, mixed>  $rule
     * @return (Closure(array<string, mixed>): bool)|null
     */
    private static function build(string $field, array $rule): ?Closure
    {
        if (str_contains($field, '.') || str_contains($field, '*')) {
            return null;
        }

        $parts = self::partition($rule);

        if ($parts === null) {
            return null;
        }

        [$strings, $objects] = $parts;
        $valueCheck = $strings === [] ? static fn (mixed $value): bool => true : FastCheckCompiler::compile(implode('|', $strings));

        if ($objects === [] || ! $valueCheck instanceof Closure) {
            return null;
        }

        $nullable = in_array('nullable', $strings, true);

        return static function (array $data) use ($field, $valueCheck, $objects, $nullable): bool {
            $value = $data[$field] ?? null;

            if (! $valueCheck($value)) {
                return false;
            }

            // Laravel's isValidatable() for a non-implicit rule.
            if (! array_key_exists($field, $data) || (is_string($value) && trim($value) === '') || ($value === null && $nullable)) {
                return true;
            }

            foreach ($objects as $object) {
                if (! self::passes($object, $field, $value)) {
                    return false;
                }
            }

            return true;
        };
    }

    /**
     * @param  array<int|string, mixed>  $rule
     * @return array{0: list<string>, 1: list<ValidationRule>}|null strings and objects, or null for any other part
     */
    private static function partition(array $rule): ?array
    {
        $strings = [];
        $objects = [];

        foreach ($rule as $part) {
            if (is_string($part) && ! str_contains($part, '|')) {
                $strings[] = $part;
            } elseif (self::isPlainRule($part)) {
                $objects[] = $part;
            } else {
                return null;
            }
        }

        return [$strings, $objects];
    }

    /**
     * @phpstan-assert-if-true ValidationRule $rule
     */
    private static function isPlainRule(mixed $rule): bool
    {
        return $rule instanceof ValidationRule
            && ! $rule instanceof DataAwareRule
            && ! $rule instanceof ValidatorAwareRule
            && (! property_exists($rule, 'implicit') || $rule->implicit === false);
    }

    private static function passes(ValidationRule $rule, string $field, mixed $value): bool
    {
        $failed = false;

        $rule->validate($field, $value, static function (string $attribute, ?string $message = null) use (&$failed): PotentiallyTranslatedString {
            $failed = true;

            return new PotentiallyTranslatedString($message ?? $attribute, Container::getInstance()->make(Translator::class));
        });

        return ! $failed;
    }
}
