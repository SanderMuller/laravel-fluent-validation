<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Validation\ConditionalRules;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\NotIn;
use Illuminate\Validation\ValidationRuleParser;

/**
 * Explodes rules as Laravel's setRules() does, without its conditional-rule
 * filter. That filter builds a collection per attribute and costs more than
 * the explode, but changes nothing when no rule is conditional.
 *
 * @internal Implementation detail of `OptimizedRules`.
 */
final class UnconditionalRules
{
    /**
     * The exploded rules and implicit attributes, or null when a rule is
     * conditional or Arrayable, or a key has an escaped dot, which needs a
     * placeholder.
     *
     * @param  array<array-key, mixed>  $data  the validator's parsed data
     * @param  array<array-key, mixed>  $rules
     * @return array{rules: array<array-key, mixed>, implicitAttributes: array<array-key, mixed>}|null
     */
    public static function explode(array $data, array $rules): ?array
    {
        if (self::needsLaravel($rules)) {
            return null;
        }

        $plain = self::explodePlain($rules);

        if ($plain !== null) {
            return ['rules' => $plain, 'implicitAttributes' => []];
        }

        $response = (new ValidationRuleParser($data))->explode($rules);

        return is_array($response->rules) && is_array($response->implicitAttributes)
            ? ['rules' => $response->rules, 'implicitAttributes' => $response->implicitAttributes]
            : null;
    }

    /**
     * The rules exploded as Laravel's parser explodes them, or null when a key
     * has a wildcard or a rule holds an object other than `in` or `not_in`.
     * The attributes of one pattern come in a row with the same rules, so they
     * share one exploded list.
     *
     * @param  array<array-key, mixed>  $rules
     * @return array<array-key, list<mixed>>|null
     */
    private static function explodePlain(array $rules): ?array
    {
        $previousRule = null;
        $previous = [];

        foreach ($rules as $attribute => $rule) {
            if (str_contains((string) $attribute, '*')) {
                return null;
            }

            if ($rule !== $previousRule || $previous === []) {
                $previousRule = $rule;
                $previous = self::explodeOne($rule);

                if ($previous === null) {
                    return null;
                }
            }

            $rules[$attribute] = $previous;
        }

        return $rules;
    }

    /**
     * @return list<mixed>|null
     */
    private static function explodeOne(mixed $rule): ?array
    {
        if (is_string($rule)) {
            return explode('|', $rule);
        }

        if (! is_array($rule)) {
            return null;
        }

        $parts = [];

        foreach ($rule as $part) {
            if (is_object($part)) {
                if ($part::class !== In::class && $part::class !== NotIn::class) {
                    return null;
                }

                $part = (string) $part;
            }

            $parts[] = $part;
        }

        return $parts;
    }

    /**
     * @param  array<array-key, mixed>  $rules
     */
    private static function needsLaravel(array $rules): bool
    {
        foreach ($rules as $attribute => $rule) {
            if ($rule instanceof ConditionalRules || $rule instanceof Arrayable || str_contains((string) $attribute, '\\')) {
                return true;
            }

            if (is_array($rule)) {
                foreach ($rule as $part) {
                    if ($part instanceof ConditionalRules) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
