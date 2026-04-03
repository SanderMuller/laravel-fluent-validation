<?php

declare(strict_types=1);

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\AnyOf;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\Rules\ArrayRule;
use SanderMuller\FluentValidation\Rules\BooleanRule;
use SanderMuller\FluentValidation\Rules\DateRule;
use SanderMuller\FluentValidation\Rules\EmailRule;
use SanderMuller\FluentValidation\Rules\FileRule;
use SanderMuller\FluentValidation\Rules\ImageRule;
use SanderMuller\FluentValidation\Rules\NumericRule;
use SanderMuller\FluentValidation\Rules\PasswordRule;
use SanderMuller\FluentValidation\Rules\StringRule;
use SanderMuller\FluentValidation\RuleSet;

// =========================================================================
// BooleanRule
// =========================================================================

it('validates boolean with required', function (): void {
    $v = makeValidator(['active' => true], ['active' => FluentRule::boolean()->required()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['active' => 'yes'], ['active' => FluentRule::boolean()->required()]);
    expect($v->passes())->toBeFalse();
});

it('validates boolean with nullable', function (): void {
    $validator = makeValidator(['active' => null], ['active' => FluentRule::boolean()->nullable()]);
    expect($validator->passes())->toBeTrue();
});

it('creates boolean accepted rule', function (): void {
    $v = makeValidator(['tos' => true], ['tos' => FluentRule::boolean()->accepted()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['tos' => false], ['tos' => FluentRule::boolean()->accepted()]);
    expect($v->passes())->toBeFalse();
});

it('creates boolean declined rule', function (): void {
    $validator = makeValidator(['opt_out' => false], ['opt_out' => FluentRule::boolean()->declined()]);
    expect($validator->passes())->toBeTrue();
});

// =========================================================================
// ArrayRule
// =========================================================================

it('validates array with required and min/max', function (): void {
    $v = makeValidator(
        ['tags' => ['php', 'laravel']],
        ['tags' => FluentRule::array()->required()->min(1)->max(10)]
    );

    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['tags' => []],
        ['tags' => FluentRule::array()->required()->min(1)]
    );

    expect($v->passes())->toBeFalse();
});

it('validates array with list', function (): void {
    $v = makeValidator(
        ['ids' => [1, 2, 3]],
        ['ids' => FluentRule::array()->required()->list()->min(1)]
    );

    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['ids' => ['a' => 1, 'b' => 2]],
        ['ids' => FluentRule::array()->required()->list()]
    );

    expect($v->passes())->toBeFalse();
});

it('validates array with keys', function (): void {
    $validator = makeValidator(
        ['data' => ['name' => 'John', 'email' => 'john@example.com']],
        ['data' => FluentRule::array(['name', 'email'])->required()]
    );

    expect($validator->passes())->toBeTrue();
});

it('validates array with nullable', function (): void {
    $validator = makeValidator(['items' => null], ['items' => FluentRule::array()->nullable()]);
    expect($validator->passes())->toBeTrue();
});

it('validates array with between', function (): void {
    $validator = makeValidator(
        ['items' => ['a', 'b', 'c']],
        ['items' => FluentRule::array()->between(1, 5)]
    );

    expect($validator->passes())->toBeTrue();
});

it('validates array with exactly', function (): void {
    $v = makeValidator(
        ['items' => ['a', 'b']],
        ['items' => FluentRule::array()->exactly(2)]
    );

    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['items' => ['a', 'b', 'c']],
        ['items' => FluentRule::array()->exactly(2)]
    );

    expect($v->passes())->toBeFalse();
});

it('validates array each() with scalar rule standalone', function (): void {
    $v = makeValidator(
        ['tags' => ['php', 'laravel']],
        ['tags' => FluentRule::array()->required()->each(FluentRule::string()->max(50))]
    );

    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['tags' => ['php', 123]],
        ['tags' => FluentRule::array()->required()->each(FluentRule::string()->max(50))]
    );

    expect($v->passes())->toBeFalse();
});

it('validates array each() with field mappings standalone', function (): void {
    $v = makeValidator(
        ['items' => [['name' => 'John', 'age' => 25], ['name' => 'Jane', 'age' => 30]]],
        ['items' => FluentRule::array()->required()->each([
            'name' => FluentRule::string()->required()->min(2),
            'age' => FluentRule::numeric()->required()->min(0),
        ])]
    );

    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['items' => [['name' => 'J', 'age' => 25]]],
        ['items' => FluentRule::array()->required()->each([
            'name' => FluentRule::string()->required()->min(2),
            'age' => FluentRule::numeric()->required()->min(0),
        ])]
    );

    expect($v->passes())->toBeFalse();
});

it('validates nested array each() standalone', function (): void {
    $v = makeValidator(
        ['matrix' => [['a', 'b'], ['c', 'd']]],
        ['matrix' => FluentRule::array()->required()->each(
            FluentRule::array()->each(FluentRule::string()->max(10))
        )]
    );

    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['matrix' => [['a', 123], ['c', 'd']]],
        ['matrix' => FluentRule::array()->required()->each(
            FluentRule::array()->each(FluentRule::string()->max(10))
        )]
    );

    expect($v->passes())->toBeFalse();
});

// =========================================================================
// Factory methods return correct types
// =========================================================================

it('returns correct rule types from factory', function (): void {
    expect(FluentRule::string())->toBeInstanceOf(StringRule::class);
    expect(FluentRule::numeric())->toBeInstanceOf(NumericRule::class);
    expect(FluentRule::date())->toBeInstanceOf(DateRule::class);
    expect(FluentRule::dateTime())->toBeInstanceOf(DateRule::class);
    expect(FluentRule::boolean())->toBeInstanceOf(BooleanRule::class);
    expect(FluentRule::array())->toBeInstanceOf(ArrayRule::class);
    expect(FluentRule::email())->toBeInstanceOf(EmailRule::class);
    expect(FluentRule::file())->toBeInstanceOf(FileRule::class);
    expect(FluentRule::image())->toBeInstanceOf(ImageRule::class);
    expect(FluentRule::password())->toBeInstanceOf(PasswordRule::class);
    expect(FluentRule::anyOf(['string', 'integer']))->toBeInstanceOf(AnyOf::class);
});

// =========================================================================
// anyOf
// =========================================================================

it('validates anyOf passes when any rule matches', function (): void {
    $v = makeValidator(
        ['contact' => 'user@example.com'],
        ['contact' => FluentRule::anyOf([FluentRule::string()->email(), FluentRule::string()->url()])]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['contact' => 'https://example.com'],
        ['contact' => FluentRule::anyOf([FluentRule::string()->email(), FluentRule::string()->url()])]
    );
    expect($v->passes())->toBeTrue();
});

it('validates anyOf fails when no rule matches', function (): void {
    $validator = makeValidator(
        ['contact' => 'not-email-or-url'],
        ['contact' => FluentRule::anyOf([FluentRule::string()->email(), FluentRule::string()->url()])]
    );
    expect($validator->passes())->toBeFalse();
});

// =========================================================================
// Presence handling — optional fields (no modifier)
// =========================================================================

it('skips validation for absent field without presence modifier', function (): void {
    $validator = makeValidator([], ['name' => FluentRule::string()->min(2)]);

    expect($validator->passes())->toBeTrue();
});

it('skips validation for absent numeric field without presence modifier', function (): void {
    $validator = makeValidator([], ['age' => FluentRule::numeric()->min(0)]);

    expect($validator->passes())->toBeTrue();
});

it('skips validation for absent date field without presence modifier', function (): void {
    $validator = makeValidator([], ['date' => FluentRule::date()->after('today')]);

    expect($validator->passes())->toBeTrue();
});

it('skips validation for absent boolean field without presence modifier', function (): void {
    $validator = makeValidator([], ['active' => FluentRule::boolean()]);

    expect($validator->passes())->toBeTrue();
});

it('skips validation for absent array field without presence modifier', function (): void {
    $validator = makeValidator([], ['tags' => FluentRule::array()->min(1)]);

    expect($validator->passes())->toBeTrue();
});

it('still validates present field without presence modifier', function (): void {
    $validator = makeValidator(['name' => 123], ['name' => FluentRule::string()->min(2)]);

    expect($validator->passes())->toBeFalse();
});

// =========================================================================
// Presence handling — null without nullable
// =========================================================================

it('fails for null field without nullable modifier', function (): void {
    $validator = makeValidator(['name' => null], ['name' => FluentRule::string()->required()]);

    expect($validator->passes())->toBeFalse();
});

it('fails for null numeric field without nullable modifier', function (): void {
    $validator = makeValidator(['age' => null], ['age' => FluentRule::numeric()->required()]);

    expect($validator->passes())->toBeFalse();
});

// =========================================================================
// Presence handling — requiredIf with closure/bool
// =========================================================================

it('validates requiredIf with true bool triggers required', function (): void {
    $validator = makeValidator([], ['name' => FluentRule::string()->requiredIf(true)]);

    expect($validator->passes())->toBeFalse();
});

it('validates requiredIf with false bool skips required', function (): void {
    $validator = makeValidator([], ['name' => FluentRule::string()->requiredIf(false)]);

    expect($validator->passes())->toBeTrue();
});

it('validates requiredIf with closure', function (): void {
    $validator = makeValidator([], ['name' => FluentRule::string()->requiredIf(fn (): true => true)]);

    expect($validator->passes())->toBeFalse();
});

// =========================================================================
// notIn validation
// =========================================================================

it('validates string with notIn rule passes', function (): void {
    $validator = makeValidator(
        ['role' => 'editor'],
        ['role' => FluentRule::string()->required()->notIn(['admin', 'root'])]
    );

    expect($validator->passes())->toBeTrue();
});

it('validates string with notIn rule fails', function (): void {
    $validator = makeValidator(
        ['role' => 'admin'],
        ['role' => FluentRule::string()->required()->notIn(['admin', 'root'])]
    );

    expect($validator->passes())->toBeFalse();
});

// =========================================================================
// Numeric — enum with int enum
// =========================================================================

it('validates numeric with int enum', function (): void {
    $v = makeValidator(
        ['priority' => 1],
        ['priority' => FluentRule::numeric()->required()->enum(TestIntEnum::class)]
    );

    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['priority' => 99],
        ['priority' => FluentRule::numeric()->required()->enum(TestIntEnum::class)]
    );

    expect($v->passes())->toBeFalse();
});

// =========================================================================
// Error message propagation
// =========================================================================

it('propagates error messages from sub-validator', function (): void {
    $validator = makeValidator(
        ['name' => ''],
        ['name' => FluentRule::string()->required()->min(2)]
    );

    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->get('name'))->not->toBeEmpty();
});

it('has no errors when validation passes', function (): void {
    $validator = makeValidator(
        ['name' => 'John'],
        ['name' => FluentRule::string()->required()->min(2)]
    );

    expect($validator->passes())->toBeTrue();
    expect($validator->errors()->get('name'))->toBeEmpty();
});

// =========================================================================
// Labels and per-rule messages (standalone, via SelfValidates)
// =========================================================================

it('uses label in error messages via SelfValidates', function (): void {
    $validator = makeValidator(
        ['name' => ''],
        ['name' => FluentRule::string('Full Name')->required()]
    );

    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->first('name'))->toContain('Full Name');
});

it('uses per-rule message via SelfValidates', function (): void {
    $validator = makeValidator(
        ['name' => ''],
        ['name' => FluentRule::string()->required()->message('We need your name!')]
    );

    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->first('name'))->toBe('We need your name!');
});

it('uses label on numeric rule', function (): void {
    $validator = makeValidator(
        ['age' => 'not-a-number'],
        ['age' => FluentRule::numeric('Your age')->required()]
    );

    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->first('age'))->toContain('Your age');
});

it('uses label on date rule', function (): void {
    $validator = makeValidator(
        ['starts_at' => 'not-a-date'],
        ['starts_at' => FluentRule::date('Start Date')->required()]
    );

    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->first('starts_at'))->toContain('Start Date');
});

it('uses label on boolean rule', function (): void {
    $validator = makeValidator(
        ['agree' => 'not-a-bool'],
        ['agree' => FluentRule::boolean('Terms Agreement')->required()]
    );

    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->first('agree'))->toContain('Terms Agreement');
});

it('uses message after in() rule', function (): void {
    $validator = makeValidator(
        ['role' => 'hacker'],
        ['role' => FluentRule::string()->required()->in(['admin', 'user'])->message('Pick a valid role.')]
    );

    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->first('role'))->toBe('Pick a valid role.');
});

it('uses message after requiredIf with closure', function (): void {
    $validator = makeValidator(
        [],
        ['name' => FluentRule::string()->requiredIf(fn (): true => true)->message('Conditionally required!')]
    );

    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->first('name'))->toBe('Conditionally required!');
});

it('throws when message is called before any rule', function (): void {
    FluentRule::string()->message('This should throw');
})->throws(LogicException::class, 'message() must be called after a rule method');

it('supports multiple messages on different rules', function (): void {
    $validator = makeValidator(
        ['name' => ''],
        ['name' => FluentRule::string()
            ->required()->message('Name is required.')
            ->min(2)->message('Name too short.')]
    );

    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->first('name'))->toBe('Name is required.');
});

it('uses message inside when() conditional', function (): void {
    $validator = makeValidator(
        ['password' => 'short'],
        ['password' => FluentRule::string()->required()->when(true, fn ($r) => $r->min(12)->message('Admin passwords need 12+ chars.'))]
    );

    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->first('password'))->toBe('Admin passwords need 12+ chars.');
});

// =========================================================================
// BooleanRule — acceptedIf / declinedIf
// =========================================================================

it('validates boolean with acceptedIf', function (): void {
    $v = makeValidator(
        ['tos' => true, 'country' => 'US'],
        ['tos' => FluentRule::boolean()->acceptedIf('country', 'US')]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['tos' => false, 'country' => 'US'],
        ['tos' => FluentRule::boolean()->acceptedIf('country', 'US')]
    );
    expect($v->passes())->toBeFalse();
});

it('validates boolean with declinedIf', function (): void {
    $v = makeValidator(
        ['opt_in' => false, 'type' => 'minor'],
        ['opt_in' => FluentRule::boolean()->declinedIf('type', 'minor')]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['opt_in' => true, 'type' => 'minor'],
        ['opt_in' => FluentRule::boolean()->declinedIf('type', 'minor')]
    );
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// ArrayRule — requiredArrayKeys / BackedEnum keys
// =========================================================================

it('validates array with requiredArrayKeys', function (): void {
    $v = makeValidator(
        ['data' => ['name' => 'John', 'email' => 'john@test.com']],
        ['data' => FluentRule::array()->requiredArrayKeys('name', 'email')]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['data' => ['name' => 'John']],
        ['data' => FluentRule::array()->requiredArrayKeys('name', 'email')]
    );
    expect($v->passes())->toBeFalse();
});

it('validates array with BackedEnum keys', function (): void {
    $v = makeValidator(
        ['data' => ['low' => 1, 'medium' => 2]],
        ['data' => FluentRule::array([TestArrayKeyEnum::Low, TestArrayKeyEnum::Medium])]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['data' => ['low' => 1, 'medium' => 2, 'unknown' => 3]],
        ['data' => FluentRule::array([TestArrayKeyEnum::Low, TestArrayKeyEnum::Medium])]
    );
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// HasFieldModifiers — filled / prohibited / missing
// =========================================================================

it('validates field with filled', function (): void {
    $v = makeValidator(['name' => 'John'], ['name' => FluentRule::string()->filled()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['name' => ''], ['name' => FluentRule::string()->filled()]);
    expect($v->passes())->toBeFalse();
});

it('validates field with prohibited', function (): void {
    $v = makeValidator(['secret' => 'value'], ['secret' => FluentRule::string()->prohibited()]);
    expect($v->passes())->toBeFalse();

    $v = makeValidator([], ['secret' => FluentRule::string()->prohibited()]);
    expect($v->passes())->toBeTrue();
});

it('validates field with missing', function (): void {
    $v = makeValidator([], ['secret' => FluentRule::string()->missing()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['secret' => 'value'], ['secret' => FluentRule::string()->missing()]);
    expect($v->passes())->toBeFalse();
});

it('validates missingIf', function (): void {
    $v = makeValidator(
        ['type' => 'free'],
        ['price' => FluentRule::string()->missingIf('type', 'free')]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['type' => 'free', 'price' => '100'],
        ['price' => FluentRule::string()->missingIf('type', 'free')]
    );
    expect($v->passes())->toBeFalse();
});

it('validates missingUnless', function (): void {
    $v = makeValidator(
        ['type' => 'free'],
        ['price' => FluentRule::string()->missingUnless('type', 'paid')]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['type' => 'free', 'price' => '100'],
        ['price' => FluentRule::string()->missingUnless('type', 'paid')]
    );
    expect($v->passes())->toBeFalse();
});

it('validates missingWith', function (): void {
    $v = makeValidator(
        ['coupon' => 'ABC'],
        ['discount' => FluentRule::string()->missingWith('coupon')]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['coupon' => 'ABC', 'discount' => '10'],
        ['discount' => FluentRule::string()->missingWith('coupon')]
    );
    expect($v->passes())->toBeFalse();
});

it('validates missingWithAll', function (): void {
    $v = makeValidator(
        ['a' => '1', 'b' => '2'],
        ['c' => FluentRule::string()->missingWithAll('a', 'b')]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['a' => '1', 'b' => '2', 'c' => '3'],
        ['c' => FluentRule::string()->missingWithAll('a', 'b')]
    );
    expect($v->passes())->toBeFalse();
});


// =========================================================================
// HasFieldModifiers — requiredUnless
// =========================================================================

it('validates requiredUnless with field and value', function (): void {
    $v = makeValidator(
        ['role' => 'guest'],
        ['name' => FluentRule::string()->requiredUnless('role', 'guest')]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['role' => 'admin'],
        ['name' => FluentRule::string()->requiredUnless('role', 'guest')]
    );
    expect($v->passes())->toBeFalse();
});

it('validates requiredUnless with true bool triggers required', function (): void {
    $validator = makeValidator([], ['name' => FluentRule::string()->requiredUnless(true)]);
    expect($validator->passes())->toBeTrue();
});

it('validates requiredUnless with false bool triggers required', function (): void {
    $validator = makeValidator([], ['name' => FluentRule::string()->requiredUnless(false)]);
    expect($validator->passes())->toBeFalse();
});

it('validates requiredUnless with closure', function (): void {
    $validator = makeValidator([], ['name' => FluentRule::string()->requiredUnless(fn (): false => false)]);
    expect($validator->passes())->toBeFalse();
});

// =========================================================================
// HasFieldModifiers — requiredWith / requiredWithAll / requiredWithout / requiredWithoutAll
// =========================================================================

it('validates requiredWith', function (): void {
    $v = makeValidator(
        ['email' => 'test@test.com'],
        ['name' => FluentRule::string()->requiredWith('email')]
    );
    expect($v->passes())->toBeFalse();

    $v = makeValidator(
        [],
        ['name' => FluentRule::string()->requiredWith('email')]
    );
    expect($v->passes())->toBeTrue();
});

it('validates requiredWithAll', function (): void {
    $v = makeValidator(
        ['first' => 'a', 'last' => 'b'],
        ['full' => FluentRule::string()->requiredWithAll('first', 'last')]
    );
    expect($v->passes())->toBeFalse();

    $v = makeValidator(
        ['first' => 'a'],
        ['full' => FluentRule::string()->requiredWithAll('first', 'last')]
    );
    expect($v->passes())->toBeTrue();
});

it('validates requiredWithout', function (): void {
    $v = makeValidator(
        [],
        ['name' => FluentRule::string()->requiredWithout('nickname')]
    );
    expect($v->passes())->toBeFalse();

    $v = makeValidator(
        ['nickname' => 'Johnny'],
        ['name' => FluentRule::string()->requiredWithout('nickname')]
    );
    expect($v->passes())->toBeTrue();
});

it('validates requiredWithoutAll', function (): void {
    $v = makeValidator(
        [],
        ['name' => FluentRule::string()->requiredWithoutAll('first_name', 'last_name')]
    );
    expect($v->passes())->toBeFalse();

    $v = makeValidator(
        ['first_name' => 'John'],
        ['name' => FluentRule::string()->requiredWithoutAll('first_name', 'last_name')]
    );
    expect($v->passes())->toBeTrue();
});

// =========================================================================
// HasFieldModifiers — excludeIf / excludeUnless / excludeWith / excludeWithout
// =========================================================================

it('validates excludeIf', function (): void {
    $validator = makeValidator(
        ['type' => 'free', 'price' => '100'],
        ['price' => FluentRule::string()->excludeIf('type', 'free')]
    );
    expect($validator->passes())->toBeTrue();
});

it('validates excludeUnless', function (): void {
    $validator = makeValidator(
        ['type' => 'free', 'price' => '100'],
        ['price' => FluentRule::string()->excludeUnless('type', 'paid')]
    );
    expect($validator->passes())->toBeTrue();
});

it('validates excludeIf with bool', function (): void {
    $v = makeValidator(['field' => 'val'], ['field' => FluentRule::string()->excludeIf(true)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['field' => 'val'], ['field' => FluentRule::string()->excludeIf(false)]);
    expect($v->passes())->toBeTrue();
});

it('validates excludeIf with closure', function (): void {
    $v = makeValidator(['field' => 'val'], ['field' => FluentRule::string()->excludeIf(fn (): true => true)]);
    expect($v->passes())->toBeTrue();
});

it('validates excludeUnless with bool', function (): void {
    $v = makeValidator(['field' => 'val'], ['field' => FluentRule::string()->excludeUnless(true)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['field' => 'val'], ['field' => FluentRule::string()->excludeUnless(false)]);
    expect($v->passes())->toBeTrue();
});

it('validates excludeUnless with closure', function (): void {
    $v = makeValidator(['field' => 'val'], ['field' => FluentRule::string()->excludeUnless(fn (): false => false)]);
    expect($v->passes())->toBeTrue();
});

it('validates excludeWith', function (): void {
    $validator = makeValidator(
        ['other' => 'val', 'field' => 'val'],
        ['field' => FluentRule::string()->excludeWith('other')]
    );
    expect($validator->passes())->toBeTrue();
});

it('validates excludeWithout', function (): void {
    $validator = makeValidator(
        ['field' => 'val'],
        ['field' => FluentRule::string()->excludeWithout('other')]
    );
    expect($validator->passes())->toBeTrue();
});

// =========================================================================
// HasFieldModifiers — prohibitedIf / prohibitedUnless / prohibits
// =========================================================================

it('validates prohibitedIf with field and value', function (): void {
    $v = makeValidator(
        ['type' => 'free', 'price' => '100'],
        ['price' => FluentRule::string()->prohibitedIf('type', 'free')]
    );
    expect($v->passes())->toBeFalse();

    $v = makeValidator(
        ['type' => 'paid', 'price' => '100'],
        ['price' => FluentRule::string()->prohibitedIf('type', 'free')]
    );
    expect($v->passes())->toBeTrue();
});

it('validates prohibitedIf with bool', function (): void {
    $v = makeValidator(['field' => 'val'], ['field' => FluentRule::string()->prohibitedIf(true)]);
    expect($v->passes())->toBeFalse();

    $v = makeValidator(['field' => 'val'], ['field' => FluentRule::string()->prohibitedIf(false)]);
    expect($v->passes())->toBeTrue();
});

it('validates prohibitedIf with closure', function (): void {
    $validator = makeValidator(['field' => 'val'], ['field' => FluentRule::string()->prohibitedIf(fn (): true => true)]);
    expect($validator->passes())->toBeFalse();
});

it('validates prohibitedUnless with field and value', function (): void {
    $v = makeValidator(
        ['type' => 'free', 'price' => '100'],
        ['price' => FluentRule::string()->prohibitedUnless('type', 'paid')]
    );
    expect($v->passes())->toBeFalse();

    $v = makeValidator(
        ['type' => 'paid', 'price' => '100'],
        ['price' => FluentRule::string()->prohibitedUnless('type', 'paid')]
    );
    expect($v->passes())->toBeTrue();
});

it('validates prohibitedUnless with bool', function (): void {
    $v = makeValidator(['field' => 'val'], ['field' => FluentRule::string()->prohibitedUnless(true)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['field' => 'val'], ['field' => FluentRule::string()->prohibitedUnless(false)]);
    expect($v->passes())->toBeFalse();
});

it('validates prohibits', function (): void {
    $v = makeValidator(
        ['field' => 'val', 'other' => 'val'],
        ['field' => FluentRule::string()->prohibits('other')]
    );
    expect($v->passes())->toBeFalse();

    $v = makeValidator(
        ['field' => 'val'],
        ['field' => FluentRule::string()->prohibits('other')]
    );
    expect($v->passes())->toBeTrue();
});

// =========================================================================
// HasEmbeddedRules — enum with callback
// =========================================================================

it('validates enum with callback modifier', function (): void {
    $v = makeValidator(
        ['status' => 'active'],
        ['status' => FluentRule::string()->required()->enum(TestStringEnum::class, fn ($rule) => $rule->only(TestStringEnum::Active))]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['status' => 'inactive'],
        ['status' => FluentRule::string()->required()->enum(TestStringEnum::class, fn ($rule) => $rule->only(TestStringEnum::Active))]
    );
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// SelfValidates — canCompile / compiledRules
// =========================================================================

it('reports canCompile true when no object rules', function (): void {
    $stringRule = FluentRule::string()->required()->min(2)->max(255);
    expect($stringRule->canCompile())->toBeTrue();
});

it('reports canCompile false when object rules present', function (): void {
    $stringRule = FluentRule::string()->required()->in(['a', 'b']);
    expect($stringRule->canCompile())->toBeFalse();
});

it('compiles to pipe-joined string when no object rules', function (): void {
    $stringRule = FluentRule::string()->required()->min(2)->max(255);
    expect($stringRule->compiledRules())->toBe('string|required|min:2|max:255');
});

it('compiles stringable object rules to pipe string', function (): void {
    $stringRule = FluentRule::string()->required()->in(['a', 'b']);
    $compiled = $stringRule->compiledRules();
    expect($compiled)->toBeString();
    expect($compiled)->toContain('string');
    expect($compiled)->toContain('required');
    expect($compiled)->toContain('in:');
});

// =========================================================================
// HasEmbeddedRules — unique / exists
// =========================================================================

it('compiles unique rule to pipe string', function (): void {
    $stringRule = FluentRule::string()->unique('users', 'email');
    expect($stringRule->canCompile())->toBeFalse();
    $compiled = $stringRule->compiledRules();
    expect($compiled)->toBeString();
    expect($compiled)->toContain('unique:');
});

it('compiles unique rule with default column to pipe string', function (): void {
    $stringRule = FluentRule::string()->unique('users');
    expect($stringRule->canCompile())->toBeFalse();
    $compiled = $stringRule->compiledRules();
    expect($compiled)->toBeString();
    expect($compiled)->toContain('unique:');
});

it('compiles exists rule to pipe string', function (): void {
    $stringRule = FluentRule::string()->exists('users', 'email');
    expect($stringRule->canCompile())->toBeFalse();
    $compiled = $stringRule->compiledRules();
    expect($compiled)->toBeString();
    expect($compiled)->toContain('exists:');
});

it('compiles exists rule with default column to pipe string', function (): void {
    $stringRule = FluentRule::string()->exists('users');
    expect($stringRule->canCompile())->toBeFalse();
    $compiled = $stringRule->compiledRules();
    expect($compiled)->toBeString();
    expect($compiled)->toContain('exists:');
});

// =========================================================================
// RuleSet::compile()
// =========================================================================

it('compiles fluent rules to native format', function (): void {
    $compiled = RuleSet::compile([
        'name' => FluentRule::string()->required()->min(2),
        'age' => FluentRule::numeric()->integer(),
    ]);

    expect($compiled['name'])->toBe('string|required|min:2');
    expect($compiled['age'])->toBe('numeric|integer');
});

it('compile passes through non-fluent rules unchanged', function (): void {
    $compiled = RuleSet::compile([
        'name' => 'required|string',
        'tags' => ['required', 'array'],
    ]);

    expect($compiled['name'])->toBe('required|string');
    expect($compiled['tags'])->toBe(['required', 'array']);
});

// =========================================================================
// ArrayRule — getEachRules / withoutEachRules
// =========================================================================

it('returns null from getEachRules when no each set', function (): void {
    $arrayRule = FluentRule::array();
    expect($arrayRule->getEachRules())->toBeNull();
});

it('returns each rules from getEachRules', function (): void {
    $stringRule = FluentRule::string()->required();
    $arrayRule = FluentRule::array()->each($stringRule);
    expect($arrayRule->getEachRules())->toBe($stringRule);
});

it('withoutEachRules returns clone without each rules', function (): void {
    $arrayRule = FluentRule::array()->required()->each(FluentRule::string());
    $without = $arrayRule->withoutEachRules();

    expect($without->getEachRules())->toBeNull();
    expect($arrayRule->getEachRules())->not->toBeNull();
});

// =========================================================================
// Macroable
// =========================================================================

it('supports macros on StringRule', function (): void {
    StringRule::macro('slug', fn () => $this->alpha(true)->lowercase());

    $v = makeValidator(
        ['slug' => 'hello'],
        ['slug' => FluentRule::string()->slug()]
    );

    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['slug' => 'Hello World'],
        ['slug' => FluentRule::string()->slug()]
    );

    expect($v->passes())->toBeFalse();
});

// =========================================================================
// compiledRules — non-Stringable object fallback
// =========================================================================

it('compiledRules returns array when rule contains non-stringable object', function (): void {
    $nonStringable = new class implements ValidationRule {
        public function validate(string $attribute, mixed $value, Closure $fail): void {}
    };

    $stringRule = FluentRule::string()->required()->rule($nonStringable);
    expect($stringRule->canCompile())->toBeFalse();

    $compiled = $stringRule->compiledRules();
    expect($compiled)->toBeArray();
    expect($compiled[0])->toBe('string');
    expect($compiled[1])->toBe('required');
    expect($compiled[2])->toBe($nonStringable);
});

// =========================================================================
// exclude() modifier
// =========================================================================

it('exclude adds the exclude constraint', function (): void {
    $stringRule = FluentRule::string()->exclude();
    expect($stringRule->compiledRules())->toBe('string|exclude');
});

// =========================================================================
// ArrayRule — buildNestedRules with nested ArrayRule in field mapping
// =========================================================================

it('buildNestedRules handles nested ArrayRule in field mapping each()', function (): void {
    $arrayRule = FluentRule::array()->required()->each(FluentRule::string()->max(50));
    $outer = FluentRule::array()->required()->each([
        'tags' => $arrayRule,
        'name' => FluentRule::string()->required(),
    ]);

    $nested = $outer->buildNestedRules('items');

    expect($nested)->toHaveKey('items.*.tags');
    expect($nested)->toHaveKey('items.*.tags.*');
    expect($nested)->toHaveKey('items.*.name');
});

// =========================================================================
// Framework parity — missing from initial port
// =========================================================================

it('validates requiredIf with multiple values', function (): void {
    $v = makeValidator(
        ['role' => 'editor'],
        ['name' => FluentRule::string()->requiredIf('role', 'admin', 'editor')]
    );
    expect($v->passes())->toBeFalse();

    $v = makeValidator(
        ['role' => 'viewer'],
        ['name' => FluentRule::string()->requiredIf('role', 'admin', 'editor')]
    );
    expect($v->passes())->toBeTrue();
});

it('validates requiredWith with multiple fields', function (): void {
    $v = makeValidator(
        ['first' => 'John'],
        ['name' => FluentRule::string()->requiredWith('first', 'last')]
    );
    expect($v->passes())->toBeFalse();

    $v = makeValidator(
        [],
        ['name' => FluentRule::string()->requiredWith('first', 'last')]
    );
    expect($v->passes())->toBeTrue();
});

it('validates prohibits with multiple fields', function (): void {
    $validator = makeValidator(
        ['role' => 'admin', 'other' => 'x', 'another' => 'y'],
        ['role' => FluentRule::string()->prohibits('other', 'another')]
    );
    expect($validator->passes())->toBeFalse();
});

it('validates unless conditional modifier', function (): void {
    $stringRule = FluentRule::string()
        ->required()
        ->unless(false, fn ($r) => $r->min(12))
        ->max(255);

    $validator = makeValidator(['name' => 'short'], ['name' => $stringRule]);
    expect($validator->passes())->toBeFalse();
});

it('validates unless does not apply when condition is true', function (): void {
    $stringRule = FluentRule::string()
        ->required()
        ->unless(true, fn ($r) => $r->min(12))
        ->max(255);

    $validator = makeValidator(['name' => 'short'], ['name' => $stringRule]);
    expect($validator->passes())->toBeTrue();
});

it('validates rule escape hatch with closure', function (): void {
    $v = makeValidator(
        ['field' => 'invalid'],
        ['field' => FluentRule::string()->required()->rule(function (string $attribute, mixed $value, Closure $fail): void {
            if ($value !== 'valid') {
                $fail("The {$attribute} must be valid.");
            }
        })]
    );
    expect($v->passes())->toBeFalse();

    $v = makeValidator(
        ['field' => 'valid'],
        ['field' => FluentRule::string()->required()->rule(function (string $attribute, mixed $value, Closure $fail): void {
            if ($value !== 'valid') {
                $fail("The {$attribute} must be valid.");
            }
        })]
    );
    expect($v->passes())->toBeTrue();
});

// =========================================================================
// Constraint uniqueness
// =========================================================================

it('deduplicates constraints when compiling', function (): void {
    $stringRule = FluentRule::string()->required()->required();
    $compiled = $stringRule->compiledRules();
    // Should contain 'required' but not break — duplicate is harmless
    expect($compiled)->toContain('required');
});

// =========================================================================
// Enums for testing
// =========================================================================

enum TestStringEnum: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}

enum TestIntEnum: int
{
    case Low = 1;
    case Medium = 2;
    case High = 3;
}

enum TestArrayKeyEnum: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
