<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SanderMuller\FluentValidation\Exceptions\BatchLimitExceededException;

/**
 * Remaps `BatchLimitExceededException` to `ValidationException` at the
 * documented entry points (`HasFluentRules`, `RuleSet::validate`,
 * `RuleSet::check`) so callers see the package's standard validation
 * exception type rather than a raw `RuntimeException`.
 *
 * The underlying throw happens at validator-construction time (from
 * `BatchDatabaseChecker::buildVerifier` or `HasFluentRules`'s
 * parent-max assertion), which means FormRequest class-local hooks like
 * `failedValidation()` do NOT run — the validator is never built. The
 * remap's job is limited to normalising the exception type seen by
 * `try { ... } catch (ValidationException $e) { ... }` sites and by
 * Laravel's global exception handler.
 *
 * @internal
 */
final class BatchLimitRemap
{
    public static function toValidationException(BatchLimitExceededException $exception, string $fallbackAttribute): ValidationException
    {
        $attribute = $exception->attribute ?? $fallbackAttribute;

        $validator = Validator::make([], []);
        $validator->errors()->add($attribute, $exception->getMessage());

        return new ValidationException($validator);
    }
}
