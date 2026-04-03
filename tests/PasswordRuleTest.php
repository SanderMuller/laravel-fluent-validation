<?php

declare(strict_types=1);

use Illuminate\Validation\Rules\Password;
use SanderMuller\FluentValidation\FluentRule;

// =========================================================================
// PasswordRule
// =========================================================================

it('validates password with PasswordRule', function (): void {
    $validator = makeValidator(
        ['password' => 'SecureP@ss1'],
        ['password' => FluentRule::password()->required()->letters()->mixedCase()->numbers()->symbols()]
    );
    expect($validator->passes())->toBeTrue();
});

it('rejects weak password', function (): void {
    $validator = makeValidator(
        ['password' => 'weak'],
        ['password' => FluentRule::password(8)->required()->letters()->numbers()]
    );
    expect($validator->passes())->toBeFalse();
});

it('validates password min length', function (): void {
    $v = makeValidator(
        ['password' => 'abcdefgh'],
        ['password' => FluentRule::password(8)->required()]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['password' => 'short'],
        ['password' => FluentRule::password(8)->required()]
    );
    expect($v->passes())->toBeFalse();
});

it('validates password max length', function (): void {
    $validator = makeValidator(
        ['password' => str_repeat('a', 256)],
        ['password' => FluentRule::password()->required()->max(255)]
    );
    expect($validator->passes())->toBeFalse();
});

it('password uncompromised configures the Password rule', function (): void {
    $passwordRule = FluentRule::password()->uncompromised(5);
    $compiled = $passwordRule->compiledRules();
    $password = $compiled[1];
    expect($password)->toBeInstanceOf(Password::class);
    expect((new ReflectionProperty($password, 'uncompromised'))->getValue($password))->toBeTrue();
    expect((new ReflectionProperty($password, 'compromisedThreshold'))->getValue($password))->toBe(5);
});

it('password canCompile returns false', function (): void {
    expect(FluentRule::password()->canCompile())->toBeFalse();
    expect(FluentRule::password()->required()->canCompile())->toBeFalse();
});

it('password compiledRules includes Password object', function (): void {
    $compiled = FluentRule::password()->required()->compiledRules();
    expect($compiled)->toBeArray();
    expect($compiled[0])->toBe('string');
    expect($compiled[1])->toBe('required');
    expect($compiled[2])->toBeInstanceOf(Password::class);
});

it('password supports field modifiers', function (): void {
    $v = makeValidator([], ['password' => FluentRule::password()->required()]);
    expect($v->passes())->toBeFalse();

    $v = makeValidator(['password' => null], ['password' => FluentRule::password()->nullable()]);
    expect($v->passes())->toBeTrue();
});

it('password rejects non-string', function (): void {
    $validator = makeValidator(['password' => 12345678], ['password' => FluentRule::password()->required()]);
    expect($validator->passes())->toBeFalse();
});

it('password combined chain rejects each near-miss independently', function (): void {
    $passwordRule = FluentRule::password()->required()->letters()->mixedCase()->numbers()->symbols();

    // Missing uppercase
    $v = makeValidator(['password' => 'abcdefg1!'], ['password' => $passwordRule]);
    expect($v->passes())->toBeFalse();

    // Missing number
    $v = makeValidator(['password' => 'Abcdefg!!'], ['password' => $passwordRule]);
    expect($v->passes())->toBeFalse();

    // Missing symbol
    $v = makeValidator(['password' => 'Abcdefg12'], ['password' => $passwordRule]);
    expect($v->passes())->toBeFalse();

    // Missing letter
    $v = makeValidator(['password' => '12345678!'], ['password' => $passwordRule]);
    expect($v->passes())->toBeFalse();

    // All satisfied
    $v = makeValidator(['password' => 'Abcdef1!x'], ['password' => $passwordRule]);
    expect($v->passes())->toBeTrue();
});

it('password letters requires at least one letter', function (): void {
    $v = makeValidator(['password' => '12345678'], ['password' => FluentRule::password()->required()->letters()]);
    expect($v->passes())->toBeFalse();

    $v = makeValidator(['password' => '1234567a'], ['password' => FluentRule::password()->required()->letters()]);
    expect($v->passes())->toBeTrue();
});

it('password mixedCase requires upper and lower', function (): void {
    $v = makeValidator(['password' => 'alllowercase'], ['password' => FluentRule::password()->required()->mixedCase()]);
    expect($v->passes())->toBeFalse();

    $v = makeValidator(['password' => 'hasUpperA'], ['password' => FluentRule::password()->required()->mixedCase()]);
    expect($v->passes())->toBeTrue();
});

it('password numbers requires at least one number', function (): void {
    $v = makeValidator(['password' => 'NoNumbers!'], ['password' => FluentRule::password()->required()->numbers()]);
    expect($v->passes())->toBeFalse();

    $v = makeValidator(['password' => 'Has1Number'], ['password' => FluentRule::password()->required()->numbers()]);
    expect($v->passes())->toBeTrue();
});

it('password symbols requires at least one symbol', function (): void {
    $v = makeValidator(['password' => 'NoSymbols1'], ['password' => FluentRule::password()->required()->symbols()]);
    expect($v->passes())->toBeFalse();

    $v = makeValidator(['password' => 'HasSymbol!'], ['password' => FluentRule::password()->required()->symbols()]);
    expect($v->passes())->toBeTrue();
});
