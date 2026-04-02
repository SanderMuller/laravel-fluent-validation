<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use SanderMuller\FluentValidation\Rule;
use SanderMuller\FluentValidation\Rules\ArrayRule;
use SanderMuller\FluentValidation\Rules\BooleanRule;
use SanderMuller\FluentValidation\Rules\DateRule;
use SanderMuller\FluentValidation\Rules\NumericRule;
use SanderMuller\FluentValidation\Rules\StringRule;
use SanderMuller\FluentValidation\RuleSet;

// =========================================================================
// StringRule — field modifiers
// =========================================================================

it('creates a string rule with bail', function (): void {
    $validator = makeValidator(['name' => 123], ['name' => Rule::string()->bail()->min(2)->max(255)]);

    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->get('name'))->toHaveCount(1);
});

it('creates a string rule with nullable', function (): void {
    $validator = makeValidator(['name' => null], ['name' => Rule::string()->nullable()->max(255)]);

    expect($validator->passes())->toBeTrue();
});

it('creates a string rule with required', function (): void {
    $validator = makeValidator([], ['name' => Rule::string()->required()]);

    expect($validator->passes())->toBeFalse();
});

it('creates a string rule with sometimes that skips when absent', function (): void {
    $validator = makeValidator([], ['name' => Rule::string()->sometimes()->min(2)]);

    expect($validator->passes())->toBeTrue();
});

// =========================================================================
// StringRule — type-specific constraints
// =========================================================================

it('validates string with min and max', function (): void {
    $v = makeValidator(['name' => 'John'], ['name' => Rule::string()->required()->min(2)->max(255)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['name' => 'J'], ['name' => Rule::string()->required()->min(2)->max(255)]);
    expect($v->passes())->toBeFalse();
});

it('validates string with chained modifiers and constraints', function (): void {
    $v = makeValidator(
        ['password' => 'short'],
        ['password' => Rule::string()->required()->when(true, fn ($r): StringRule => $r->min(12))->max(255)]
    );

    expect($v->passes())->toBeFalse();

    $v = makeValidator(
        ['password' => 'longenoughpassword'],
        ['password' => Rule::string()->required()->when(true, fn ($r): StringRule => $r->min(12))->max(255)]
    );

    expect($v->passes())->toBeTrue();
});

it('validates when condition is false does not apply', function (): void {
    $validator = makeValidator(
        ['name' => 'Jo'],
        ['name' => Rule::string()->required()->when(false, fn ($r): StringRule => $r->min(12))->max(255)]
    );

    expect($validator->passes())->toBeTrue();
});

// =========================================================================
// StringRule — rule() escape hatch
// =========================================================================

it('supports the rule escape hatch with a string', function (): void {
    $validator = makeValidator(
        ['name' => 'hello', 'other' => 'world'],
        ['name' => Rule::string()->required()->rule('different:other')]
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

    $v = makeValidator(['field' => 'valid'], ['field' => Rule::string()->required()->rule($customRule)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['field' => 'invalid'], ['field' => Rule::string()->required()->rule($customRule)]);
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// StringRule — conditional modifiers
// =========================================================================

it('validates required_if with field and value', function (): void {
    $v = makeValidator(
        ['role' => 'admin'],
        ['name' => Rule::string()->requiredIf('role', 'admin')]
    );

    expect($v->passes())->toBeFalse();

    $v = makeValidator(
        ['role' => 'user'],
        ['name' => Rule::string()->requiredIf('role', 'admin')]
    );

    expect($v->passes())->toBeTrue();
});

// =========================================================================
// present() modifier
// =========================================================================

it('validates present fails when field is absent', function (): void {
    $validator = makeValidator([], ['name' => Rule::string()->present()]);

    expect($validator->passes())->toBeFalse();
});

it('validates present passes when field is present', function (): void {
    $validator = makeValidator(['name' => ''], ['name' => Rule::string()->present()]);

    expect($validator->passes())->toBeTrue();
});

// =========================================================================
// StringRule — HasEmbeddedRules
// =========================================================================

it('validates string with in rule', function (): void {
    $v = makeValidator(
        ['status' => 'draft'],
        ['status' => Rule::string()->required()->in(['draft', 'published', 'archived'])]
    );

    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['status' => 'deleted'],
        ['status' => Rule::string()->required()->in(['draft', 'published', 'archived'])]
    );

    expect($v->passes())->toBeFalse();
});

it('validates string with enum rule', function (): void {
    $v = makeValidator(
        ['status' => 'active'],
        ['status' => Rule::string()->required()->enum(TestStringEnum::class)]
    );

    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['status' => 'nonexistent'],
        ['status' => Rule::string()->required()->enum(TestStringEnum::class)]
    );

    expect($v->passes())->toBeFalse();
});

// =========================================================================
// NumericRule
// =========================================================================

it('validates numeric with integer and min', function (): void {
    $validator = makeValidator(['age' => 25], ['age' => Rule::numeric()->integer()->min(0)]);

    expect($validator->passes())->toBeTrue();
});

it('validates numeric with required', function (): void {
    $validator = makeValidator([], ['age' => Rule::numeric()->required()]);

    expect($validator->passes())->toBeFalse();
});

it('validates numeric with nullable', function (): void {
    $validator = makeValidator(['age' => null], ['age' => Rule::numeric()->nullable()]);

    expect($validator->passes())->toBeTrue();
});

it('validates numeric with between', function (): void {
    $v = makeValidator(['price' => 15.5], ['price' => Rule::numeric()->between(10, 20)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['price' => 25], ['price' => Rule::numeric()->between(10, 20)]);
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// DateRule
// =========================================================================

it('validates date with after', function (): void {
    $validator = makeValidator(
        ['date' => '2099-01-01'],
        ['date' => Rule::date()->after('today')]
    );

    expect($validator->passes())->toBeTrue();
});

it('validates date with required', function (): void {
    $validator = makeValidator([], ['date' => Rule::date()->required()]);

    expect($validator->passes())->toBeFalse();
});

it('validates date with nullable', function (): void {
    $validator = makeValidator(['date' => null], ['date' => Rule::date()->nullable()]);

    expect($validator->passes())->toBeTrue();
});

it('validates date with format', function (): void {
    $v = makeValidator(
        ['date' => '01/15/2025'],
        ['date' => Rule::date()->format('m/d/Y')]
    );

    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['date' => '2025-01-15'],
        ['date' => Rule::date()->format('m/d/Y')]
    );

    expect($v->passes())->toBeFalse();
});

it('validates datetime shortcut', function (): void {
    $validator = makeValidator(
        ['timestamp' => '2025-01-15 14:30:00'],
        ['timestamp' => Rule::dateTime()]
    );

    expect($validator->passes())->toBeTrue();
});

// =========================================================================
// BooleanRule
// =========================================================================

it('validates boolean with required', function (): void {
    $v = makeValidator(['active' => true], ['active' => Rule::boolean()->required()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['active' => 'yes'], ['active' => Rule::boolean()->required()]);
    expect($v->passes())->toBeFalse();
});

it('validates boolean with nullable', function (): void {
    $validator = makeValidator(['active' => null], ['active' => Rule::boolean()->nullable()]);
    expect($validator->passes())->toBeTrue();
});

it('creates boolean accepted rule', function (): void {
    $v = makeValidator(['tos' => true], ['tos' => Rule::boolean()->accepted()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['tos' => false], ['tos' => Rule::boolean()->accepted()]);
    expect($v->passes())->toBeFalse();
});

it('creates boolean declined rule', function (): void {
    $validator = makeValidator(['opt_out' => false], ['opt_out' => Rule::boolean()->declined()]);
    expect($validator->passes())->toBeTrue();
});

// =========================================================================
// ArrayRule
// =========================================================================

it('validates array with required and min/max', function (): void {
    $v = makeValidator(
        ['tags' => ['php', 'laravel']],
        ['tags' => Rule::array()->required()->min(1)->max(10)]
    );

    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['tags' => []],
        ['tags' => Rule::array()->required()->min(1)]
    );

    expect($v->passes())->toBeFalse();
});

it('validates array with list', function (): void {
    $v = makeValidator(
        ['ids' => [1, 2, 3]],
        ['ids' => Rule::array()->required()->list()->min(1)]
    );

    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['ids' => ['a' => 1, 'b' => 2]],
        ['ids' => Rule::array()->required()->list()]
    );

    expect($v->passes())->toBeFalse();
});

it('validates array with keys', function (): void {
    $validator = makeValidator(
        ['data' => ['name' => 'John', 'email' => 'john@example.com']],
        ['data' => Rule::array(['name', 'email'])->required()]
    );

    expect($validator->passes())->toBeTrue();
});

it('validates array with nullable', function (): void {
    $validator = makeValidator(['items' => null], ['items' => Rule::array()->nullable()]);
    expect($validator->passes())->toBeTrue();
});

it('validates array with between', function (): void {
    $validator = makeValidator(
        ['items' => ['a', 'b', 'c']],
        ['items' => Rule::array()->between(1, 5)]
    );

    expect($validator->passes())->toBeTrue();
});

it('validates array with exactly', function (): void {
    $v = makeValidator(
        ['items' => ['a', 'b']],
        ['items' => Rule::array()->exactly(2)]
    );

    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['items' => ['a', 'b', 'c']],
        ['items' => Rule::array()->exactly(2)]
    );

    expect($v->passes())->toBeFalse();
});

it('validates array each() with scalar rule standalone', function (): void {
    $v = makeValidator(
        ['tags' => ['php', 'laravel']],
        ['tags' => Rule::array()->required()->each(Rule::string()->max(50))]
    );

    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['tags' => ['php', 123]],
        ['tags' => Rule::array()->required()->each(Rule::string()->max(50))]
    );

    expect($v->passes())->toBeFalse();
});

it('validates array each() with field mappings standalone', function (): void {
    $v = makeValidator(
        ['items' => [['name' => 'John', 'age' => 25], ['name' => 'Jane', 'age' => 30]]],
        ['items' => Rule::array()->required()->each([
            'name' => Rule::string()->required()->min(2),
            'age' => Rule::numeric()->required()->min(0),
        ])]
    );

    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['items' => [['name' => 'J', 'age' => 25]]],
        ['items' => Rule::array()->required()->each([
            'name' => Rule::string()->required()->min(2),
            'age' => Rule::numeric()->required()->min(0),
        ])]
    );

    expect($v->passes())->toBeFalse();
});

it('validates nested array each() standalone', function (): void {
    $v = makeValidator(
        ['matrix' => [['a', 'b'], ['c', 'd']]],
        ['matrix' => Rule::array()->required()->each(
            Rule::array()->each(Rule::string()->max(10))
        )]
    );

    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['matrix' => [['a', 123], ['c', 'd']]],
        ['matrix' => Rule::array()->required()->each(
            Rule::array()->each(Rule::string()->max(10))
        )]
    );

    expect($v->passes())->toBeFalse();
});

// =========================================================================
// Factory methods return correct types
// =========================================================================

it('returns correct rule types from factory', function (): void {
    expect(Rule::string())->toBeInstanceOf(StringRule::class);
    expect(Rule::numeric())->toBeInstanceOf(NumericRule::class);
    expect(Rule::date())->toBeInstanceOf(DateRule::class);
    expect(Rule::dateTime())->toBeInstanceOf(DateRule::class);
    expect(Rule::boolean())->toBeInstanceOf(BooleanRule::class);
    expect(Rule::array())->toBeInstanceOf(ArrayRule::class);
});

// =========================================================================
// Presence handling — optional fields (no modifier)
// =========================================================================

it('skips validation for absent field without presence modifier', function (): void {
    $validator = makeValidator([], ['name' => Rule::string()->min(2)]);

    expect($validator->passes())->toBeTrue();
});

it('skips validation for absent numeric field without presence modifier', function (): void {
    $validator = makeValidator([], ['age' => Rule::numeric()->min(0)]);

    expect($validator->passes())->toBeTrue();
});

it('skips validation for absent date field without presence modifier', function (): void {
    $validator = makeValidator([], ['date' => Rule::date()->after('today')]);

    expect($validator->passes())->toBeTrue();
});

it('skips validation for absent boolean field without presence modifier', function (): void {
    $validator = makeValidator([], ['active' => Rule::boolean()]);

    expect($validator->passes())->toBeTrue();
});

it('skips validation for absent array field without presence modifier', function (): void {
    $validator = makeValidator([], ['tags' => Rule::array()->min(1)]);

    expect($validator->passes())->toBeTrue();
});

it('still validates present field without presence modifier', function (): void {
    $validator = makeValidator(['name' => 123], ['name' => Rule::string()->min(2)]);

    expect($validator->passes())->toBeFalse();
});

// =========================================================================
// Presence handling — null without nullable
// =========================================================================

it('fails for null field without nullable modifier', function (): void {
    $validator = makeValidator(['name' => null], ['name' => Rule::string()->required()]);

    expect($validator->passes())->toBeFalse();
});

it('fails for null numeric field without nullable modifier', function (): void {
    $validator = makeValidator(['age' => null], ['age' => Rule::numeric()->required()]);

    expect($validator->passes())->toBeFalse();
});

// =========================================================================
// Presence handling — requiredIf with closure/bool
// =========================================================================

it('validates requiredIf with true bool triggers required', function (): void {
    $validator = makeValidator([], ['name' => Rule::string()->requiredIf(true)]);

    expect($validator->passes())->toBeFalse();
});

it('validates requiredIf with false bool skips required', function (): void {
    $validator = makeValidator([], ['name' => Rule::string()->requiredIf(false)]);

    expect($validator->passes())->toBeTrue();
});

it('validates requiredIf with closure', function (): void {
    $validator = makeValidator([], ['name' => Rule::string()->requiredIf(fn (): true => true)]);

    expect($validator->passes())->toBeFalse();
});

// =========================================================================
// notIn validation
// =========================================================================

it('validates string with notIn rule passes', function (): void {
    $validator = makeValidator(
        ['role' => 'editor'],
        ['role' => Rule::string()->required()->notIn(['admin', 'root'])]
    );

    expect($validator->passes())->toBeTrue();
});

it('validates string with notIn rule fails', function (): void {
    $validator = makeValidator(
        ['role' => 'admin'],
        ['role' => Rule::string()->required()->notIn(['admin', 'root'])]
    );

    expect($validator->passes())->toBeFalse();
});

// =========================================================================
// Numeric — enum with int enum
// =========================================================================

it('validates numeric with int enum', function (): void {
    $v = makeValidator(
        ['priority' => 1],
        ['priority' => Rule::numeric()->required()->enum(TestIntEnum::class)]
    );

    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['priority' => 99],
        ['priority' => Rule::numeric()->required()->enum(TestIntEnum::class)]
    );

    expect($v->passes())->toBeFalse();
});

// =========================================================================
// Error message propagation
// =========================================================================

it('propagates error messages from sub-validator', function (): void {
    $validator = makeValidator(
        ['name' => ''],
        ['name' => Rule::string()->required()->min(2)]
    );

    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->get('name'))->not->toBeEmpty();
});

it('has no errors when validation passes', function (): void {
    $validator = makeValidator(
        ['name' => 'John'],
        ['name' => Rule::string()->required()->min(2)]
    );

    expect($validator->passes())->toBeTrue();
    expect($validator->errors()->get('name'))->toBeEmpty();
});

// =========================================================================
// StringRule — alpha / alphaNumeric / alphaDash / ascii
// =========================================================================

it('validates string with alpha', function (): void {
    $v = makeValidator(['name' => 'John'], ['name' => Rule::string()->alpha()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['name' => 'John123'], ['name' => Rule::string()->alpha()]);
    expect($v->passes())->toBeFalse();
});

it('validates string with alpha ascii', function (): void {
    $v = makeValidator(['name' => 'John'], ['name' => Rule::string()->alpha(true)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['name' => 'Jöhn'], ['name' => Rule::string()->alpha(true)]);
    expect($v->passes())->toBeFalse();
});

it('validates string with alphaDash', function (): void {
    $v = makeValidator(['slug' => 'my-slug_v2'], ['slug' => Rule::string()->alphaDash()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['slug' => 'has spaces'], ['slug' => Rule::string()->alphaDash()]);
    expect($v->passes())->toBeFalse();
});

it('validates string with alphaDash ascii', function (): void {
    $validator = makeValidator(['slug' => 'my-slug'], ['slug' => Rule::string()->alphaDash(true)]);
    expect($validator->passes())->toBeTrue();
});

it('validates string with alphaNumeric', function (): void {
    $v = makeValidator(['code' => 'abc123'], ['code' => Rule::string()->alphaNumeric()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['code' => 'abc-123'], ['code' => Rule::string()->alphaNumeric()]);
    expect($v->passes())->toBeFalse();
});

it('validates string with alphaNumeric ascii', function (): void {
    $validator = makeValidator(['code' => 'abc123'], ['code' => Rule::string()->alphaNumeric(true)]);
    expect($validator->passes())->toBeTrue();
});

it('validates string with ascii', function (): void {
    $v = makeValidator(['text' => 'hello'], ['text' => Rule::string()->ascii()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['text' => 'héllo'], ['text' => Rule::string()->ascii()]);
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// StringRule — between / exactly / startsWith / endsWith / doesntStartWith / doesntEndWith
// =========================================================================

it('validates string with between', function (): void {
    $v = makeValidator(['name' => 'John'], ['name' => Rule::string()->between(2, 10)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['name' => 'J'], ['name' => Rule::string()->between(2, 10)]);
    expect($v->passes())->toBeFalse();
});

it('validates string with exactly', function (): void {
    $v = makeValidator(['code' => 'abcd'], ['code' => Rule::string()->exactly(4)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['code' => 'abc'], ['code' => Rule::string()->exactly(4)]);
    expect($v->passes())->toBeFalse();
});

it('validates string with startsWith', function (): void {
    $v = makeValidator(['url' => 'https://example.com'], ['url' => Rule::string()->startsWith('https://')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['url' => 'http://example.com'], ['url' => Rule::string()->startsWith('https://')]);
    expect($v->passes())->toBeFalse();
});

it('validates string with endsWith', function (): void {
    $v = makeValidator(['file' => 'photo.jpg'], ['file' => Rule::string()->endsWith('.jpg', '.png')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['file' => 'photo.gif'], ['file' => Rule::string()->endsWith('.jpg', '.png')]);
    expect($v->passes())->toBeFalse();
});

it('validates string with doesntStartWith', function (): void {
    $v = makeValidator(['name' => 'hello'], ['name' => Rule::string()->doesntStartWith('foo', 'bar')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['name' => 'foobar'], ['name' => Rule::string()->doesntStartWith('foo', 'bar')]);
    expect($v->passes())->toBeFalse();
});

it('validates string with doesntEndWith', function (): void {
    $v = makeValidator(['name' => 'hello'], ['name' => Rule::string()->doesntEndWith('world', 'bar')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['name' => 'helloworld'], ['name' => Rule::string()->doesntEndWith('world', 'bar')]);
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// StringRule — case
// =========================================================================

it('validates string with lowercase', function (): void {
    $v = makeValidator(['name' => 'hello'], ['name' => Rule::string()->lowercase()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['name' => 'Hello'], ['name' => Rule::string()->lowercase()]);
    expect($v->passes())->toBeFalse();
});

it('validates string with uppercase', function (): void {
    $v = makeValidator(['name' => 'HELLO'], ['name' => Rule::string()->uppercase()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['name' => 'Hello'], ['name' => Rule::string()->uppercase()]);
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// StringRule — url / uuid / ulid / json / ip / macAddress / timezone / hexColor
// =========================================================================

it('validates string with url', function (): void {
    $v = makeValidator(['site' => 'https://example.com'], ['site' => Rule::string()->url()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['site' => 'not-a-url'], ['site' => Rule::string()->url()]);
    expect($v->passes())->toBeFalse();
});

it('validates string with activeUrl', function (): void {
    $v = makeValidator(['site' => 'https://example.com'], ['site' => Rule::string()->activeUrl()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['site' => 'https://thisdomaindoesnotexist12345.invalid'], ['site' => Rule::string()->activeUrl()]);
    expect($v->passes())->toBeFalse();
});

it('validates string with uuid', function (): void {
    $v = makeValidator(['id' => '550e8400-e29b-41d4-a716-446655440000'], ['id' => Rule::string()->uuid()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['id' => 'not-a-uuid'], ['id' => Rule::string()->uuid()]);
    expect($v->passes())->toBeFalse();
});

it('validates string with ulid', function (): void {
    $v = makeValidator(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'], ['id' => Rule::string()->ulid()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['id' => 'not-a-ulid'], ['id' => Rule::string()->ulid()]);
    expect($v->passes())->toBeFalse();
});

it('validates string with json', function (): void {
    $v = makeValidator(['data' => '{"key":"value"}'], ['data' => Rule::string()->json()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['data' => 'not-json'], ['data' => Rule::string()->json()]);
    expect($v->passes())->toBeFalse();
});

it('validates string with ip', function (): void {
    $v = makeValidator(['addr' => '192.168.1.1'], ['addr' => Rule::string()->ip()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['addr' => 'not-an-ip'], ['addr' => Rule::string()->ip()]);
    expect($v->passes())->toBeFalse();
});

it('validates string with ipv4', function (): void {
    $v = makeValidator(['addr' => '192.168.1.1'], ['addr' => Rule::string()->ipv4()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['addr' => '::1'], ['addr' => Rule::string()->ipv4()]);
    expect($v->passes())->toBeFalse();
});

it('validates string with ipv6', function (): void {
    $v = makeValidator(['addr' => '::1'], ['addr' => Rule::string()->ipv6()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['addr' => '192.168.1.1'], ['addr' => Rule::string()->ipv6()]);
    expect($v->passes())->toBeFalse();
});

it('validates string with macAddress', function (): void {
    $v = makeValidator(['mac' => '00:1B:44:11:3A:B7'], ['mac' => Rule::string()->macAddress()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['mac' => 'not-a-mac'], ['mac' => Rule::string()->macAddress()]);
    expect($v->passes())->toBeFalse();
});

it('validates string with timezone', function (): void {
    $v = makeValidator(['tz' => 'America/New_York'], ['tz' => Rule::string()->timezone()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['tz' => 'Not/A_Timezone'], ['tz' => Rule::string()->timezone()]);
    expect($v->passes())->toBeFalse();
});

it('validates string with hexColor', function (): void {
    $v = makeValidator(['color' => '#ff0000'], ['color' => Rule::string()->hexColor()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['color' => 'red'], ['color' => Rule::string()->hexColor()]);
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// StringRule — regex / notRegex
// =========================================================================

it('validates string with regex', function (): void {
    $v = makeValidator(['code' => 'ABC-123'], ['code' => Rule::string()->regex('/^[A-Z]+-\d+$/')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['code' => 'abc'], ['code' => Rule::string()->regex('/^[A-Z]+-\d+$/')]);
    expect($v->passes())->toBeFalse();
});

it('validates string with notRegex', function (): void {
    $v = makeValidator(['name' => 'hello'], ['name' => Rule::string()->notRegex('/\d/')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['name' => 'hello123'], ['name' => Rule::string()->notRegex('/\d/')]);
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// StringRule — date / dateFormat / confirmed / same / different
// =========================================================================

it('validates string with date', function (): void {
    $v = makeValidator(['d' => '2025-01-15'], ['d' => Rule::string()->date()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => 'not-a-date'], ['d' => Rule::string()->date()]);
    expect($v->passes())->toBeFalse();
});

it('validates string with dateFormat', function (): void {
    $v = makeValidator(['d' => '15/01/2025'], ['d' => Rule::string()->dateFormat('d/m/Y')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2025-01-15'], ['d' => Rule::string()->dateFormat('d/m/Y')]);
    expect($v->passes())->toBeFalse();
});

it('validates string with confirmed', function (): void {
    $v = makeValidator(
        ['password' => 'secret', 'password_confirmation' => 'secret'],
        ['password' => Rule::string()->confirmed()]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['password' => 'secret', 'password_confirmation' => 'different'],
        ['password' => Rule::string()->confirmed()]
    );
    expect($v->passes())->toBeFalse();
});

it('validates string with same', function (): void {
    $v = makeValidator(
        ['password' => 'secret', 'confirm' => 'secret'],
        ['password' => Rule::string()->same('confirm')]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['password' => 'secret', 'confirm' => 'other'],
        ['password' => Rule::string()->same('confirm')]
    );
    expect($v->passes())->toBeFalse();
});

it('validates string with different', function (): void {
    $v = makeValidator(
        ['name' => 'John', 'other' => 'Jane'],
        ['name' => Rule::string()->different('other')]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['name' => 'John', 'other' => 'John'],
        ['name' => Rule::string()->different('other')]
    );
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// StringRule — inArray / distinct
// =========================================================================

it('validates string with inArray', function (): void {
    $v = makeValidator(
        ['name' => 'John', 'names' => ['John', 'Jane']],
        ['name' => Rule::string()->inArray('names.*')]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['name' => 'Bob', 'names' => ['John', 'Jane']],
        ['name' => Rule::string()->inArray('names.*')]
    );
    expect($v->passes())->toBeFalse();
});

it('compiles string with distinct rule', function (): void {
    $stringRule = Rule::string()->distinct();
    expect($stringRule->compiledRules())->toBe('string|distinct');
});

it('compiles string with distinct strict mode rule', function (): void {
    $stringRule = Rule::string()->distinct('strict');
    expect($stringRule->compiledRules())->toBe('string|distinct:strict');
});

// =========================================================================
// NumericRule — min / max / decimal / digits / digitsBetween
// =========================================================================

it('validates numeric with min', function (): void {
    $v = makeValidator(['age' => 18], ['age' => Rule::numeric()->min(18)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['age' => 17], ['age' => Rule::numeric()->min(18)]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with max', function (): void {
    $v = makeValidator(['age' => 100], ['age' => Rule::numeric()->max(120)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['age' => 150], ['age' => Rule::numeric()->max(120)]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with decimal', function (): void {
    $v = makeValidator(['price' => '10.50'], ['price' => Rule::numeric()->decimal(2)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['price' => '10.5'], ['price' => Rule::numeric()->decimal(2)]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with decimal min and max', function (): void {
    $v = makeValidator(['price' => '10.5'], ['price' => Rule::numeric()->decimal(1, 3)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['price' => '10.5000'], ['price' => Rule::numeric()->decimal(1, 3)]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with digits', function (): void {
    $v = makeValidator(['pin' => 1234], ['pin' => Rule::numeric()->digits(4)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['pin' => 123], ['pin' => Rule::numeric()->digits(4)]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with digitsBetween', function (): void {
    $v = makeValidator(['code' => 12345], ['code' => Rule::numeric()->digitsBetween(4, 6)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['code' => 123], ['code' => Rule::numeric()->digitsBetween(4, 6)]);
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// NumericRule — greaterThan / greaterThanOrEqualTo / lessThan / lessThanOrEqualTo
// =========================================================================

it('validates numeric with greaterThan', function (): void {
    $v = makeValidator(['max' => 20, 'min' => 10], ['max' => Rule::numeric()->greaterThan('min')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['max' => 5, 'min' => 10], ['max' => Rule::numeric()->greaterThan('min')]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with greaterThanOrEqualTo', function (): void {
    $v = makeValidator(['max' => 10, 'min' => 10], ['max' => Rule::numeric()->greaterThanOrEqualTo('min')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['max' => 9, 'min' => 10], ['max' => Rule::numeric()->greaterThanOrEqualTo('min')]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with lessThan', function (): void {
    $v = makeValidator(['min' => 5, 'max' => 10], ['min' => Rule::numeric()->lessThan('max')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['min' => 15, 'max' => 10], ['min' => Rule::numeric()->lessThan('max')]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with lessThanOrEqualTo', function (): void {
    $v = makeValidator(['min' => 10, 'max' => 10], ['min' => Rule::numeric()->lessThanOrEqualTo('max')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['min' => 11, 'max' => 10], ['min' => Rule::numeric()->lessThanOrEqualTo('max')]);
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// NumericRule — multipleOf / maxDigits / minDigits / exactly / same / different
// =========================================================================

it('validates numeric with multipleOf', function (): void {
    $v = makeValidator(['qty' => 15], ['qty' => Rule::numeric()->multipleOf(5)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['qty' => 13], ['qty' => Rule::numeric()->multipleOf(5)]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with maxDigits', function (): void {
    $v = makeValidator(['num' => 999], ['num' => Rule::numeric()->maxDigits(3)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['num' => 10000], ['num' => Rule::numeric()->maxDigits(3)]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with minDigits', function (): void {
    $v = makeValidator(['num' => 100], ['num' => Rule::numeric()->minDigits(3)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['num' => 10], ['num' => Rule::numeric()->minDigits(3)]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with exactly', function (): void {
    $v = makeValidator(['count' => 5], ['count' => Rule::numeric()->exactly(5)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['count' => 6], ['count' => Rule::numeric()->exactly(5)]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with same', function (): void {
    $v = makeValidator(['a' => 10, 'b' => 10], ['a' => Rule::numeric()->same('b')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['a' => 10, 'b' => 20], ['a' => Rule::numeric()->same('b')]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with different', function (): void {
    $v = makeValidator(['a' => 10, 'b' => 20], ['a' => Rule::numeric()->different('b')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['a' => 10, 'b' => 10], ['a' => Rule::numeric()->different('b')]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with confirmed', function (): void {
    $v = makeValidator(
        ['amount' => 100, 'amount_confirmation' => 100],
        ['amount' => Rule::numeric()->confirmed()]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['amount' => 100, 'amount_confirmation' => 200],
        ['amount' => Rule::numeric()->confirmed()]
    );
    expect($v->passes())->toBeFalse();
});

it('validates numeric with inArray', function (): void {
    $v = makeValidator(
        ['val' => 2, 'allowed' => [1, 2, 3]],
        ['val' => Rule::numeric()->inArray('allowed.*')]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['val' => 9, 'allowed' => [1, 2, 3]],
        ['val' => Rule::numeric()->inArray('allowed.*')]
    );
    expect($v->passes())->toBeFalse();
});

it('compiles numeric with distinct rule', function (): void {
    $numericRule = Rule::numeric()->distinct();
    expect($numericRule->compiledRules())->toBe('numeric|distinct');
});

it('validates numeric with integer strict mode', function (): void {
    $validator = makeValidator(['num' => 5], ['num' => Rule::numeric()->integer(true)]);
    expect($validator->passes())->toBeTrue();
});

// =========================================================================
// DateRule — before / beforeToday / afterToday / todayOrBefore / todayOrAfter
// =========================================================================

it('validates date with before', function (): void {
    $v = makeValidator(['d' => '2020-01-01'], ['d' => Rule::date()->before('2025-01-01')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2030-01-01'], ['d' => Rule::date()->before('2025-01-01')]);
    expect($v->passes())->toBeFalse();
});

it('validates date with beforeToday', function (): void {
    $v = makeValidator(['d' => '2000-01-01'], ['d' => Rule::date()->beforeToday()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2099-01-01'], ['d' => Rule::date()->beforeToday()]);
    expect($v->passes())->toBeFalse();
});

it('validates date with afterToday', function (): void {
    $v = makeValidator(['d' => '2099-01-01'], ['d' => Rule::date()->afterToday()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2000-01-01'], ['d' => Rule::date()->afterToday()]);
    expect($v->passes())->toBeFalse();
});

it('validates date with todayOrBefore', function (): void {
    $validator = makeValidator(['d' => '2000-01-01'], ['d' => Rule::date()->todayOrBefore()]);
    expect($validator->passes())->toBeTrue();
});

it('validates date with todayOrAfter', function (): void {
    $validator = makeValidator(['d' => '2099-01-01'], ['d' => Rule::date()->todayOrAfter()]);
    expect($validator->passes())->toBeTrue();
});

// =========================================================================
// DateRule — past / future / nowOrPast / nowOrFuture
// =========================================================================

it('validates date with past', function (): void {
    $v = makeValidator(['d' => '2000-01-01 00:00:00'], ['d' => Rule::date()->past()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2099-01-01 00:00:00'], ['d' => Rule::date()->past()]);
    expect($v->passes())->toBeFalse();
});

it('validates date with future', function (): void {
    $v = makeValidator(['d' => '2099-01-01 00:00:00'], ['d' => Rule::date()->future()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2000-01-01 00:00:00'], ['d' => Rule::date()->future()]);
    expect($v->passes())->toBeFalse();
});

it('validates date with nowOrPast', function (): void {
    $validator = makeValidator(['d' => '2000-01-01 00:00:00'], ['d' => Rule::date()->nowOrPast()]);
    expect($validator->passes())->toBeTrue();
});

it('validates date with nowOrFuture', function (): void {
    $validator = makeValidator(['d' => '2099-01-01 00:00:00'], ['d' => Rule::date()->nowOrFuture()]);
    expect($validator->passes())->toBeTrue();
});

// =========================================================================
// DateRule — beforeOrEqual / afterOrEqual / between / betweenOrEqual / dateEquals
// =========================================================================

it('validates date with beforeOrEqual', function (): void {
    $v = makeValidator(['d' => '2025-01-01'], ['d' => Rule::date()->beforeOrEqual('2025-01-01')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2025-01-02'], ['d' => Rule::date()->beforeOrEqual('2025-01-01')]);
    expect($v->passes())->toBeFalse();
});

it('validates date with afterOrEqual', function (): void {
    $v = makeValidator(['d' => '2025-01-01'], ['d' => Rule::date()->afterOrEqual('2025-01-01')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2024-12-31'], ['d' => Rule::date()->afterOrEqual('2025-01-01')]);
    expect($v->passes())->toBeFalse();
});

it('validates date with between', function (): void {
    $v = makeValidator(['d' => '2025-06-15'], ['d' => Rule::date()->between('2025-01-01', '2025-12-31')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2024-06-15'], ['d' => Rule::date()->between('2025-01-01', '2025-12-31')]);
    expect($v->passes())->toBeFalse();
});

it('validates date with betweenOrEqual', function (): void {
    $v = makeValidator(['d' => '2025-01-01'], ['d' => Rule::date()->betweenOrEqual('2025-01-01', '2025-12-31')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2024-12-31'], ['d' => Rule::date()->betweenOrEqual('2025-01-01', '2025-12-31')]);
    expect($v->passes())->toBeFalse();
});

it('validates date with dateEquals', function (): void {
    $v = makeValidator(['d' => '2025-01-15'], ['d' => Rule::date()->dateEquals('2025-01-15')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2025-01-16'], ['d' => Rule::date()->dateEquals('2025-01-15')]);
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// DateRule — same / different
// =========================================================================

it('validates date with same', function (): void {
    $v = makeValidator(['d' => '2025-01-01', 'other' => '2025-01-01'], ['d' => Rule::date()->same('other')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2025-01-01', 'other' => '2025-01-02'], ['d' => Rule::date()->same('other')]);
    expect($v->passes())->toBeFalse();
});

it('validates date with different', function (): void {
    $v = makeValidator(['d' => '2025-01-01', 'other' => '2025-01-02'], ['d' => Rule::date()->different('other')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2025-01-01', 'other' => '2025-01-01'], ['d' => Rule::date()->different('other')]);
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// DateRule — DateTimeInterface arguments
// =========================================================================

it('validates date with DateTimeInterface argument', function (): void {
    $cutoff = CarbonImmutable::parse('2025-06-01');

    $v = makeValidator(['d' => '2025-01-01'], ['d' => Rule::date()->before($cutoff)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2025-12-01'], ['d' => Rule::date()->before($cutoff)]);
    expect($v->passes())->toBeFalse();
});

it('validates date with DateTimeInterface and custom format', function (): void {
    $cutoff = CarbonImmutable::parse('2025-06-01');

    $validator = makeValidator(['d' => '01/01/2025'], ['d' => Rule::date()->format('m/d/Y')->before($cutoff)]);
    expect($validator->passes())->toBeTrue();
});

// =========================================================================
// BooleanRule — acceptedIf / declinedIf
// =========================================================================

it('validates boolean with acceptedIf', function (): void {
    $v = makeValidator(
        ['tos' => true, 'country' => 'US'],
        ['tos' => Rule::boolean()->acceptedIf('country', 'US')]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['tos' => false, 'country' => 'US'],
        ['tos' => Rule::boolean()->acceptedIf('country', 'US')]
    );
    expect($v->passes())->toBeFalse();
});

it('validates boolean with declinedIf', function (): void {
    $v = makeValidator(
        ['opt_in' => false, 'type' => 'minor'],
        ['opt_in' => Rule::boolean()->declinedIf('type', 'minor')]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['opt_in' => true, 'type' => 'minor'],
        ['opt_in' => Rule::boolean()->declinedIf('type', 'minor')]
    );
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// ArrayRule — requiredArrayKeys / BackedEnum keys
// =========================================================================

it('validates array with requiredArrayKeys', function (): void {
    $v = makeValidator(
        ['data' => ['name' => 'John', 'email' => 'john@test.com']],
        ['data' => Rule::array()->requiredArrayKeys('name', 'email')]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['data' => ['name' => 'John']],
        ['data' => Rule::array()->requiredArrayKeys('name', 'email')]
    );
    expect($v->passes())->toBeFalse();
});

it('validates array with BackedEnum keys', function (): void {
    $v = makeValidator(
        ['data' => ['low' => 1, 'medium' => 2]],
        ['data' => Rule::array([TestArrayKeyEnum::Low, TestArrayKeyEnum::Medium])]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['data' => ['low' => 1, 'medium' => 2, 'unknown' => 3]],
        ['data' => Rule::array([TestArrayKeyEnum::Low, TestArrayKeyEnum::Medium])]
    );
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// HasFieldModifiers — filled / prohibited / missing
// =========================================================================

it('validates field with filled', function (): void {
    $v = makeValidator(['name' => 'John'], ['name' => Rule::string()->filled()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['name' => ''], ['name' => Rule::string()->filled()]);
    expect($v->passes())->toBeFalse();
});

it('validates field with prohibited', function (): void {
    $v = makeValidator(['secret' => 'value'], ['secret' => Rule::string()->prohibited()]);
    expect($v->passes())->toBeFalse();

    $v = makeValidator([], ['secret' => Rule::string()->prohibited()]);
    expect($v->passes())->toBeTrue();
});

it('validates field with missing', function (): void {
    $v = makeValidator([], ['secret' => Rule::string()->missing()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['secret' => 'value'], ['secret' => Rule::string()->missing()]);
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// HasFieldModifiers — requiredUnless
// =========================================================================

it('validates requiredUnless with field and value', function (): void {
    $v = makeValidator(
        ['role' => 'guest'],
        ['name' => Rule::string()->requiredUnless('role', 'guest')]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['role' => 'admin'],
        ['name' => Rule::string()->requiredUnless('role', 'guest')]
    );
    expect($v->passes())->toBeFalse();
});

it('validates requiredUnless with true bool triggers required', function (): void {
    $validator = makeValidator([], ['name' => Rule::string()->requiredUnless(true)]);
    expect($validator->passes())->toBeTrue();
});

it('validates requiredUnless with false bool triggers required', function (): void {
    $validator = makeValidator([], ['name' => Rule::string()->requiredUnless(false)]);
    expect($validator->passes())->toBeFalse();
});

it('validates requiredUnless with closure', function (): void {
    $validator = makeValidator([], ['name' => Rule::string()->requiredUnless(fn (): false => false)]);
    expect($validator->passes())->toBeFalse();
});

// =========================================================================
// HasFieldModifiers — requiredWith / requiredWithAll / requiredWithout / requiredWithoutAll
// =========================================================================

it('validates requiredWith', function (): void {
    $v = makeValidator(
        ['email' => 'test@test.com'],
        ['name' => Rule::string()->requiredWith('email')]
    );
    expect($v->passes())->toBeFalse();

    $v = makeValidator(
        [],
        ['name' => Rule::string()->requiredWith('email')]
    );
    expect($v->passes())->toBeTrue();
});

it('validates requiredWithAll', function (): void {
    $v = makeValidator(
        ['first' => 'a', 'last' => 'b'],
        ['full' => Rule::string()->requiredWithAll('first', 'last')]
    );
    expect($v->passes())->toBeFalse();

    $v = makeValidator(
        ['first' => 'a'],
        ['full' => Rule::string()->requiredWithAll('first', 'last')]
    );
    expect($v->passes())->toBeTrue();
});

it('validates requiredWithout', function (): void {
    $v = makeValidator(
        [],
        ['name' => Rule::string()->requiredWithout('nickname')]
    );
    expect($v->passes())->toBeFalse();

    $v = makeValidator(
        ['nickname' => 'Johnny'],
        ['name' => Rule::string()->requiredWithout('nickname')]
    );
    expect($v->passes())->toBeTrue();
});

it('validates requiredWithoutAll', function (): void {
    $v = makeValidator(
        [],
        ['name' => Rule::string()->requiredWithoutAll('first_name', 'last_name')]
    );
    expect($v->passes())->toBeFalse();

    $v = makeValidator(
        ['first_name' => 'John'],
        ['name' => Rule::string()->requiredWithoutAll('first_name', 'last_name')]
    );
    expect($v->passes())->toBeTrue();
});

// =========================================================================
// HasFieldModifiers — excludeIf / excludeUnless / excludeWith / excludeWithout
// =========================================================================

it('validates excludeIf', function (): void {
    $validator = makeValidator(
        ['type' => 'free', 'price' => '100'],
        ['price' => Rule::string()->excludeIf('type', 'free')]
    );
    expect($validator->passes())->toBeTrue();
});

it('validates excludeUnless', function (): void {
    $validator = makeValidator(
        ['type' => 'free', 'price' => '100'],
        ['price' => Rule::string()->excludeUnless('type', 'paid')]
    );
    expect($validator->passes())->toBeTrue();
});

it('validates excludeWith', function (): void {
    $validator = makeValidator(
        ['other' => 'val', 'field' => 'val'],
        ['field' => Rule::string()->excludeWith('other')]
    );
    expect($validator->passes())->toBeTrue();
});

it('validates excludeWithout', function (): void {
    $validator = makeValidator(
        ['field' => 'val'],
        ['field' => Rule::string()->excludeWithout('other')]
    );
    expect($validator->passes())->toBeTrue();
});

// =========================================================================
// HasFieldModifiers — prohibitedIf / prohibitedUnless / prohibits
// =========================================================================

it('validates prohibitedIf with field and value', function (): void {
    $v = makeValidator(
        ['type' => 'free', 'price' => '100'],
        ['price' => Rule::string()->prohibitedIf('type', 'free')]
    );
    expect($v->passes())->toBeFalse();

    $v = makeValidator(
        ['type' => 'paid', 'price' => '100'],
        ['price' => Rule::string()->prohibitedIf('type', 'free')]
    );
    expect($v->passes())->toBeTrue();
});

it('validates prohibitedIf with bool', function (): void {
    $v = makeValidator(['field' => 'val'], ['field' => Rule::string()->prohibitedIf(true)]);
    expect($v->passes())->toBeFalse();

    $v = makeValidator(['field' => 'val'], ['field' => Rule::string()->prohibitedIf(false)]);
    expect($v->passes())->toBeTrue();
});

it('validates prohibitedIf with closure', function (): void {
    $validator = makeValidator(['field' => 'val'], ['field' => Rule::string()->prohibitedIf(fn (): true => true)]);
    expect($validator->passes())->toBeFalse();
});

it('validates prohibitedUnless with field and value', function (): void {
    $v = makeValidator(
        ['type' => 'free', 'price' => '100'],
        ['price' => Rule::string()->prohibitedUnless('type', 'paid')]
    );
    expect($v->passes())->toBeFalse();

    $v = makeValidator(
        ['type' => 'paid', 'price' => '100'],
        ['price' => Rule::string()->prohibitedUnless('type', 'paid')]
    );
    expect($v->passes())->toBeTrue();
});

it('validates prohibitedUnless with bool', function (): void {
    $v = makeValidator(['field' => 'val'], ['field' => Rule::string()->prohibitedUnless(true)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['field' => 'val'], ['field' => Rule::string()->prohibitedUnless(false)]);
    expect($v->passes())->toBeFalse();
});

it('validates prohibits', function (): void {
    $v = makeValidator(
        ['field' => 'val', 'other' => 'val'],
        ['field' => Rule::string()->prohibits('other')]
    );
    expect($v->passes())->toBeFalse();

    $v = makeValidator(
        ['field' => 'val'],
        ['field' => Rule::string()->prohibits('other')]
    );
    expect($v->passes())->toBeTrue();
});

// =========================================================================
// HasEmbeddedRules — enum with callback
// =========================================================================

it('validates enum with callback modifier', function (): void {
    $v = makeValidator(
        ['status' => 'active'],
        ['status' => Rule::string()->required()->enum(TestStringEnum::class, fn ($rule) => $rule->only(TestStringEnum::Active))]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['status' => 'inactive'],
        ['status' => Rule::string()->required()->enum(TestStringEnum::class, fn ($rule) => $rule->only(TestStringEnum::Active))]
    );
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// SelfValidates — canCompile / compiledRules
// =========================================================================

it('reports canCompile true when no object rules', function (): void {
    $stringRule = Rule::string()->required()->min(2)->max(255);
    expect($stringRule->canCompile())->toBeTrue();
});

it('reports canCompile false when object rules present', function (): void {
    $stringRule = Rule::string()->required()->in(['a', 'b']);
    expect($stringRule->canCompile())->toBeFalse();
});

it('compiles to pipe-joined string when no object rules', function (): void {
    $stringRule = Rule::string()->required()->min(2)->max(255);
    expect($stringRule->compiledRules())->toBe('string|required|min:2|max:255');
});

it('compiles stringable object rules to pipe string', function (): void {
    $stringRule = Rule::string()->required()->in(['a', 'b']);
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
    $stringRule = Rule::string()->inArrayKeys('options.*');
    expect($stringRule->compiledRules())->toBe('string|in_array_keys:options.*');
});

it('compiles string with currentPassword rule', function (): void {
    $stringRule = Rule::string()->currentPassword();
    expect($stringRule->compiledRules())->toBe('string|current_password');
});

it('compiles string with currentPassword and guard rule', function (): void {
    $stringRule = Rule::string()->currentPassword('api');
    expect($stringRule->compiledRules())->toBe('string|current_password:api');
});

// =========================================================================
// NumericRule — inArrayKeys
// =========================================================================

it('compiles numeric with inArrayKeys rule', function (): void {
    $numericRule = Rule::numeric()->inArrayKeys('options.*');
    expect($numericRule->compiledRules())->toBe('numeric|in_array_keys:options.*');
});

// =========================================================================
// HasEmbeddedRules — unique / exists
// =========================================================================

it('compiles unique rule to pipe string', function (): void {
    $stringRule = Rule::string()->unique('users', 'email');
    expect($stringRule->canCompile())->toBeFalse();
    $compiled = $stringRule->compiledRules();
    expect($compiled)->toBeString();
    expect($compiled)->toContain('unique:');
});

it('compiles unique rule with default column to pipe string', function (): void {
    $stringRule = Rule::string()->unique('users');
    expect($stringRule->canCompile())->toBeFalse();
    $compiled = $stringRule->compiledRules();
    expect($compiled)->toBeString();
    expect($compiled)->toContain('unique:');
});

it('compiles exists rule to pipe string', function (): void {
    $stringRule = Rule::string()->exists('users', 'email');
    expect($stringRule->canCompile())->toBeFalse();
    $compiled = $stringRule->compiledRules();
    expect($compiled)->toBeString();
    expect($compiled)->toContain('exists:');
});

it('compiles exists rule with default column to pipe string', function (): void {
    $stringRule = Rule::string()->exists('users');
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
        'name' => Rule::string()->required()->min(2),
        'age' => Rule::numeric()->integer(),
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
    $arrayRule = Rule::array();
    expect($arrayRule->getEachRules())->toBeNull();
});

it('returns each rules from getEachRules', function (): void {
    $stringRule = Rule::string()->required();
    $arrayRule = Rule::array()->each($stringRule);
    expect($arrayRule->getEachRules())->toBe($stringRule);
});

it('withoutEachRules returns clone without each rules', function (): void {
    $arrayRule = Rule::array()->required()->each(Rule::string());
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
        ['slug' => Rule::string()->slug()]
    );

    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['slug' => 'Hello World'],
        ['slug' => Rule::string()->slug()]
    );

    expect($v->passes())->toBeFalse();
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
