<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Validation\ConditionalRules;
use Illuminate\Validation\ValidationRuleParser;

/**
 * Explodes rules as Laravel's setRules() does, without its conditional-rule
 * filter. That filter builds a collection per attribute and costs more than
 * the explode, but changes nothing when no rule is conditional.
 *
 * @internal Implementation detail of `HasFluentRules`.
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

        $response = (new ValidationRuleParser($data))->explode($rules);

        return is_array($response->rules) && is_array($response->implicitAttributes)
            ? ['rules' => $response->rules, 'implicitAttributes' => $response->implicitAttributes]
            : null;
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
