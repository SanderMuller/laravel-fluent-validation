<?php declare(strict_types=1);

use Illuminate\Contracts\Validation\ValidationRule;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\Rules\StringRule;
use SanderMuller\FluentValidation\Tests\Fixtures\TestStringEnum;

// =========================================================================
// StringRule — field modifiers
// =========================================================================

it('creates a string rule with bail', function (): void {
    $validator = makeValidator(['name' => 123], ['name' => FluentRule::string()->bail()->min(2)->max(255)]);

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->get('name'))->toHaveCount(1);
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
        ['password' => FluentRule::string()->required()->when(true, fn (StringRule $r): StringRule => $r->min(12))->max(255)]
    );

    expect($v->passes())->toBeFalse();

    $v = makeValidator(
        ['password' => 'longenoughpassword'],
        ['password' => FluentRule::string()->required()->when(true, fn (StringRule $r): StringRule => $r->min(12))->max(255)]
    );

    expect($v->passes())->toBeTrue();
});

it('validates when condition is false does not apply', function (): void {
    $validator = makeValidator(
        ['name' => 'Jo'],
        ['name' => FluentRule::string()->required()->when(false, fn (StringRule $r): StringRule => $r->min(12))->max(255)]
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
// StringRule — email()
// =========================================================================

it('validates email on string rule', function (): void {
    $v = makeValidator(['email' => 'user@example.com'], ['email' => FluentRule::string()->required()->email()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['email' => 'not-an-email'], ['email' => FluentRule::string()->required()->email()]);
    expect($v->passes())->toBeFalse();
});

it('compiles email on string rule', function (): void {
    expect(FluentRule::string()->email()->compiledRules())->toBe('string|email')
        ->and(FluentRule::string()->email('rfc')->compiledRules())->toBe('string|email:rfc')
        ->and(FluentRule::string()->email('rfc', 'spoof')->compiledRules())->toBe('string|email:rfc,spoof');
});
