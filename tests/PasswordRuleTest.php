<?php declare(strict_types=1);

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
    /** @var Password $password */
    expect((new ReflectionProperty($password, 'uncompromised'))->getValue($password))->toBeTrue();
    expect((new ReflectionProperty($password, 'compromisedThreshold'))->getValue($password))->toBe(5);
});

it('password canCompile returns false', function (): void {
    expect(FluentRule::password()->canCompile())->toBeFalse()
        ->and(FluentRule::password()->required()->canCompile())->toBeFalse();
});

it('password compiledRules includes Password object', function (): void {
    $compiled = FluentRule::password()->required()->compiledRules();
    expect($compiled)->toBeArray()
        ->toMatchArray([0 => 'required', 1 => 'string'])
        ->and($compiled[2])->toBeInstanceOf(Password::class);
});

it('password supports field modifiers', function (): void {
    $v = makeValidator([], ['password' => FluentRule::password()->required()]);
    expect($v->passes())->toBeFalse();

    $v = makeValidator(['password' => null], ['password' => FluentRule::password()->nullable()]);
    expect($v->passes())->toBeTrue();
});

it('password rejects non-string', function (): void {
    $validator = makeValidator(['password' => 12_345_678], ['password' => FluentRule::password()->required()]);
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

it('uses Password::default() when no min specified', function (): void {

    Password::defaults(fn () => Password::min(10)->letters()->numbers());

    try {
        $v = makeValidator(['password' => 'short1'], ['password' => FluentRule::password()->required()]);
        expect($v->passes())->toBeFalse(); // too short (min 10)

        $v = makeValidator(['password' => 'LongEnough1'], ['password' => FluentRule::password()->required()]);
        expect($v->passes())->toBeTrue();
    } finally {
        Password::$defaultCallback = null;
    }
});

it('overrides defaults when explicit min is passed', function (): void {
    Password::defaults(fn () => Password::min(20)->letters());

    try {
        $v = makeValidator(['password' => 'Short1'], ['password' => FluentRule::password(min: 6)->required()]);
        expect($v->passes())->toBeTrue();
    } finally {
        Password::$defaultCallback = null;
    }
});

it('defaults: false ignores app password defaults', function (): void {
    Password::defaults(fn () => Password::min(20)->letters()->numbers());

    try {
        // defaults: false → Password::min(8), ignores the configured min:20 + letters + numbers
        $v = makeValidator(['password' => 'simplepassword'], ['password' => FluentRule::password(defaults: false)->required()]);
        expect($v->passes())->toBeTrue(); // passes with min:8, no letters/numbers requirement

        // With defaults: true (default) → applies min:20 + letters + numbers
        $v = makeValidator(['password' => 'simplepassword'], ['password' => FluentRule::password()->required()]);
        expect($v->passes())->toBeFalse(); // fails: too short (min:20) and no numbers
    } finally {
        Password::$defaultCallback = null;
    }
});

it('min() sets minimum length via chain', function (): void {
    $v = makeValidator(['password' => 'short'], ['password' => FluentRule::password()->min(8)->required()]);
    expect($v->passes())->toBeFalse();

    $v = makeValidator(['password' => 'longenough'], ['password' => FluentRule::password()->min(8)->required()]);
    expect($v->passes())->toBeTrue();
});

it('min() and max() work together', function (): void {
    $rule = FluentRule::password()->min(8)->max(20)->required();

    $v = makeValidator(['password' => 'short'], ['password' => $rule]);
    expect($v->passes())->toBeFalse(); // too short

    $v = makeValidator(['password' => 'this_password_is_way_too_long_for_the_max'], ['password' => $rule]);
    expect($v->passes())->toBeFalse(); // too long

    $v = makeValidator(['password' => 'justright123'], ['password' => $rule]);
    expect($v->passes())->toBeTrue();
});

it('min() preserves other password rules', function (): void {
    $rule = FluentRule::password()->min(10)->letters()->numbers();

    $v = makeValidator(['password' => '1234567890'], ['password' => $rule]);
    expect($v->passes())->toBeFalse(); // no letters

    $v = makeValidator(['password' => 'abcdefghij'], ['password' => $rule]);
    expect($v->passes())->toBeFalse(); // no numbers

    $v = makeValidator(['password' => 'HasLetters1'], ['password' => $rule]);
    expect($v->passes())->toBeTrue();
});

it('confirmed() works on password rule', function (): void {
    $rule = FluentRule::password()->required()->confirmed();

    $v = makeValidator(
        ['password' => 'MyPassword1', 'password_confirmation' => 'MyPassword1'],
        ['password' => $rule],
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['password' => 'MyPassword1', 'password_confirmation' => 'Different1'],
        ['password' => $rule],
    );
    expect($v->passes())->toBeFalse();
});

it('compiles different() and notIn() like the string chain with Password::default()', function (): void {
    $fluent = FluentRule::password()->required()->notIn(['secret', 'hunter2'])->different('email')->confirmed();
    $string = FluentRule::string()->required()->notIn(['secret', 'hunter2'])->different('email')->rule(Password::default())->confirmed();
    $data = ['password' => 'secret', 'password_confirmation' => 'secret', 'email' => 'secret'];

    expect(array_map(stringifyRule(...), $fluent->toArray()))->toBe(array_map(stringifyRule(...), $string->toArray()))
        ->and(validator($data, ['password' => $fluent])->errors()->get('password'))
        ->toBe(validator($data, ['password' => $string])->errors()->get('password'))
        ->toContain('The selected password is invalid.', 'The password field and email must be different.');
});

it('reports notIn() before the Password rule', function (): void {
    $errors = validator(['password' => 'abc'], ['password' => FluentRule::password()->notIn('abc')])->errors()->get('password');

    expect($errors[0])->toBe('The selected password is invalid.');
});

it('uses the message of different() and notIn()', function (): void {
    $rule = FluentRule::password()->notIn(['abc'], 'Too common.')->different('email', 'Not your email.');
    $data = ['password' => 'abc', 'email' => 'abc'];

    expect(validator($data, ['password' => $rule])->errors()->get('password'))->toContain('Too common.', 'Not your email.');
});

function stringifyRule(object|string $rule): string
{
    return is_object($rule) ? $rule::class . ($rule instanceof Stringable ? ':' . $rule : '') : $rule;
}
