<?php declare(strict_types=1);

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as BaseValidator;
use SanderMuller\FluentValidation\RuleSet;

/**
 * Regression guard: the per-item slow-rule path must honor a custom
 * `Validator::resolver()`. ItemValidator swaps in a MemoizingValidator for
 * speed, but only when the factory returns the plain default validator — a
 * resolver-provided subclass carries overridden behaviour a state-copy can't
 * replicate, so it must be used as-is (a Grease-greased validator, or a
 * consumer's own subclass). Before the guard, per-item validation silently ran
 * on MemoizingValidator, dropping the custom behaviour.
 */
final class CustomResolverCountingValidator extends BaseValidator
{
    public static int $passesCount = 0;

    public function passes(): bool
    {
        ++self::$passesCount;

        return parent::passes();
    }
}

final class CustomResolverAlwaysPassesRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void {}
}

/**
 * @param  array<array-key, mixed>  $data
 * @param  array<array-key, mixed>  $rules
 * @param  array<string, string>  $messages
 * @param  array<string, string>  $attributes
 */
function makeCustomResolverValidator(Translator $translator, array $data, array $rules, array $messages = [], array $attributes = []): BaseValidator
{
    return new CustomResolverCountingValidator($translator, $data, $rules, $messages, $attributes);
}

it('uses the custom resolver validator for per-item slow-rule validation', function (): void {
    CustomResolverCountingValidator::$passesCount = 0;

    Validator::resolver(makeCustomResolverValidator(...));

    // The custom Rule object makes `rows.*.a` non-fast-checkable, forcing the
    // per-item slow path (makeItemValidator) rather than a fast-check closure.
    $rules = [
        'rows' => 'required|array',
        'rows.*.a' => ['required', 'string', new CustomResolverAlwaysPassesRule()],
    ];

    RuleSet::from($rules)->validate(['rows' => [['a' => 'x'], ['a' => 'y']]]);

    // Top-level `rows` validation is one passes() call; each of the 2 items adds
    // another via the per-item validator. If the resolver were bypassed for
    // per-item validation, only the top-level call would be counted.
    expect(CustomResolverCountingValidator::$passesCount)->toBeGreaterThan(1);
});
