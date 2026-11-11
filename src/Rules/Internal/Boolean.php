<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Rules\Internal;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Backs `FluentRule::boolean()->allowWords()`: accepts every value Laravel's
 * `accepted` or `declined` rule accepts, with the same strict, case-sensitive
 * comparison. Laravel has no OR between rule strings, and `Rules\AnyOf` is
 * missing before Laravel 12.8, so the union lives here.
 *
 * Do not rename the class: Laravel resolves a user-supplied `'field.boolean'`
 * message for an object rule through `{attribute}.{snake(class_basename)}`.
 *
 * @internal
 */
final class Boolean implements ValidationRule
{
    private const ACCEPTED_OR_DECLINED = [true, false, 1, 0, '1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! in_array($value, self::ACCEPTED_OR_DECLINED, true)) {
            $fail('validation.boolean')->translate();
        }
    }
}
