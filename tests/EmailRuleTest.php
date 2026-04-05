<?php declare(strict_types=1);

use Illuminate\Contracts\Validation\ValidationRule;
use SanderMuller\FluentValidation\FluentRule;

// =========================================================================
// EmailRule
// =========================================================================

it('validates email with EmailRule', function (): void {
    $v = makeValidator(['email' => 'user@example.com'], ['email' => FluentRule::email()->required()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['email' => 'not-an-email'], ['email' => FluentRule::email()->required()]);
    expect($v->passes())->toBeFalse();
});

it('compiles EmailRule with modes', function (): void {
    expect(FluentRule::email()->compiledRules())->toBe('string|email');
    expect(FluentRule::email()->rfcCompliant()->compiledRules())->toBe('string|email:rfc');
    expect(FluentRule::email()->strict()->compiledRules())->toBe('string|email:strict');
    expect(FluentRule::email()->rfcCompliant()->preventSpoofing()->compiledRules())->toBe('string|email:rfc,spoof');
    expect(FluentRule::email()->validateMxRecord()->compiledRules())->toBe('string|email:dns');
    expect(FluentRule::email()->withNativeValidation()->compiledRules())->toBe('string|email:filter');
    expect(FluentRule::email()->withNativeValidation(allowUnicode: true)->compiledRules())->toBe('string|email:filter_unicode');
});

it('compiles EmailRule with field modifiers', function (): void {
    expect(FluentRule::email()->required()->max(255)->compiledRules())->toBe('required|string|max:255|email');
});

it('validates EmailRule rejects non-string', function (): void {
    $validator = makeValidator(['email' => 123], ['email' => FluentRule::email()->required()]);
    expect($validator->passes())->toBeFalse();
});

it('EmailRule compiles unique', function (): void {
    $compiled = FluentRule::email()->required()->unique('users', 'email')->compiledRules();
    expect($compiled)->toBeString();
    expect($compiled)->toStartWith('required|string|email|');
    expect($compiled)->toContain('unique:');
});

it('EmailRule compiles exists', function (): void {
    $compiled = FluentRule::email()->required()->exists('users', 'email')->compiledRules();
    expect($compiled)->toBeString();
    expect($compiled)->toStartWith('required|string|email|');
    expect($compiled)->toContain('exists:');
});

it('EmailRule compiles confirmed', function (): void {
    expect(FluentRule::email()->confirmed()->compiledRules())->toBe('string|confirmed|email');
});

it('EmailRule compiles same and different', function (): void {
    expect(FluentRule::email()->same('backup_email')->compiledRules())->toBe('string|same:backup_email|email');
    expect(FluentRule::email()->different('old_email')->compiledRules())->toBe('string|different:old_email|email');
});

it('EmailRule compiledRules returns array for non-Stringable rule', function (): void {
    $nonStringable = new class implements ValidationRule {
        public function validate(string $attribute, mixed $value, Closure $fail): void {}
    };

    $compiled = FluentRule::email()->rule($nonStringable)->compiledRules();
    expect($compiled)->toBeArray();
    expect($compiled[0])->toBe('string');
    expect($compiled[1])->toBe('email');
    expect($compiled[2])->toBe($nonStringable);
});

it('EmailRule with modes validates and compiles correctly', function (): void {
    $emailRule = FluentRule::email()->rfcCompliant()->preventSpoofing()->required();

    // Modes are included in compiled output
    expect($emailRule->compiledRules())->toBe('required|string|email:rfc,spoof');

    // Basic validation still works with modes active
    $v = makeValidator(['email' => 'user@example.com'], ['email' => $emailRule]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['email' => 'not-an-email'], ['email' => $emailRule]);
    expect($v->passes())->toBeFalse();
});
