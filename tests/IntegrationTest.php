<?php declare(strict_types=1);

use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Rule as LaravelRule;
use Illuminate\Validation\Validator;
use SanderMuller\FluentValidation\Rule;
use SanderMuller\FluentValidation\Rules\StringRule;

// =========================================================================
// Mixed with other rules in an array
// =========================================================================

it('works alongside string rules in an array', function (): void {
    $validator = makeValidator(
        ['name' => 'John'],
        ['name' => ['sometimes', Rule::string()->min(2)->max(255)]]
    );

    expect($validator->passes())->toBeTrue();
});

it('works alongside string rules in an array when absent', function (): void {
    $validator = makeValidator(
        [],
        ['name' => ['sometimes', Rule::string()->min(2)->max(255)]]
    );

    // 'sometimes' is a native rule, our rule is an additional custom rule
    // The outer validator sees 'sometimes' and skips the field entirely
    expect($validator->passes())->toBeTrue();
});

it('works alongside Laravel Rule objects', function (): void {
    $validator = makeValidator(
        ['status' => 'active'],
        ['status' => [Rule::string()->required(), LaravelRule::in(['active', 'inactive'])]]
    );

    expect($validator->passes())->toBeTrue();
});

it('works alongside Laravel Rule objects when value is invalid', function (): void {
    $validator = makeValidator(
        ['status' => 'deleted'],
        ['status' => [Rule::string()->required(), LaravelRule::in(['active', 'inactive'])]]
    );

    expect($validator->passes())->toBeFalse();
});

// =========================================================================
// Rule::forEach integration
// =========================================================================

it('works inside Rule::forEach', function (): void {
    $validator = makeValidator(
        ['items' => ['hello', 'world']],
        ['items.*' => LaravelRule::forEach(fn (): StringRule => Rule::string()->required()->max(255))]
    );

    expect($validator->passes())->toBeTrue();
});

it('fails inside Rule::forEach for invalid items', function (): void {
    $validator = makeValidator(
        ['items' => ['hello', 123]],
        ['items.*' => LaravelRule::forEach(fn (): StringRule => Rule::string()->required()->max(255))]
    );

    expect($validator->passes())->toBeFalse();
});

// =========================================================================
// Wildcard attributes
// =========================================================================

it('works with wildcard attributes directly', function (): void {
    $validator = makeValidator(
        ['items' => ['hello', 'world']],
        ['items' => Rule::array()->required()->min(1), 'items.*' => Rule::string()->max(10)]
    );

    expect($validator->passes())->toBeTrue();
});

it('fails with wildcard attributes for invalid items', function (): void {
    $validator = makeValidator(
        ['items' => ['hello', 123]],
        ['items' => Rule::array()->required(), 'items.*' => Rule::string()]
    );

    expect($validator->passes())->toBeFalse();
});

// =========================================================================
// Custom error messages
// =========================================================================

it('uses custom error messages from the validator', function (): void {
    $v = new Validator(
        new Translator(new ArrayLoader(), 'en'),
        ['name' => ''],
        ['name' => Rule::string()->required()],
        ['name.required' => 'Please enter your name.']
    );

    expect($v->passes())->toBeFalse();
    expect($v->errors()->first('name'))->toBe('Please enter your name.');
});

it('uses custom attribute names', function (): void {
    $v = new Validator(
        new Translator(new ArrayLoader(), 'en'),
        ['email_address' => 'not-enough'],
        ['email_address' => Rule::string()->required()->min(20)],
        [],
        ['email_address' => 'email address']
    );

    expect($v->passes())->toBeFalse();
    $error = $v->errors()->first('email_address');
    expect($error)->toContain('email address');
});

// =========================================================================
// validated() data extraction
// =========================================================================

it('returns validated data correctly', function (): void {
    $validator = makeValidator(
        ['name' => 'John', 'age' => 25, 'extra' => 'ignored'],
        ['name' => Rule::string()->required(), 'age' => Rule::numeric()->required()]
    );

    expect($validator->passes())->toBeTrue();
    $validated = $validator->validated();
    expect($validated)->toBe(['name' => 'John', 'age' => 25]);
});

it('excludes absent optional fields from validated data', function (): void {
    $validator = makeValidator(
        ['name' => 'John'],
        ['name' => Rule::string()->required(), 'nickname' => Rule::string()->sometimes()->min(2)]
    );

    expect($validator->passes())->toBeTrue();
    $validated = $validator->validated();
    expect($validated)->toHaveKey('name');
    expect($validated)->not->toHaveKey('nickname');
});

// =========================================================================
// Multiple fluent rules on same field
// =========================================================================

it('handles multiple fluent rules in an array for one field', function (): void {
    $validator = makeValidator(
        ['age' => 25],
        ['age' => [Rule::numeric()->required()->min(0), 'max:120']]
    );

    expect($validator->passes())->toBeTrue();
});

// =========================================================================
// Interaction with ConditionalRules (Rule::when)
// =========================================================================

it('works with Laravel Rule::when when wrapped in array', function (): void {
    $isAdmin = true;

    // Note: Rule::when() requires an array or string, not a bare object.
    // Wrap the fluent rule in an array.
    $validator = makeValidator(
        ['secret' => 'short'],
        ['secret' => LaravelRule::when($isAdmin, [Rule::string()->required()->min(12)])]
    );

    expect($validator->passes())->toBeFalse();
});

it('skips with Laravel Rule::when when condition is false', function (): void {
    $isAdmin = false;

    $validator = makeValidator(
        ['secret' => 'short'],
        ['secret' => LaravelRule::when($isAdmin, [Rule::string()->required()->min(12)])]
    );

    expect($validator->passes())->toBeTrue();
});

// =========================================================================
// Nested array validation
// =========================================================================

it('works for nested dot-notation fields', function (): void {
    $validator = makeValidator(
        ['user' => ['name' => 'John', 'email' => 'john@example.com']],
        ['user.name' => Rule::string()->required()->min(2), 'user.email' => Rule::string()->required()]
    );

    expect($validator->passes())->toBeTrue();
});

it('fails for nested dot-notation fields', function (): void {
    $validator = makeValidator(
        ['user' => ['name' => 'J']],
        ['user.name' => Rule::string()->required()->min(2)]
    );

    expect($validator->passes())->toBeFalse();
});

// =========================================================================
// exclude modifier integration with validated()
// =========================================================================

it('exclude modifier works when used as a native rule alongside the fluent rule', function (): void {
    // Note: exclude/exclude_if/exclude_unless are processed by the outer
    // validator's parser — they can't work inside a self-validating Rule
    // object. Use them as separate native rules alongside the fluent rule.
    $validator = makeValidator(
        ['name' => 'John', 'internal_id' => '123'],
        ['name' => Rule::string()->required(), 'internal_id' => ['exclude', Rule::string()]]
    );

    expect($validator->passes())->toBeTrue();
    $validated = $validator->validated();
    expect($validated)->toHaveKey('name');
    expect($validated)->not->toHaveKey('internal_id');
});
