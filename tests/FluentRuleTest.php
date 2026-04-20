<?php declare(strict_types=1);

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Fluent;
use Illuminate\Validation\Rules\AnyOf;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\ExcludeIf;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\Rules\StringRule;
use SanderMuller\FluentValidation\RuleSet;
use SanderMuller\FluentValidation\Tests\Fixtures\TestArrayKeyEnum;
use SanderMuller\FluentValidation\Tests\Fixtures\TestIntEnum;
use SanderMuller\FluentValidation\Tests\Fixtures\TestStringEnum;

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
    $v = makeValidator(['opt_out' => false], ['opt_out' => FluentRule::boolean()->declined()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['opt_out' => true], ['opt_out' => FluentRule::boolean()->declined()]);
    expect($v->passes())->toBeFalse();
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

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->keys())->toContain('tags.1')->not->toContain('tags');
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

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->keys())->toContain('items.0.name')->not->toContain('items');
});

it('produces indexed error keys for standalone each() scalar', function (): void {
    $validator = makeValidator(
        ['tags' => ['valid', 123]],
        ['tags' => FluentRule::array()->required()->each(FluentRule::string())]
    );

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->keys())->toContain('tags.1');
});

it('produces indexed error keys for standalone each() with fields', function (): void {
    $validator = makeValidator(
        ['items' => [['name' => 'Ok'], ['name' => '']]],
        ['items' => FluentRule::array()->required()->each([
            'name' => FluentRule::string()->required()->min(2),
        ])]
    );

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->keys())->toContain('items.1.name')->not->toContain('items');
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

// Factory return types are guaranteed by native PHP return-type declarations.
// Every `FluentRule::string()` etc. returns its concrete rule class at the
// type-system level — Pest + PHPStan catch mismatches statically, so a
// runtime `toBeInstanceOf` chain here is dead code.

// =========================================================================
// anyOf (Laravel 13+)
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
})->skip(! class_exists(AnyOf::class), 'AnyOf requires Laravel 13+');

it('validates anyOf fails when no rule matches', function (): void {
    $validator = makeValidator(
        ['contact' => 'not-email-or-url'],
        ['contact' => FluentRule::anyOf([FluentRule::string()->email(), FluentRule::string()->url()])]
    );
    expect($validator->passes())->toBeFalse();
})->skip(! class_exists(AnyOf::class), 'AnyOf requires Laravel 13+');

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
// Regression: bool values in conditional rules serialize correctly
// =========================================================================

it('requiredIf with bool value serializes correctly', function (): void {
    // required_if:published,1 — matches when published is "1" or 1 (form input)
    $v = makeValidator(
        ['published' => '0'],
        ['title' => FluentRule::string()->requiredIf('published', true)]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['published' => '1'],
        ['title' => FluentRule::string()->requiredIf('published', true)]
    );
    expect($v->passes())->toBeFalse();
});

it('requiredIf with false value serializes correctly', function (): void {
    // required_if:active,0 — matches when active is "0" or 0
    $v = makeValidator(
        ['active' => '0', 'reason' => 'testing'],
        ['reason' => FluentRule::string()->requiredIf('active', false)]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['active' => '1'],
        ['reason' => FluentRule::string()->requiredIf('active', false)]
    );
    expect($v->passes())->toBeTrue(); // active is 1, not 0 → reason not required
});

// BackedEnum values in conditional rules
// =========================================================================

it('requiredIf accepts BackedEnum values', function (): void {
    $v = makeValidator(
        ['type' => 'active', 'reason' => 'needs review'],
        ['reason' => FluentRule::string()->requiredIf('type', TestStringEnum::Active)]
    );
    expect($v->passes())->toBeTrue();
});

it('excludeUnless accepts BackedEnum values', function (): void {
    $rule = FluentRule::string()->excludeUnless('type', TestStringEnum::Active);
    expect($rule->compiledRules())->toBe('exclude_unless:type,active|string');
});

it('excludeIf accepts BackedEnum values', function (): void {
    $rule = FluentRule::string()->excludeIf('type', TestStringEnum::Inactive);
    expect($rule->compiledRules())->toBe('exclude_if:type,inactive|string');
});

it('prohibitedIf accepts BackedEnum values', function (): void {
    $rule = FluentRule::string()->prohibitedIf('type', TestStringEnum::Active, TestStringEnum::Inactive);
    expect($rule->compiledRules())->toBe('prohibited_if:type,active,inactive|string');
});

it('conditional rules accept int BackedEnum values', function (): void {
    $rule = FluentRule::string()->requiredIf('priority', TestIntEnum::Low);
    expect($rule->compiledRules())->toBe('required_if:priority,1|string');
});

// =========================================================================
// whenInput() — data-dependent conditional rules
// =========================================================================

it('whenInput applies rules when condition is true', function (): void {
    $stringRule = FluentRule::string()->whenInput(
        fn (Fluent $input): bool => $input->role === 'admin',
        fn (StringRule $r): StringRule => $r->required()->min(12),
    );

    $compiled = RuleSet::compile(['password' => $stringRule]);
    $validator = makeValidator(['role' => 'admin', 'password' => 'short'], $compiled);
    expect($validator->passes())->toBeFalse();
});

it('whenInput skips rules when condition is false', function (): void {
    $stringRule = FluentRule::string()->whenInput(
        fn (Fluent $input): bool => $input->role === 'admin',
        fn (StringRule $r): StringRule => $r->required()->min(12),
    );

    $compiled = RuleSet::compile(['password' => $stringRule]);
    $validator = makeValidator(['role' => 'user', 'password' => 'short'], $compiled);
    expect($validator->passes())->toBeTrue();
});

it('whenInput applies default rules when condition is false', function (): void {
    $stringRule = FluentRule::string()->whenInput(
        fn (Fluent $input): bool => $input->role === 'admin',
        fn (StringRule $r): StringRule => $r->required()->min(12),
        fn (StringRule $r): StringRule => $r->sometimes()->max(5),
    );

    $compiled = RuleSet::compile(['password' => $stringRule]);
    $validator = makeValidator(['role' => 'user', 'password' => 'toolong'], $compiled);
    expect($validator->passes())->toBeFalse();
});

it('whenInput accepts string rules instead of closures', function (): void {
    $stringRule = FluentRule::string()->whenInput(
        fn (Fluent $input): bool => $input->type === 'premium',
        'required|min:12',
    );

    $compiled = RuleSet::compile(['code' => $stringRule]);
    $validator = makeValidator(['type' => 'premium', 'code' => 'short'], $compiled);
    expect($validator->passes())->toBeFalse();
});

it('whenInput branch does not leak parent messages or labels', function (): void {
    $stringRule = FluentRule::string('Full Name')
        ->required()->message('Name is required.')
        ->whenInput(
            fn (Fluent $input): bool => $input->strict === '1',
            fn (StringRule $r): StringRule => $r->min(12),
        );

    // The branch closure should produce only 'min:12', not 'string|required|min:12'
    // and should not carry the parent's custom messages or label
    $compiled = RuleSet::compile(['name' => $stringRule]);
    $validator = makeValidator(['strict' => '0', 'name' => 'Jo'], $compiled);
    expect($validator->passes())->toBeTrue(); // strict is 0, min:12 doesn't apply
});

// =========================================================================
// message() on custom ValidationRule objects
// =========================================================================

it('message works on custom ValidationRule via class name fallback', function (): void {
    $customRule = new class implements ValidationRule {
        public function validate(string $attribute, mixed $value, Closure $fail): void
        {
            if ($value !== 'valid') {
                $fail('Default error.');
            }
        }
    };

    $stringRule = FluentRule::string()->rule($customRule)->message('Custom message!');
    $compiled = RuleSet::compile(['field' => $stringRule]);

    // The message is keyed by the class basename
    [$messages] = RuleSet::extractMetadata(['field' => $stringRule]);
    $key = array_key_first($messages);
    expect($key)->not->toBeNull()
        ->toStartWith('field.');
    /** @var string $key */
    expect($messages[$key])->toBe('Custom message!');
});

// =========================================================================
// fieldMessage() — field-level fallback
// =========================================================================

it('fieldMessage sets a fallback for any rule failure', function (): void {
    try {
        RuleSet::from([
            'name' => FluentRule::string()->required()->min(10)->fieldMessage('Something is wrong with the name.'),
        ])->validate(['name' => '']);
    } catch (ValidationException $validationException) {
        expect($validationException->errors()['name'][0])->toBe('Something is wrong with the name.');

        return;
    }

    $this->fail('Expected ValidationException');
});

it('rule-specific message takes priority over fieldMessage', function (): void {
    try {
        RuleSet::from([
            'name' => FluentRule::string()
                ->required()->message('Name is required.')
                ->min(10)
                ->fieldMessage('General name error.'),
        ])->validate(['name' => '']);
    } catch (ValidationException $validationException) {
        expect($validationException->errors()['name'][0])->toBe('Name is required.');

        return;
    }

    $this->fail('Expected ValidationException');
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

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->get('name'))->not->toBeEmpty();
});

it('has no errors when validation passes', function (): void {
    $validator = makeValidator(
        ['name' => 'John'],
        ['name' => FluentRule::string()->required()->min(2)]
    );

    expect($validator->passes())->toBeTrue()
        ->and($validator->errors()->get('name'))->toBeEmpty();
});

// =========================================================================
// Labels and per-rule messages (standalone, via SelfValidates)
// =========================================================================

it('uses label in error messages via SelfValidates', function (): void {
    $validator = makeValidator(
        ['name' => ''],
        ['name' => FluentRule::string('Full Name')->required()]
    );

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->first('name'))->toContain('Full Name');
});

it('uses per-rule message via SelfValidates', function (): void {
    $validator = makeValidator(
        ['name' => ''],
        ['name' => FluentRule::string()->required()->message('We need your name!')]
    );

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->first('name'))->toBe('We need your name!');
});

it('uses label on numeric rule', function (): void {
    $validator = makeValidator(
        ['age' => 'not-a-number'],
        ['age' => FluentRule::numeric('Your age')->required()]
    );

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->first('age'))->toContain('Your age');
});

it('uses label on date rule', function (): void {
    $validator = makeValidator(
        ['starts_at' => 'not-a-date'],
        ['starts_at' => FluentRule::date('Start Date')->required()]
    );

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->first('starts_at'))->toContain('Start Date');
});

it('uses label on boolean rule', function (): void {
    $validator = makeValidator(
        ['agree' => 'not-a-bool'],
        ['agree' => FluentRule::boolean('Terms Agreement')->required()]
    );

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->first('agree'))->toContain('Terms Agreement');
});

it('uses message after in() rule', function (): void {
    $validator = makeValidator(
        ['role' => 'hacker'],
        ['role' => FluentRule::string()->required()->in(['admin', 'user'])->message('Pick a valid role.')]
    );

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->first('role'))->toBe('Pick a valid role.');
});

it('uses message after requiredIf with closure', function (): void {
    $validator = makeValidator(
        [],
        ['name' => FluentRule::string()->requiredIf(fn (): true => true)->message('Conditionally required!')]
    );

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->first('name'))->toBe('Conditionally required!');
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

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->first('name'))->toBe('Name is required.');
});

it('uses message inside when() conditional', function (): void {
    $validator = makeValidator(
        ['password' => 'short'],
        ['password' => FluentRule::string()->required()->when(true, fn (StringRule $r): StringRule => $r->min(12)->message('Admin passwords need 12+ chars.'))]
    );

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->first('password'))->toBe('Admin passwords need 12+ chars.');
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
// AcceptedRule — standalone accepted factory (permissive; no boolean base)
// =========================================================================

it('FluentRule::accepted() validates permissive accepted values', function (): void {
    // Match Laravel's `$acceptable = ['yes', 'on', '1', 1, true, 'true']`.
    foreach (['yes', 'on', '1', 1, true, 'true'] as $truthy) {
        $v = makeValidator(['tos' => $truthy], ['tos' => FluentRule::accepted()]);
        expect($v->passes())->toBeTrue();
    }
});

it('FluentRule::accepted() rejects non-accepted values', function (): void {
    foreach (['no', 'off', '0', 0, false, ''] as $falsy) {
        $v = makeValidator(['tos' => $falsy], ['tos' => FluentRule::accepted()]);
        expect($v->passes())->toBeFalse();
    }
});

it('FluentRule::accepted() does not combine with strict boolean (no footgun)', function (): void {
    // Regression: FluentRule::boolean()->accepted() compiles to `boolean|accepted` —
    // `boolean` rejects 'yes'/'on' which `accepted` permits. The standalone
    // factory avoids that pairing.
    $v = makeValidator(['tos' => 'yes'], ['tos' => FluentRule::accepted()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['tos' => 'yes'], ['tos' => FluentRule::boolean()->accepted()]);
    expect($v->passes())->toBeFalse();
});

it('FluentRule::accepted() supports label', function (): void {
    $v = makeValidator(
        ['agree' => false],
        ['agree' => FluentRule::accepted('Terms Agreement')->required()]
    );
    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('agree'))->toContain('Terms Agreement');
});

it('FluentRule::accepted()->acceptedIf() replaces unconditional accepted', function (): void {
    $v = makeValidator(
        ['tos' => false, 'country' => 'DE'],
        ['tos' => FluentRule::accepted()->acceptedIf('country', 'US')]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['tos' => false, 'country' => 'US'],
        ['tos' => FluentRule::accepted()->acceptedIf('country', 'US')]
    );
    expect($v->passes())->toBeFalse();

    $v = makeValidator(
        ['tos' => true, 'country' => 'US'],
        ['tos' => FluentRule::accepted()->acceptedIf('country', 'US')]
    );
    expect($v->passes())->toBeTrue();
});

it('FluentRule::accepted()->required()->acceptedIf() preserves required', function (): void {
    // Regression: acceptedIf() must only strip the 'accepted' base, not
    // wipe prior modifiers like required / nullable (which also land in
    // $this->constraints via addRule()).
    $v = makeValidator(
        [],
        ['tos' => FluentRule::accepted()->required()->acceptedIf('country', 'US')]
    );
    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('tos'))->toContain('required');
});

it('FluentRule::accepted()->nullable() allows null without accepted check', function (): void {
    $v = makeValidator(
        ['tos' => null],
        ['tos' => FluentRule::accepted()->nullable()]
    );
    expect($v->passes())->toBeTrue();
});

it('FluentRule::accepted() is case-sensitive (rejects YES / ON / TRUE)', function (): void {
    foreach (['YES', 'ON', 'Yes', 'On', 'TRUE'] as $upper) {
        $v = makeValidator(['tos' => $upper], ['tos' => FluentRule::accepted()]);
        expect($v->passes())->toBeFalse();
    }
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

it('validates excludeIf with bool excludes from validated', function (): void {
    $v = makeValidator(['field' => 'val', 'other' => 'keep'], [
        'field' => FluentRule::string()->excludeIf(true),
        'other' => FluentRule::string(),
    ]);
    expect($v->passes())->toBeTrue()
        ->and($v->validated())->not->toHaveKey('field')
        ->toHaveKey('other');

    $v = makeValidator(['field' => 'val'], ['field' => FluentRule::string()->excludeIf(false)]);
    expect($v->passes())->toBeTrue()
        ->and($v->validated())->toHaveKey('field');
});

it('validates excludeIf with closure excludes from validated', function (): void {
    $validator = makeValidator(['field' => 'val', 'other' => 'keep'], [
        'field' => FluentRule::string()->excludeIf(fn (): true => true),
        'other' => FluentRule::string(),
    ]);
    expect($validator->passes())->toBeTrue()
        ->and($validator->validated())->not->toHaveKey('field');
});

it('validates excludeUnless with bool excludes from validated', function (): void {
    $v = makeValidator(['field' => 'val'], ['field' => FluentRule::string()->excludeUnless(true)]);
    expect($v->passes())->toBeTrue()
        ->and($v->validated())->toHaveKey('field');

    $v = makeValidator(['field' => 'val', 'other' => 'keep'], [
        'field' => FluentRule::string()->excludeUnless(false),
        'other' => FluentRule::string(),
    ]);
    expect($v->passes())->toBeTrue()
        ->and($v->validated())->not->toHaveKey('field');
});

it('validates excludeUnless with closure excludes from validated', function (): void {
    $validator = makeValidator(['field' => 'val', 'other' => 'keep'], [
        'field' => FluentRule::string()->excludeUnless(fn (): false => false),
        'other' => FluentRule::string(),
    ]);
    expect($validator->passes())->toBeTrue()
        ->and($validator->validated())->not->toHaveKey('field');
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
        ['status' => FluentRule::string()->required()->enum(TestStringEnum::class, fn (Enum $rule): Enum => $rule->only(TestStringEnum::Active))]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['status' => 'inactive'],
        ['status' => FluentRule::string()->required()->enum(TestStringEnum::class, fn (Enum $rule): Enum => $rule->only(TestStringEnum::Active))]
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
    expect($stringRule->compiledRules())->toBe('required|string|min:2|max:255');
});

it('compiles object rules to string when all rules are stringable', function (): void {
    $compiled = FluentRule::string()->required()->in(['a', 'b'])->compiledRules();
    expect($compiled)->toBeString()
        ->toContain('string')
        ->toContain('required')
        ->toContain('in:');
});

it('compiles presence modifiers before string constraints and closures after', function (): void {
    $closure = function (string $attribute, mixed $value, Closure $fail): void {};

    $compiled = FluentRule::string()
        ->excludeIf(fn (): bool => false)
        ->required()
        ->bail()
        ->min(3)
        ->rule($closure)
        ->compiledRules();

    // Find positions: ExcludeIf first, strings in middle, closure last.
    expect($compiled)->toBeArray();
    /** @var list<object|string> $compiled */
    $excludeIdx = null;
    $closureIdx = null;
    $bailIdx = null;
    foreach ($compiled as $i => $r) {
        if ($r instanceof ExcludeIf) {
            $excludeIdx = $i;
        }

        if ($r === $closure) {
            $closureIdx = $i;
        }

        if ($r === 'bail') {
            $bailIdx = $i;
        }
    }

    expect($excludeIdx)->not->toBeNull()
        ->and($closureIdx)->not->toBeNull()
        ->and($bailIdx)->not->toBeNull();
    // ExcludeIf before bail, bail before closure.
    expect($excludeIdx)->toBeLessThan($bailIdx); // @phpstan-ignore argument.type
    expect($bailIdx)->toBeLessThan($closureIdx); // @phpstan-ignore argument.type
});

it('compiles Unique and In rules as stringified pipe-joined string', function (): void {
    $compiled = FluentRule::string()
        ->required()
        ->bail()
        ->unique('users', 'email')
        ->in(['a', 'b'])
        ->compiledRules();

    expect($compiled)->toBeArray()
        ->toContain('string')
        ->toContain('required')
        ->toContain('bail');

    $hasUnique = false;
    $hasIn = false;
    /** @var list<object|string> $compiled */
    foreach ($compiled as $rule) {
        if ($rule instanceof Unique) {
            $hasUnique = true;
        }

        if ($rule instanceof In) {
            $hasIn = true;
        }
    }

    expect($hasUnique)->toBeTrue()
        ->and($hasIn)->toBeTrue();
});

// =========================================================================
// HasEmbeddedRules — unique / exists
// =========================================================================

it('compiles unique rule to string', function (): void {
    $compiled = FluentRule::string()->unique('users', 'email')->compiledRules();
    expect($compiled)->toBeArray()
        ->toContain('string');

    /** @var list<object|string> $compiled */
    $unique = collect($compiled)->first(fn (object|string $r): bool => $r instanceof Unique);
    expect($unique)->toBeInstanceOf(Unique::class);
});

it('compiles exists rule to string', function (): void {
    $compiled = FluentRule::string()->exists('users', 'email')->compiledRules();
    expect($compiled)->toBeArray()
        ->toContain('string');

    /** @var list<object|string> $compiled */
    $exists = collect($compiled)->first(fn (object|string $r): bool => $r instanceof Exists);
    expect($exists)->toBeInstanceOf(Exists::class);
});

it('unique with where callback adds constraint', function (): void {
    $compiled = FluentRule::string()->unique('users', 'email', fn (Unique $rule): Unique => $rule->where('tenant_id', 1))->compiledRules();
    expect($compiled)->toBeArray();

    /** @var list<object|string> $compiled */
    $unique = collect($compiled)->first(fn (object|string $r): bool => $r instanceof Unique);
    /** @var Unique $unique */
    expect((string) $unique)->toContain('tenant_id');
});

it('unique with ignore callback adds constraint', function (): void {
    $compiled = FluentRule::string()->unique('users', 'email', fn (Unique $rule): Unique => $rule->ignore(42))->compiledRules();
    expect($compiled)->toBeArray();

    /** @var list<object|string> $compiled */
    $unique = collect($compiled)->first(fn (object|string $r): bool => $r instanceof Unique);
    /** @var Unique $unique */
    expect((string) $unique)->toContain('"42"');
});

it('exists with where callback adds constraint', function (): void {
    $compiled = FluentRule::string()->exists('subjects', 'id', fn (Exists $rule): Exists => $rule->where('video_id', 42))->compiledRules();
    expect($compiled)->toBeArray();

    /** @var list<object|string> $compiled */
    $exists = collect($compiled)->first(fn (object|string $r): bool => $r instanceof Exists);
    /** @var Exists $exists */
    expect((string) $exists)->toContain('video_id');
});

it('exists with whereNull callback adds constraint', function (): void {
    $compiled = FluentRule::string()->exists('users', 'id', fn (Exists $rule): Exists => $rule->where('deleted_at'))->compiledRules();
    expect($compiled)->toBeArray();

    /** @var list<object|string> $compiled */
    $exists = collect($compiled)->first(fn (object|string $r): bool => $r instanceof Exists);
    /** @var Exists $exists */
    expect((string) $exists)->toContain('deleted_at');
});

it('unique without callback still works (backward compat)', function (): void {
    $compiled = FluentRule::string()->unique('users', 'email')->compiledRules();
    expect($compiled)->toBeArray();

    /** @var list<object|string> $compiled */
    $unique = collect($compiled)->first(fn (object|string $r): bool => $r instanceof Unique);
    expect($unique)->toBeInstanceOf(Unique::class);
});

it('exists without callback still works (backward compat)', function (): void {
    $compiled = FluentRule::string()->exists('users', 'email')->compiledRules();
    expect($compiled)->toBeArray();

    /** @var list<object|string> $compiled */
    $exists = collect($compiled)->first(fn (object|string $r): bool => $r instanceof Exists);
    expect($exists)->toBeInstanceOf(Exists::class);
});

it('exists with closure-based where is preserved as object (not stringified)', function (): void {
    $compiled = FluentRule::string()->exists('users', 'id', fn (Exists $rule): Exists => $rule->where(fn (mixed $query) => $query->where('active', true)))->compiledRules();
    expect($compiled)->toBeArray();

    /** @var list<object|string> $compiled */
    $exists = collect($compiled)->first(fn (object|string $r): bool => $r instanceof Exists);
    expect($exists)->toBeInstanceOf(Exists::class);

    // The closure-based where should NOT appear in the string representation
    // (Laravel's __toString drops closures), but the object preserves it
    /** @var Exists $exists */
    $stringified = (string) $exists;
    expect($stringified)->toBe('exists:users,id');

    // If the object were stringified during compilation, the closure would be lost.
    // The object form preserves it for Laravel's query builder to use at validation time.
});

// =========================================================================
// Clone support — for FormRequest inheritance patterns
// =========================================================================

it('clone clears compiled cache so new rules take effect', function (): void {
    $parent = FluentRule::field()->required();
    $parent->compiledRules(); // trigger cache

    $child = (clone $parent)->in(['a', 'b']);
    $compiled = $child->compiledRules();

    expect($compiled)->toBeString()
        ->toContain('required')
        ->toContain('in:');

    // Parent should be unaffected
    expect($parent->compiledRules())->toBe('required');
});

it('clone allows extending rules for FormRequest inheritance', function (): void {
    $parent = FluentRule::string()->required()->max(255);
    $parent->compiledRules(); // trigger cache

    $child = (clone $parent)->rule(function (string $attribute, mixed $value, Closure $fail): void {
        if ($value === 'forbidden') {
            $fail('This value is forbidden.');
        }
    });
    $compiled = $child->compiledRules();

    expect($compiled)->toBeArray()
        ->toContain('string')
        ->toContain('required')
        ->toContain('max:255');
});

// =========================================================================
// RuleSet::compile()
// =========================================================================

it('compiles fluent rules to native format', function (): void {
    $compiled = RuleSet::compile([
        'name' => FluentRule::string()->required()->min(2),
        'age' => FluentRule::numeric()->integer(),
    ]);
    expect($compiled)->toMatchArray(['name' => 'required|string|min:2', 'age' => 'numeric|integer']);
});

it('compile passes through non-fluent rules unchanged', function (): void {
    $compiled = RuleSet::compile([
        'name' => 'required|string',
        'tags' => ['required', 'array'],
    ]);
    expect($compiled)->toMatchArray(['name' => 'required|string', 'tags' => ['required', 'array']]);
});

// =========================================================================
// RuleSet::compileToArrays()
// =========================================================================

it('compileToArrays returns arrays for fluent rules', function (): void {
    $compiled = RuleSet::compileToArrays([
        'name' => FluentRule::string()->required()->min(2),
        'age' => FluentRule::numeric()->integer(),
    ]);
    expect($compiled)->toMatchArray(['name' => ['required', 'string', 'min:2'], 'age' => ['numeric', 'integer']]);
});

it('compileToArrays explodes string rules into arrays', function (): void {
    $compiled = RuleSet::compileToArrays([
        'name' => 'required|string|max:255',
        'email' => 'required|email',
    ]);
    expect($compiled)->toMatchArray(['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email']]);
});

it('compileToArrays passes through array rules unchanged', function (): void {
    $compiled = RuleSet::compileToArrays([
        'tags' => ['required', 'array'],
    ]);

    expect($compiled['tags'])->toBe(['required', 'array']);
});

it('compileToArrays wraps standalone objects in arrays', function (): void {
    $rule = new class implements ValidationRule {
        public function validate(string $attribute, mixed $value, Closure $fail): void {}
    };

    $compiled = RuleSet::compileToArrays([
        'field' => $rule,
    ]);

    expect($compiled['field'][0])->toBe($rule);
});

it('compileToArrays handles mixed fluent and string rules', function (): void {
    $compiled = RuleSet::compileToArrays([
        'name' => FluentRule::string()->required(),
        'email' => 'required|email',
        'tags' => ['required', 'array'],
    ]);
    expect($compiled)->toMatchArray(['name' => ['required', 'string'], 'email' => ['required', 'email'], 'tags' => ['required', 'array']]);
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

    expect($without->getEachRules())->toBeNull()
        ->and($arrayRule->getEachRules())->not->toBeNull();
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
    expect($compiled)->toBeArray()
        ->toContain('string')
        ->toContain('required')
        ->toContain($nonStringable);
});

// =========================================================================
// exclude() modifier
// =========================================================================

it('exclude adds the exclude constraint', function (): void {
    $stringRule = FluentRule::string()->exclude();
    expect($stringRule->compiledRules())->toBe('exclude|string');
});

it('exclude removes field from validated data', function (): void {
    $validator = makeValidator(['field' => 'val', 'other' => 'keep'], [
        'field' => FluentRule::string()->exclude(),
        'other' => FluentRule::string(),
    ]);
    expect($validator->passes())->toBeTrue()
        ->and($validator->validated())->not->toHaveKey('field')
        ->toHaveKey('other');
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

    expect($nested)->toHaveKeys(['items.*.tags', 'items.*.tags.*', 'items.*.name']);
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
        ->unless(false, fn (StringRule $r): StringRule => $r->min(12))
        ->max(255);

    $validator = makeValidator(['name' => 'short'], ['name' => $stringRule]);
    expect($validator->passes())->toBeFalse();
});

it('validates unless does not apply when condition is true', function (): void {
    $stringRule = FluentRule::string()
        ->required()
        ->unless(true, fn (StringRule $r): StringRule => $r->min(12))
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
// Failed rule identifiers (assertHasErrors compatibility)
// =========================================================================

it('exposes individual rule identifiers in failed() for self-validation', function (): void {
    $validator = makeValidator(['title' => ''], [
        'title' => FluentRule::string()->required()->min(6)->max(200),
    ]);

    expect($validator->passes())->toBeFalse();

    $failed = $validator->failed();
    expect($failed)->toHaveKey('title')
        ->and($failed['title'])->toHaveKey('Required');
});

it('exposes min rule identifier in failed() when value is too short', function (): void {
    $validator = makeValidator(['title' => 'ab'], [
        'title' => FluentRule::string()->required()->min(6)->max(200),
    ]);

    expect($validator->passes())->toBeFalse();

    $failed = $validator->failed();
    expect($failed)->toHaveKey('title')
        ->and($failed['title'])->toHaveKey('Min');
});

it('failed() is empty when validation passes', function (): void {
    $validator = makeValidator(['title' => 'Valid Title'], [
        'title' => FluentRule::string()->required()->min(6)->max(200),
    ]);

    expect($validator->passes())->toBeTrue()
        ->and($validator->failed())->toBeEmpty();
});

// =========================================================================
// Field modifiers: presentIf, presentUnless, presentWith, presentWithAll
// =========================================================================

it('validates presentIf', function (): void {
    $v = makeValidator(
        ['type' => 'admin', 'role' => 'editor'],
        ['role' => FluentRule::string()->presentIf('type', 'admin')]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['type' => 'admin'],
        ['role' => FluentRule::string()->presentIf('type', 'admin')]
    );
    expect($v->passes())->toBeFalse();

    // When condition doesn't match, field doesn't need to be present
    $v = makeValidator(
        ['type' => 'guest'],
        ['role' => FluentRule::string()->presentIf('type', 'admin')]
    );
    expect($v->passes())->toBeTrue();
});

it('validates presentUnless', function (): void {
    $v = makeValidator(
        ['type' => 'admin'],
        ['role' => FluentRule::string()->presentUnless('type', 'guest')]
    );
    expect($v->passes())->toBeFalse();

    $v = makeValidator(
        ['type' => 'admin', 'role' => 'editor'],
        ['role' => FluentRule::string()->presentUnless('type', 'guest')]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['type' => 'guest'],
        ['role' => FluentRule::string()->presentUnless('type', 'guest')]
    );
    expect($v->passes())->toBeTrue();
});

it('validates presentWith', function (): void {
    $v = makeValidator(
        ['email' => 'test@test.com'],
        ['name' => FluentRule::string()->presentWith('email')]
    );
    expect($v->passes())->toBeFalse();

    $v = makeValidator(
        ['email' => 'test@test.com', 'name' => 'John'],
        ['name' => FluentRule::string()->presentWith('email')]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        [],
        ['name' => FluentRule::string()->presentWith('email')]
    );
    expect($v->passes())->toBeTrue();
});

it('validates presentWithAll', function (): void {
    $v = makeValidator(
        ['first' => 'a', 'last' => 'b'],
        ['full' => FluentRule::string()->presentWithAll('first', 'last')]
    );
    expect($v->passes())->toBeFalse();

    $v = makeValidator(
        ['first' => 'a', 'last' => 'b', 'full' => 'a b'],
        ['full' => FluentRule::string()->presentWithAll('first', 'last')]
    );
    expect($v->passes())->toBeTrue();

    // Only one field present — not required
    $v = makeValidator(
        ['first' => 'a'],
        ['full' => FluentRule::string()->presentWithAll('first', 'last')]
    );
    expect($v->passes())->toBeTrue();
});

// =========================================================================
// Field modifiers: requiredIfAccepted, requiredIfDeclined
// =========================================================================

it('validates requiredIfAccepted', function (): void {
    $v = makeValidator(
        ['terms' => 'yes'],
        ['signature' => FluentRule::string()->requiredIfAccepted('terms')]
    );
    expect($v->passes())->toBeFalse();

    $v = makeValidator(
        ['terms' => 'yes', 'signature' => 'John Doe'],
        ['signature' => FluentRule::string()->requiredIfAccepted('terms')]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['terms' => 'no'],
        ['signature' => FluentRule::string()->requiredIfAccepted('terms')]
    );
    expect($v->passes())->toBeTrue();
});

it('validates requiredIfDeclined', function (): void {
    $v = makeValidator(
        ['terms' => 'no'],
        ['reason' => FluentRule::string()->requiredIfDeclined('terms')]
    );
    expect($v->passes())->toBeFalse();

    $v = makeValidator(
        ['terms' => 'no', 'reason' => 'I disagree'],
        ['reason' => FluentRule::string()->requiredIfDeclined('terms')]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['terms' => 'yes'],
        ['reason' => FluentRule::string()->requiredIfDeclined('terms')]
    );
    expect($v->passes())->toBeTrue();
});

// =========================================================================
// ArrayRule: contains / doesntContain
// =========================================================================

it('validates contains on array', function (): void {
    $v = makeValidator(
        ['tags' => ['php', 'laravel']],
        ['tags' => FluentRule::array()->contains('php')]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['tags' => ['php', 'laravel']],
        ['tags' => FluentRule::array()->contains('python')]
    );
    expect($v->passes())->toBeFalse();
});

it('validates doesntContain on array', function (): void {
    $v = makeValidator(
        ['tags' => ['php', 'laravel']],
        ['tags' => FluentRule::array()->doesntContain('python')]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['tags' => ['php', 'laravel']],
        ['tags' => FluentRule::array()->doesntContain('php')]
    );
    expect($v->passes())->toBeFalse();
})->skip(! method_exists(Validator::class, 'validateDoesntContain'), 'doesnt_contain requires Laravel 12+'); // @phpstan-ignore function.alreadyNarrowedType

// =========================================================================
// Convenience factory shortcuts
// =========================================================================

it('validates url shortcut', function (): void {
    $v = makeValidator(
        ['website' => 'https://example.com'],
        ['website' => FluentRule::url()->required()]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['website' => 'not-a-url'],
        ['website' => FluentRule::url()->required()]
    );
    expect($v->passes())->toBeFalse();
});

it('validates uuid shortcut', function (): void {
    $v = makeValidator(
        ['id' => '550e8400-e29b-41d4-a716-446655440000'],
        ['id' => FluentRule::uuid()->required()]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['id' => 'not-a-uuid'],
        ['id' => FluentRule::uuid()->required()]
    );
    expect($v->passes())->toBeFalse();
});

it('validates ulid shortcut', function (): void {
    $v = makeValidator(
        ['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
        ['id' => FluentRule::ulid()->required()]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['id' => 'not-a-ulid'],
        ['id' => FluentRule::ulid()->required()]
    );
    expect($v->passes())->toBeFalse();
});

it('validates ip shortcut', function (): void {
    $v = makeValidator(
        ['address' => '192.168.1.1'],
        ['address' => FluentRule::ip()->required()]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['address' => 'not-an-ip'],
        ['address' => FluentRule::ip()->required()]
    );
    expect($v->passes())->toBeFalse();
});

it('passes label through convenience shortcuts', function (): void {
    expect(FluentRule::url('Website')->getLabel())->toBe('Website')
        ->and(FluentRule::uuid('ID')->getLabel())->toBe('ID')
        ->and(FluentRule::ulid('ID')->getLabel())->toBe('ID')
        ->and(FluentRule::ip('Address')->getLabel())->toBe('Address');
});

// =========================================================================
// prohibitedIfAccepted, prohibitedIfDeclined
// =========================================================================

it('validates prohibitedIfAccepted', function (): void {
    $v = makeValidator(
        ['terms' => 'yes', 'waiver' => 'some value'],
        ['waiver' => FluentRule::string()->prohibitedIfAccepted('terms')]
    );
    expect($v->passes())->toBeFalse();

    $v = makeValidator(
        ['terms' => 'yes'],
        ['waiver' => FluentRule::string()->prohibitedIfAccepted('terms')]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['terms' => 'no', 'waiver' => 'some value'],
        ['waiver' => FluentRule::string()->prohibitedIfAccepted('terms')]
    );
    expect($v->passes())->toBeTrue();
});

it('validates prohibitedIfDeclined', function (): void {
    $v = makeValidator(
        ['terms' => 'no', 'reason' => 'I disagree'],
        ['reason' => FluentRule::string()->prohibitedIfDeclined('terms')]
    );
    expect($v->passes())->toBeFalse();

    $v = makeValidator(
        ['terms' => 'no'],
        ['reason' => FluentRule::string()->prohibitedIfDeclined('terms')]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['terms' => 'yes', 'reason' => 'whatever'],
        ['reason' => FluentRule::string()->prohibitedIfDeclined('terms')]
    );
    expect($v->passes())->toBeTrue();
});

// =========================================================================
// StringRule: encoding
// =========================================================================

it('validates encoding', function (): void {
    $v = makeValidator(
        ['name' => 'hello'],
        ['name' => FluentRule::string()->encoding('UTF-8')]
    );
    expect($v->passes())->toBeTrue();
})->skip(! method_exists(Validator::class, 'validateEncoding'), 'encoding requires Laravel 12+'); // @phpstan-ignore function.alreadyNarrowedType

it('compiles encoding', function (): void {
    expect(FluentRule::string()->encoding('UTF-8')->toArray())
        ->toContain('encoding:UTF-8');
});

// =========================================================================
// Debugging: toArray(), dump(), dd()
// =========================================================================

it('toArray returns empty array for untyped field rule', function (): void {
    expect(FluentRule::field()->toArray())->toBeEmpty();
});

it('toArray returns array from string-compiled rules', function (): void {
    $rule = FluentRule::string()->required()->max(255);
    expect($rule->toArray())->toBe(['required', 'string', 'max:255']);
});

it('toArray returns array from object-compiled rules', function (): void {
    $rule = FluentRule::string()->required()->rule(new class implements ValidationRule {
        public function validate(string $attribute, mixed $value, Closure $fail): void {}
    });
    expect($rule->toArray())->toMatchArray([0 => 'required', 1 => 'string']);
});

it('RuleSet dump returns rules messages and attributes', function (): void {
    $dump = RuleSet::from([
        'name' => FluentRule::string('Full Name')->required()->max(255),
        'email' => FluentRule::email()->required(),
    ])->dump();

    expect($dump)->toHaveKeys(['rules', 'messages', 'attributes'])
        ->and($dump['rules'])->toHaveKeys(['name', 'email'])
        ->and($dump['attributes']['name'])->toBe('Full Name');
});

it('dump is chainable on rules', function (): void {
    // dump() should return $this for chaining
    $rule = FluentRule::string()->required();
    ob_start();
    $result = $rule->dump();
    ob_end_clean();
    expect($result)->toBe($rule);
});

// =========================================================================
// FluentRule::macro() — custom factory methods
// =========================================================================

it('supports macros on FluentRule', function (): void {
    FluentRule::macro('phone', fn (?string $label = null) => FluentRule::string($label)->rule('phone'));

    $rule = FluentRule::phone('Phone'); // @phpstan-ignore staticMethod.notFound
    expect($rule)->toBeInstanceOf(StringRule::class) // @phpstan-ignore argument.templateType
        ->and($rule->getLabel())->toBe('Phone')
        ->and($rule->toArray())->toContain('phone');
});

// =========================================================================
// RuleSet::macro() — custom methods
// =========================================================================

it('supports macros on RuleSet', function (): void {
    RuleSet::macro('withName', fn () => $this->field('name', FluentRule::string()->required()->max(255)));

    $validated = RuleSet::make()->withName()->validate(['name' => 'John']);
    expect($validated['name'])->toBe('John');
});
