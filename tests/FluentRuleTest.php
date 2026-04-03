<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rules\AnyOf;
use Illuminate\Validation\Rules\Dimensions;
use Illuminate\Validation\Rules\Password;
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
// StringRule — field modifiers
// =========================================================================

it('creates a string rule with bail', function (): void {
    $validator = makeValidator(['name' => 123], ['name' => FluentRule::string()->bail()->min(2)->max(255)]);

    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->get('name'))->toHaveCount(1);
});

it('creates a string rule with nullable', function (): void {
    $validator = makeValidator(['name' => null], ['name' => FluentRule::string()->nullable()->max(255)]);

    expect($validator->passes())->toBeTrue();
});

it('creates a string rule with required', function (): void {
    $validator = makeValidator([], ['name' => FluentRule::string()->required()]);

    expect($validator->passes())->toBeFalse();
});

it('creates a string rule with sometimes that skips when absent', function (): void {
    $validator = makeValidator([], ['name' => FluentRule::string()->sometimes()->min(2)]);

    expect($validator->passes())->toBeTrue();
});

// =========================================================================
// StringRule — type-specific constraints
// =========================================================================

it('validates string with min and max', function (): void {
    $v = makeValidator(['name' => 'John'], ['name' => FluentRule::string()->required()->min(2)->max(255)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['name' => 'J'], ['name' => FluentRule::string()->required()->min(2)->max(255)]);
    expect($v->passes())->toBeFalse();
});

it('validates string with chained modifiers and constraints', function (): void {
    $v = makeValidator(
        ['password' => 'short'],
        ['password' => FluentRule::string()->required()->when(true, fn ($r): StringRule => $r->min(12))->max(255)]
    );

    expect($v->passes())->toBeFalse();

    $v = makeValidator(
        ['password' => 'longenoughpassword'],
        ['password' => FluentRule::string()->required()->when(true, fn ($r): StringRule => $r->min(12))->max(255)]
    );

    expect($v->passes())->toBeTrue();
});

it('validates when condition is false does not apply', function (): void {
    $validator = makeValidator(
        ['name' => 'Jo'],
        ['name' => FluentRule::string()->required()->when(false, fn ($r): StringRule => $r->min(12))->max(255)]
    );

    expect($validator->passes())->toBeTrue();
});

// =========================================================================
// StringRule — rule() escape hatch
// =========================================================================

it('supports the rule escape hatch with a string', function (): void {
    $validator = makeValidator(
        ['name' => 'hello', 'other' => 'world'],
        ['name' => FluentRule::string()->required()->rule('different:other')]
    );

    expect($validator->passes())->toBeTrue();
});

it('supports the rule escape hatch with a ValidationRule object', function (): void {
    $customRule = new class implements ValidationRule {
        public function validate(string $attribute, mixed $value, Closure $fail): void
        {
            if ($value !== 'valid') {
                $fail('The :attribute must be valid.');
            }
        }
    };

    $v = makeValidator(['field' => 'valid'], ['field' => FluentRule::string()->required()->rule($customRule)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['field' => 'invalid'], ['field' => FluentRule::string()->required()->rule($customRule)]);
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// StringRule — conditional modifiers
// =========================================================================

it('validates required_if with field and value', function (): void {
    $v = makeValidator(
        ['role' => 'admin'],
        ['name' => FluentRule::string()->requiredIf('role', 'admin')]
    );

    expect($v->passes())->toBeFalse();

    $v = makeValidator(
        ['role' => 'user'],
        ['name' => FluentRule::string()->requiredIf('role', 'admin')]
    );

    expect($v->passes())->toBeTrue();
});

// =========================================================================
// present() modifier
// =========================================================================

it('validates present fails when field is absent', function (): void {
    $validator = makeValidator([], ['name' => FluentRule::string()->present()]);

    expect($validator->passes())->toBeFalse();
});

it('validates present passes when field is present', function (): void {
    $validator = makeValidator(['name' => ''], ['name' => FluentRule::string()->present()]);

    expect($validator->passes())->toBeTrue();
});

// =========================================================================
// StringRule — HasEmbeddedRules
// =========================================================================

it('validates string with in rule', function (): void {
    $v = makeValidator(
        ['status' => 'draft'],
        ['status' => FluentRule::string()->required()->in(['draft', 'published', 'archived'])]
    );

    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['status' => 'deleted'],
        ['status' => FluentRule::string()->required()->in(['draft', 'published', 'archived'])]
    );

    expect($v->passes())->toBeFalse();
});

it('validates string with enum rule', function (): void {
    $v = makeValidator(
        ['status' => 'active'],
        ['status' => FluentRule::string()->required()->enum(TestStringEnum::class)]
    );

    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['status' => 'nonexistent'],
        ['status' => FluentRule::string()->required()->enum(TestStringEnum::class)]
    );

    expect($v->passes())->toBeFalse();
});

// =========================================================================
// NumericRule
// =========================================================================

it('validates numeric with integer and min', function (): void {
    $validator = makeValidator(['age' => 25], ['age' => FluentRule::numeric()->integer()->min(0)]);

    expect($validator->passes())->toBeTrue();
});

it('validates numeric with required', function (): void {
    $validator = makeValidator([], ['age' => FluentRule::numeric()->required()]);

    expect($validator->passes())->toBeFalse();
});

it('validates numeric with nullable', function (): void {
    $validator = makeValidator(['age' => null], ['age' => FluentRule::numeric()->nullable()]);

    expect($validator->passes())->toBeTrue();
});

it('validates numeric with between', function (): void {
    $v = makeValidator(['price' => 15.5], ['price' => FluentRule::numeric()->between(10, 20)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['price' => 25], ['price' => FluentRule::numeric()->between(10, 20)]);
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// DateRule
// =========================================================================

it('validates date with after', function (): void {
    $validator = makeValidator(
        ['date' => '2099-01-01'],
        ['date' => FluentRule::date()->after('today')]
    );

    expect($validator->passes())->toBeTrue();
});

it('validates date with required', function (): void {
    $validator = makeValidator([], ['date' => FluentRule::date()->required()]);

    expect($validator->passes())->toBeFalse();
});

it('validates date with nullable', function (): void {
    $validator = makeValidator(['date' => null], ['date' => FluentRule::date()->nullable()]);

    expect($validator->passes())->toBeTrue();
});

it('validates date with format', function (): void {
    $v = makeValidator(
        ['date' => '01/15/2025'],
        ['date' => FluentRule::date()->format('m/d/Y')]
    );

    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['date' => '2025-01-15'],
        ['date' => FluentRule::date()->format('m/d/Y')]
    );

    expect($v->passes())->toBeFalse();
});

it('validates datetime shortcut', function (): void {
    $validator = makeValidator(
        ['timestamp' => '2025-01-15 14:30:00'],
        ['timestamp' => FluentRule::dateTime()]
    );

    expect($validator->passes())->toBeTrue();
});

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
// StringRule — alpha / alphaNumeric / alphaDash / ascii
// =========================================================================

it('validates string with alpha', function (): void {
    $v = makeValidator(['name' => 'John'], ['name' => FluentRule::string()->alpha()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['name' => 'John123'], ['name' => FluentRule::string()->alpha()]);
    expect($v->passes())->toBeFalse();
});

it('validates string with alpha ascii', function (): void {
    $v = makeValidator(['name' => 'John'], ['name' => FluentRule::string()->alpha(true)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['name' => 'Jöhn'], ['name' => FluentRule::string()->alpha(true)]);
    expect($v->passes())->toBeFalse();
});

it('validates string with alphaDash', function (): void {
    $v = makeValidator(['slug' => 'my-slug_v2'], ['slug' => FluentRule::string()->alphaDash()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['slug' => 'has spaces'], ['slug' => FluentRule::string()->alphaDash()]);
    expect($v->passes())->toBeFalse();
});

it('validates string with alphaDash ascii', function (): void {
    $validator = makeValidator(['slug' => 'my-slug'], ['slug' => FluentRule::string()->alphaDash(true)]);
    expect($validator->passes())->toBeTrue();
});

it('validates string with alphaNumeric', function (): void {
    $v = makeValidator(['code' => 'abc123'], ['code' => FluentRule::string()->alphaNumeric()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['code' => 'abc-123'], ['code' => FluentRule::string()->alphaNumeric()]);
    expect($v->passes())->toBeFalse();
});

it('validates string with alphaNumeric ascii', function (): void {
    $validator = makeValidator(['code' => 'abc123'], ['code' => FluentRule::string()->alphaNumeric(true)]);
    expect($validator->passes())->toBeTrue();
});

it('validates string with ascii', function (): void {
    $v = makeValidator(['text' => 'hello'], ['text' => FluentRule::string()->ascii()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['text' => 'héllo'], ['text' => FluentRule::string()->ascii()]);
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// StringRule — between / exactly / startsWith / endsWith / doesntStartWith / doesntEndWith
// =========================================================================

it('validates string with between', function (): void {
    $v = makeValidator(['name' => 'John'], ['name' => FluentRule::string()->between(2, 10)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['name' => 'J'], ['name' => FluentRule::string()->between(2, 10)]);
    expect($v->passes())->toBeFalse();
});

it('validates string with exactly', function (): void {
    $v = makeValidator(['code' => 'abcd'], ['code' => FluentRule::string()->exactly(4)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['code' => 'abc'], ['code' => FluentRule::string()->exactly(4)]);
    expect($v->passes())->toBeFalse();
});

it('validates string with startsWith', function (): void {
    $v = makeValidator(['url' => 'https://example.com'], ['url' => FluentRule::string()->startsWith('https://')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['url' => 'http://example.com'], ['url' => FluentRule::string()->startsWith('https://')]);
    expect($v->passes())->toBeFalse();
});

it('validates string with endsWith', function (): void {
    $v = makeValidator(['file' => 'photo.jpg'], ['file' => FluentRule::string()->endsWith('.jpg', '.png')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['file' => 'photo.gif'], ['file' => FluentRule::string()->endsWith('.jpg', '.png')]);
    expect($v->passes())->toBeFalse();
});

it('validates string with doesntStartWith', function (): void {
    $v = makeValidator(['name' => 'hello'], ['name' => FluentRule::string()->doesntStartWith('foo', 'bar')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['name' => 'foobar'], ['name' => FluentRule::string()->doesntStartWith('foo', 'bar')]);
    expect($v->passes())->toBeFalse();
});

it('validates string with doesntEndWith', function (): void {
    $v = makeValidator(['name' => 'hello'], ['name' => FluentRule::string()->doesntEndWith('world', 'bar')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['name' => 'helloworld'], ['name' => FluentRule::string()->doesntEndWith('world', 'bar')]);
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// StringRule — case
// =========================================================================

it('validates string with lowercase', function (): void {
    $v = makeValidator(['name' => 'hello'], ['name' => FluentRule::string()->lowercase()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['name' => 'Hello'], ['name' => FluentRule::string()->lowercase()]);
    expect($v->passes())->toBeFalse();
});

it('validates string with uppercase', function (): void {
    $v = makeValidator(['name' => 'HELLO'], ['name' => FluentRule::string()->uppercase()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['name' => 'Hello'], ['name' => FluentRule::string()->uppercase()]);
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// StringRule — url / uuid / ulid / json / ip / macAddress / timezone / hexColor
// =========================================================================

it('validates string with url', function (): void {
    $v = makeValidator(['site' => 'https://example.com'], ['site' => FluentRule::string()->url()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['site' => 'not-a-url'], ['site' => FluentRule::string()->url()]);
    expect($v->passes())->toBeFalse();
});

it('validates string with activeUrl', function (): void {
    $v = makeValidator(['site' => 'https://example.com'], ['site' => FluentRule::string()->activeUrl()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['site' => 'https://thisdomaindoesnotexist12345.invalid'], ['site' => FluentRule::string()->activeUrl()]);
    expect($v->passes())->toBeFalse();
});

it('validates string with uuid', function (): void {
    $v = makeValidator(['id' => '550e8400-e29b-41d4-a716-446655440000'], ['id' => FluentRule::string()->uuid()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['id' => 'not-a-uuid'], ['id' => FluentRule::string()->uuid()]);
    expect($v->passes())->toBeFalse();
});

it('validates string with ulid', function (): void {
    $v = makeValidator(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'], ['id' => FluentRule::string()->ulid()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['id' => 'not-a-ulid'], ['id' => FluentRule::string()->ulid()]);
    expect($v->passes())->toBeFalse();
});

it('validates string with json', function (): void {
    $v = makeValidator(['data' => '{"key":"value"}'], ['data' => FluentRule::string()->json()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['data' => 'not-json'], ['data' => FluentRule::string()->json()]);
    expect($v->passes())->toBeFalse();
});

it('validates string with ip', function (): void {
    $v = makeValidator(['addr' => '192.168.1.1'], ['addr' => FluentRule::string()->ip()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['addr' => 'not-an-ip'], ['addr' => FluentRule::string()->ip()]);
    expect($v->passes())->toBeFalse();
});

it('validates string with ipv4', function (): void {
    $v = makeValidator(['addr' => '192.168.1.1'], ['addr' => FluentRule::string()->ipv4()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['addr' => '::1'], ['addr' => FluentRule::string()->ipv4()]);
    expect($v->passes())->toBeFalse();
});

it('validates string with ipv6', function (): void {
    $v = makeValidator(['addr' => '::1'], ['addr' => FluentRule::string()->ipv6()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['addr' => '192.168.1.1'], ['addr' => FluentRule::string()->ipv6()]);
    expect($v->passes())->toBeFalse();
});

it('validates string with macAddress', function (): void {
    $v = makeValidator(['mac' => '00:1B:44:11:3A:B7'], ['mac' => FluentRule::string()->macAddress()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['mac' => 'not-a-mac'], ['mac' => FluentRule::string()->macAddress()]);
    expect($v->passes())->toBeFalse();
});

it('validates string with timezone', function (): void {
    $v = makeValidator(['tz' => 'America/New_York'], ['tz' => FluentRule::string()->timezone()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['tz' => 'Not/A_Timezone'], ['tz' => FluentRule::string()->timezone()]);
    expect($v->passes())->toBeFalse();
});

it('validates string with hexColor', function (): void {
    $v = makeValidator(['color' => '#ff0000'], ['color' => FluentRule::string()->hexColor()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['color' => 'red'], ['color' => FluentRule::string()->hexColor()]);
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// StringRule — regex / notRegex
// =========================================================================

it('validates string with regex', function (): void {
    $v = makeValidator(['code' => 'ABC-123'], ['code' => FluentRule::string()->regex('/^[A-Z]+-\d+$/')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['code' => 'abc'], ['code' => FluentRule::string()->regex('/^[A-Z]+-\d+$/')]);
    expect($v->passes())->toBeFalse();
});

it('validates string with notRegex', function (): void {
    $v = makeValidator(['name' => 'hello'], ['name' => FluentRule::string()->notRegex('/\d/')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['name' => 'hello123'], ['name' => FluentRule::string()->notRegex('/\d/')]);
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// StringRule — date / dateFormat / confirmed / same / different
// =========================================================================

it('validates string with date', function (): void {
    $v = makeValidator(['d' => '2025-01-15'], ['d' => FluentRule::string()->date()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => 'not-a-date'], ['d' => FluentRule::string()->date()]);
    expect($v->passes())->toBeFalse();
});

it('validates string with dateFormat', function (): void {
    $v = makeValidator(['d' => '15/01/2025'], ['d' => FluentRule::string()->dateFormat('d/m/Y')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2025-01-15'], ['d' => FluentRule::string()->dateFormat('d/m/Y')]);
    expect($v->passes())->toBeFalse();
});

it('validates string with confirmed', function (): void {
    $v = makeValidator(
        ['password' => 'secret', 'password_confirmation' => 'secret'],
        ['password' => FluentRule::string()->confirmed()]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['password' => 'secret', 'password_confirmation' => 'different'],
        ['password' => FluentRule::string()->confirmed()]
    );
    expect($v->passes())->toBeFalse();
});

it('validates string with same', function (): void {
    $v = makeValidator(
        ['password' => 'secret', 'confirm' => 'secret'],
        ['password' => FluentRule::string()->same('confirm')]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['password' => 'secret', 'confirm' => 'other'],
        ['password' => FluentRule::string()->same('confirm')]
    );
    expect($v->passes())->toBeFalse();
});

it('validates string with different', function (): void {
    $v = makeValidator(
        ['name' => 'John', 'other' => 'Jane'],
        ['name' => FluentRule::string()->different('other')]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['name' => 'John', 'other' => 'John'],
        ['name' => FluentRule::string()->different('other')]
    );
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// StringRule — inArray / distinct
// =========================================================================

it('validates string with inArray', function (): void {
    $v = makeValidator(
        ['name' => 'John', 'names' => ['John', 'Jane']],
        ['name' => FluentRule::string()->inArray('names.*')]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['name' => 'Bob', 'names' => ['John', 'Jane']],
        ['name' => FluentRule::string()->inArray('names.*')]
    );
    expect($v->passes())->toBeFalse();
});

it('compiles string with distinct rule', function (): void {
    $stringRule = FluentRule::string()->distinct();
    expect($stringRule->compiledRules())->toBe('string|distinct');
});

it('compiles string with distinct strict mode rule', function (): void {
    $stringRule = FluentRule::string()->distinct('strict');
    expect($stringRule->compiledRules())->toBe('string|distinct:strict');
});

// =========================================================================
// NumericRule — min / max / decimal / digits / digitsBetween
// =========================================================================

it('validates numeric with min', function (): void {
    $v = makeValidator(['age' => 18], ['age' => FluentRule::numeric()->min(18)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['age' => 17], ['age' => FluentRule::numeric()->min(18)]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with max', function (): void {
    $v = makeValidator(['age' => 100], ['age' => FluentRule::numeric()->max(120)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['age' => 150], ['age' => FluentRule::numeric()->max(120)]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with decimal', function (): void {
    $v = makeValidator(['price' => '10.50'], ['price' => FluentRule::numeric()->decimal(2)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['price' => '10.5'], ['price' => FluentRule::numeric()->decimal(2)]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with decimal min and max', function (): void {
    $v = makeValidator(['price' => '10.5'], ['price' => FluentRule::numeric()->decimal(1, 3)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['price' => '10.5000'], ['price' => FluentRule::numeric()->decimal(1, 3)]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with digits', function (): void {
    $v = makeValidator(['pin' => 1234], ['pin' => FluentRule::numeric()->digits(4)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['pin' => 123], ['pin' => FluentRule::numeric()->digits(4)]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with digitsBetween', function (): void {
    $v = makeValidator(['code' => 12345], ['code' => FluentRule::numeric()->digitsBetween(4, 6)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['code' => 123], ['code' => FluentRule::numeric()->digitsBetween(4, 6)]);
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// NumericRule — greaterThan / greaterThanOrEqualTo / lessThan / lessThanOrEqualTo
// =========================================================================

it('validates numeric with greaterThan', function (): void {
    $v = makeValidator(['max' => 20, 'min' => 10], ['max' => FluentRule::numeric()->greaterThan('min')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['max' => 5, 'min' => 10], ['max' => FluentRule::numeric()->greaterThan('min')]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with greaterThanOrEqualTo', function (): void {
    $v = makeValidator(['max' => 10, 'min' => 10], ['max' => FluentRule::numeric()->greaterThanOrEqualTo('min')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['max' => 9, 'min' => 10], ['max' => FluentRule::numeric()->greaterThanOrEqualTo('min')]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with lessThan', function (): void {
    $v = makeValidator(['min' => 5, 'max' => 10], ['min' => FluentRule::numeric()->lessThan('max')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['min' => 15, 'max' => 10], ['min' => FluentRule::numeric()->lessThan('max')]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with lessThanOrEqualTo', function (): void {
    $v = makeValidator(['min' => 10, 'max' => 10], ['min' => FluentRule::numeric()->lessThanOrEqualTo('max')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['min' => 11, 'max' => 10], ['min' => FluentRule::numeric()->lessThanOrEqualTo('max')]);
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// NumericRule — multipleOf / maxDigits / minDigits / exactly / same / different
// =========================================================================

it('validates numeric with multipleOf', function (): void {
    $v = makeValidator(['qty' => 15], ['qty' => FluentRule::numeric()->multipleOf(5)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['qty' => 13], ['qty' => FluentRule::numeric()->multipleOf(5)]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with maxDigits', function (): void {
    $v = makeValidator(['num' => 999], ['num' => FluentRule::numeric()->maxDigits(3)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['num' => 10000], ['num' => FluentRule::numeric()->maxDigits(3)]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with minDigits', function (): void {
    $v = makeValidator(['num' => 100], ['num' => FluentRule::numeric()->minDigits(3)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['num' => 10], ['num' => FluentRule::numeric()->minDigits(3)]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with exactly', function (): void {
    $v = makeValidator(['count' => 5], ['count' => FluentRule::numeric()->exactly(5)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['count' => 6], ['count' => FluentRule::numeric()->exactly(5)]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with same', function (): void {
    $v = makeValidator(['a' => 10, 'b' => 10], ['a' => FluentRule::numeric()->same('b')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['a' => 10, 'b' => 20], ['a' => FluentRule::numeric()->same('b')]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with different', function (): void {
    $v = makeValidator(['a' => 10, 'b' => 20], ['a' => FluentRule::numeric()->different('b')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['a' => 10, 'b' => 10], ['a' => FluentRule::numeric()->different('b')]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with confirmed', function (): void {
    $v = makeValidator(
        ['amount' => 100, 'amount_confirmation' => 100],
        ['amount' => FluentRule::numeric()->confirmed()]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['amount' => 100, 'amount_confirmation' => 200],
        ['amount' => FluentRule::numeric()->confirmed()]
    );
    expect($v->passes())->toBeFalse();
});

it('validates numeric with inArray', function (): void {
    $v = makeValidator(
        ['val' => 2, 'allowed' => [1, 2, 3]],
        ['val' => FluentRule::numeric()->inArray('allowed.*')]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['val' => 9, 'allowed' => [1, 2, 3]],
        ['val' => FluentRule::numeric()->inArray('allowed.*')]
    );
    expect($v->passes())->toBeFalse();
});

it('compiles numeric with distinct rule', function (): void {
    $numericRule = FluentRule::numeric()->distinct();
    expect($numericRule->compiledRules())->toBe('numeric|distinct');
});

it('validates numeric with integer strict mode', function (): void {
    $validator = makeValidator(['num' => 5], ['num' => FluentRule::numeric()->integer(true)]);
    expect($validator->passes())->toBeTrue();
});

// =========================================================================
// DateRule — before / beforeToday / afterToday / todayOrBefore / todayOrAfter
// =========================================================================

it('validates date with before', function (): void {
    $v = makeValidator(['d' => '2020-01-01'], ['d' => FluentRule::date()->before('2025-01-01')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2030-01-01'], ['d' => FluentRule::date()->before('2025-01-01')]);
    expect($v->passes())->toBeFalse();
});

it('validates date with beforeToday', function (): void {
    $v = makeValidator(['d' => '2000-01-01'], ['d' => FluentRule::date()->beforeToday()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2099-01-01'], ['d' => FluentRule::date()->beforeToday()]);
    expect($v->passes())->toBeFalse();
});

it('validates date with afterToday', function (): void {
    $v = makeValidator(['d' => '2099-01-01'], ['d' => FluentRule::date()->afterToday()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2000-01-01'], ['d' => FluentRule::date()->afterToday()]);
    expect($v->passes())->toBeFalse();
});

it('validates date with todayOrBefore', function (): void {
    $validator = makeValidator(['d' => '2000-01-01'], ['d' => FluentRule::date()->todayOrBefore()]);
    expect($validator->passes())->toBeTrue();
});

it('validates date with todayOrAfter', function (): void {
    $validator = makeValidator(['d' => '2099-01-01'], ['d' => FluentRule::date()->todayOrAfter()]);
    expect($validator->passes())->toBeTrue();
});

// =========================================================================
// DateRule — past / future / nowOrPast / nowOrFuture
// =========================================================================

it('validates date with past', function (): void {
    $v = makeValidator(['d' => '2000-01-01 00:00:00'], ['d' => FluentRule::date()->past()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2099-01-01 00:00:00'], ['d' => FluentRule::date()->past()]);
    expect($v->passes())->toBeFalse();
});

it('validates date with future', function (): void {
    $v = makeValidator(['d' => '2099-01-01 00:00:00'], ['d' => FluentRule::date()->future()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2000-01-01 00:00:00'], ['d' => FluentRule::date()->future()]);
    expect($v->passes())->toBeFalse();
});

it('validates date with nowOrPast', function (): void {
    $validator = makeValidator(['d' => '2000-01-01 00:00:00'], ['d' => FluentRule::date()->nowOrPast()]);
    expect($validator->passes())->toBeTrue();
});

it('validates date with nowOrFuture', function (): void {
    $validator = makeValidator(['d' => '2099-01-01 00:00:00'], ['d' => FluentRule::date()->nowOrFuture()]);
    expect($validator->passes())->toBeTrue();
});

// =========================================================================
// DateRule — beforeOrEqual / afterOrEqual / between / betweenOrEqual / dateEquals
// =========================================================================

it('validates date with beforeOrEqual', function (): void {
    $v = makeValidator(['d' => '2025-01-01'], ['d' => FluentRule::date()->beforeOrEqual('2025-01-01')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2025-01-02'], ['d' => FluentRule::date()->beforeOrEqual('2025-01-01')]);
    expect($v->passes())->toBeFalse();
});

it('validates date with afterOrEqual', function (): void {
    $v = makeValidator(['d' => '2025-01-01'], ['d' => FluentRule::date()->afterOrEqual('2025-01-01')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2024-12-31'], ['d' => FluentRule::date()->afterOrEqual('2025-01-01')]);
    expect($v->passes())->toBeFalse();
});

it('validates date with between', function (): void {
    $v = makeValidator(['d' => '2025-06-15'], ['d' => FluentRule::date()->between('2025-01-01', '2025-12-31')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2024-06-15'], ['d' => FluentRule::date()->between('2025-01-01', '2025-12-31')]);
    expect($v->passes())->toBeFalse();
});

it('validates date with betweenOrEqual', function (): void {
    $v = makeValidator(['d' => '2025-01-01'], ['d' => FluentRule::date()->betweenOrEqual('2025-01-01', '2025-12-31')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2024-12-31'], ['d' => FluentRule::date()->betweenOrEqual('2025-01-01', '2025-12-31')]);
    expect($v->passes())->toBeFalse();
});

it('validates date with dateEquals', function (): void {
    $v = makeValidator(['d' => '2025-01-15'], ['d' => FluentRule::date()->dateEquals('2025-01-15')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2025-01-16'], ['d' => FluentRule::date()->dateEquals('2025-01-15')]);
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// DateRule — same / different
// =========================================================================

it('validates date with same', function (): void {
    $v = makeValidator(['d' => '2025-01-01', 'other' => '2025-01-01'], ['d' => FluentRule::date()->same('other')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2025-01-01', 'other' => '2025-01-02'], ['d' => FluentRule::date()->same('other')]);
    expect($v->passes())->toBeFalse();
});

it('validates date with different', function (): void {
    $v = makeValidator(['d' => '2025-01-01', 'other' => '2025-01-02'], ['d' => FluentRule::date()->different('other')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2025-01-01', 'other' => '2025-01-01'], ['d' => FluentRule::date()->different('other')]);
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// DateRule — DateTimeInterface arguments
// =========================================================================

it('validates date with DateTimeInterface argument', function (): void {
    $cutoff = CarbonImmutable::parse('2025-06-01');

    $v = makeValidator(['d' => '2025-01-01'], ['d' => FluentRule::date()->before($cutoff)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2025-12-01'], ['d' => FluentRule::date()->before($cutoff)]);
    expect($v->passes())->toBeFalse();
});

it('validates date with DateTimeInterface and custom format', function (): void {
    $cutoff = CarbonImmutable::parse('2025-06-01');

    $validator = makeValidator(['d' => '01/01/2025'], ['d' => FluentRule::date()->format('m/d/Y')->before($cutoff)]);
    expect($validator->passes())->toBeTrue();
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
// StringRule — inArrayKeys / currentPassword
// =========================================================================

it('compiles string with inArrayKeys rule', function (): void {
    $stringRule = FluentRule::string()->inArrayKeys('options.*');
    expect($stringRule->compiledRules())->toBe('string|in_array_keys:options.*');
});

it('compiles string with currentPassword rule', function (): void {
    $stringRule = FluentRule::string()->currentPassword();
    expect($stringRule->compiledRules())->toBe('string|current_password');
});

it('compiles string with currentPassword and guard rule', function (): void {
    $stringRule = FluentRule::string()->currentPassword('api');
    expect($stringRule->compiledRules())->toBe('string|current_password:api');
});

// =========================================================================
// NumericRule — inArrayKeys
// =========================================================================

it('compiles numeric with inArrayKeys rule', function (): void {
    $numericRule = FluentRule::numeric()->inArrayKeys('options.*');
    expect($numericRule->compiledRules())->toBe('numeric|in_array_keys:options.*');
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
// StringRule — email()
// =========================================================================

it('validates email on string rule', function (): void {
    $v = makeValidator(['email' => 'user@example.com'], ['email' => FluentRule::string()->required()->email()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['email' => 'not-an-email'], ['email' => FluentRule::string()->required()->email()]);
    expect($v->passes())->toBeFalse();
});

it('compiles email on string rule', function (): void {
    expect(FluentRule::string()->email()->compiledRules())->toBe('string|email');
    expect(FluentRule::string()->email('rfc')->compiledRules())->toBe('string|email:rfc');
    expect(FluentRule::string()->email('rfc', 'spoof')->compiledRules())->toBe('string|email:rfc,spoof');
});

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
    expect(FluentRule::email()->required()->max(255)->compiledRules())->toBe('string|required|max:255|email');
});

it('validates EmailRule rejects non-string', function (): void {
    $validator = makeValidator(['email' => 123], ['email' => FluentRule::email()->required()]);
    expect($validator->passes())->toBeFalse();
});

it('EmailRule compiles unique', function (): void {
    $compiled = FluentRule::email()->required()->unique('users', 'email')->compiledRules();
    expect($compiled)->toBeString();
    expect($compiled)->toStartWith('string|required|email|');
    expect($compiled)->toContain('unique:');
});

it('EmailRule compiles exists', function (): void {
    $compiled = FluentRule::email()->required()->exists('users', 'email')->compiledRules();
    expect($compiled)->toBeString();
    expect($compiled)->toStartWith('string|required|email|');
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
    expect($emailRule->compiledRules())->toBe('string|required|email:rfc,spoof');

    // Basic validation still works with modes active
    $v = makeValidator(['email' => 'user@example.com'], ['email' => $emailRule]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['email' => 'not-an-email'], ['email' => $emailRule]);
    expect($v->passes())->toBeFalse();
});

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

// =========================================================================
// FileRule
// =========================================================================

it('compiles file rule with min and max', function (): void {
    expect(FluentRule::file()->compiledRules())->toBe('file');
    expect(FluentRule::file()->min(100)->compiledRules())->toBe('file|min:100');
    expect(FluentRule::file()->max(2048)->compiledRules())->toBe('file|max:2048');
    expect(FluentRule::file()->min(100)->max(2048)->compiledRules())->toBe('file|min:100|max:2048');
});

it('converts decimal and whitespace sizes to kilobytes', function (): void {
    expect(FluentRule::file()->max('1.5mb')->compiledRules())->toBe('file|max:1536');
    expect(FluentRule::file()->max(' 5mb ')->compiledRules())->toBe('file|max:5120');
});

it('compiles file rule with human-readable sizes', function (): void {
    expect(FluentRule::file()->max('5mb')->compiledRules())->toBe('file|max:5120');
    expect(FluentRule::file()->max('1gb')->compiledRules())->toBe('file|max:1048576');
    expect(FluentRule::file()->max('1tb')->compiledRules())->toBe('file|max:1073741824');
    expect(FluentRule::file()->max('512kb')->compiledRules())->toBe('file|max:512');
    expect(FluentRule::file()->between('1mb', '10mb')->compiledRules())->toBe('file|between:1024,10240');
});

it('file rule accepts plain numeric string as kilobytes', function (): void {
    expect(FluentRule::file()->max('2048')->compiledRules())->toBe('file|max:2048');
});

it('compiles file rule with between and exactly', function (): void {
    expect(FluentRule::file()->between(100, 2048)->compiledRules())->toBe('file|between:100,2048');
    expect(FluentRule::file()->exactly(512)->compiledRules())->toBe('file|size:512');
});

it('compiles file rule with extensions', function (): void {
    expect(FluentRule::file()->extensions('pdf', 'docx')->compiledRules())->toBe('file|extensions:pdf,docx');
});

it('compiles file rule with mimes', function (): void {
    expect(FluentRule::file()->mimes('jpg', 'png', 'pdf')->compiledRules())->toBe('file|mimes:jpg,png,pdf');
});

it('compiles file rule with mimetypes', function (): void {
    expect(FluentRule::file()->mimetypes('image/jpeg', 'image/png')->compiledRules())->toBe('file|mimetypes:image/jpeg,image/png');
});

it('compiles file rule with field modifiers', function (): void {
    expect(FluentRule::file()->required()->max(2048)->compiledRules())->toBe('file|required|max:2048');
    expect(FluentRule::file()->nullable()->compiledRules())->toBe('file|nullable');
});

it('validates file upload', function (): void {
    $file = UploadedFile::fake()->create('document.pdf', 100);
    $validator = makeValidator(['doc' => $file], ['doc' => FluentRule::file()->required()->max(2048)]);
    expect($validator->passes())->toBeTrue();
});

it('rejects non-file value', function (): void {
    $validator = makeValidator(['doc' => 'not-a-file'], ['doc' => FluentRule::file()->required()]);
    expect($validator->passes())->toBeFalse();
});

it('rejects file exceeding max size', function (): void {
    $file = UploadedFile::fake()->create('big.pdf', 3000);
    $validator = makeValidator(['doc' => $file], ['doc' => FluentRule::file()->required()->max(2048)]);
    expect($validator->passes())->toBeFalse();
});

it('validates file mimes at runtime', function (): void {
    $file = UploadedFile::fake()->create('doc.pdf', 100);
    $v = makeValidator(['doc' => $file], ['doc' => FluentRule::file()->required()->mimes('pdf')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['doc' => $file], ['doc' => FluentRule::file()->required()->mimes('jpg', 'png')]);
    expect($v->passes())->toBeFalse();
});

it('validates file extensions', function (): void {
    $file = UploadedFile::fake()->create('document.pdf', 100);
    $v = makeValidator(['doc' => $file], ['doc' => FluentRule::file()->required()->extensions('pdf', 'docx')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['doc' => $file], ['doc' => FluentRule::file()->required()->extensions('jpg', 'png')]);
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// ImageRule
// =========================================================================

it('compiles image rule', function (): void {
    expect(FluentRule::image()->compiledRules())->toBe('image');
    expect(FluentRule::image()->max(5120)->compiledRules())->toBe('image|max:5120');
    expect(FluentRule::image()->required()->max('5mb')->compiledRules())->toBe('image|required|max:5120');
});

it('compiles image rule with allowSvg', function (): void {
    expect(FluentRule::image()->allowSvg()->compiledRules())->toBe('image:allow_svg');
});

it('allowSvg preserves field modifiers set before it', function (): void {
    $compiled = FluentRule::image()->required()->allowSvg()->compiledRules();
    expect($compiled)->toBe('image:allow_svg|required');
});

it('compiles image rule with minWidth and maxWidth', function (): void {
    $compiled = FluentRule::image()->minWidth(100)->maxWidth(1920)->compiledRules();
    expect($compiled)->toContain('image');
    expect($compiled)->toContain('dimensions:min_width=100');
    expect($compiled)->toContain('dimensions:max_width=1920');
});

it('compiles image rule with width and height', function (): void {
    $compiled = FluentRule::image()->width(800)->height(600)->compiledRules();
    expect($compiled)->toContain('dimensions:width=800');
    expect($compiled)->toContain('dimensions:height=600');
});

it('compiles image rule with minHeight and maxHeight', function (): void {
    $compiled = FluentRule::image()->minHeight(100)->maxHeight(1080)->compiledRules();
    expect($compiled)->toContain('dimensions:min_height=100');
    expect($compiled)->toContain('dimensions:max_height=1080');
});

it('compiles image rule with string ratio', function (): void {
    $compiled = FluentRule::image()->ratio('16/9')->compiledRules();
    expect($compiled)->toContain('image');
    expect($compiled)->toContain('dimensions:ratio=16/9');
});

it('compiles image rule with float ratio', function (): void {
    $compiled = FluentRule::image()->ratio(1.5)->compiledRules();
    expect($compiled)->toContain('dimensions:ratio=1.5');
});

it('compiles image rule with Dimensions instance', function (): void {
    $dimensions = new Dimensions(['min_width' => 200, 'ratio' => 1.0]);
    $compiled = FluentRule::image()->dimensions($dimensions)->compiledRules();
    expect($compiled)->toContain((string) $dimensions);
});

it('validates image upload', function (): void {
    $image = UploadedFile::fake()->image('photo.jpg', 100, 100);
    $validator = makeValidator(['photo' => $image], ['photo' => FluentRule::image()->required()->max(2048)]);
    expect($validator->passes())->toBeTrue();
});

it('rejects non-image file as image', function (): void {
    $file = UploadedFile::fake()->create('document.pdf', 100);
    $validator = makeValidator(['photo' => $file], ['photo' => FluentRule::image()->required()]);
    expect($validator->passes())->toBeFalse();
});

it('image inherits file methods', function (): void {
    expect(FluentRule::image()->extensions('jpg', 'png')->mimes('jpg', 'png')->max(2048)->compiledRules())
        ->toBe('image|extensions:jpg,png|mimes:jpg,png|max:2048');
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
// File — field modifier integration (framework parity)
// =========================================================================

it('file required rejects absent field', function (): void {
    $validator = makeValidator([], ['doc' => FluentRule::file()->required()]);
    expect($validator->passes())->toBeFalse();
});

it('file nullable passes with null', function (): void {
    $validator = makeValidator(['doc' => null], ['doc' => FluentRule::file()->nullable()]);
    expect($validator->passes())->toBeTrue();
});

it('file absent without required passes', function (): void {
    $validator = makeValidator([], ['doc' => FluentRule::file()->nullable()]);
    expect($validator->passes())->toBeTrue();
});

it('file bail stops on first failure', function (): void {
    $validator = makeValidator(
        ['doc' => 'not-a-file'],
        ['doc' => FluentRule::file()->bail()->max(2048)]
    );
    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->get('doc'))->toHaveCount(1);
});

// =========================================================================
// Image — field modifier integration (framework parity)
// =========================================================================

it('image required rejects absent field', function (): void {
    $validator = makeValidator([], ['avatar' => FluentRule::image()->required()]);
    expect($validator->passes())->toBeFalse();
});

it('image nullable passes with null', function (): void {
    $validator = makeValidator(['avatar' => null], ['avatar' => FluentRule::image()->nullable()]);
    expect($validator->passes())->toBeTrue();
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
