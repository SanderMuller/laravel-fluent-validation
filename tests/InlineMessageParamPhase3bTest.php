<?php declare(strict_types=1);

use SanderMuller\FluentValidation\FluentRule;

// =========================================================================
// Phase 3b — `message:` parity for StringRule + NumericRule non-variadic
// rule-adding methods. Composite methods (digits, digitsBetween, exactly
// on NumericRule) bind message: to the LAST sub-rule per spec §2.
// =========================================================================

dataset('phase3b_string', [
    'alpha' => [
        fn () => FluentRule::string()->alpha(message: 'x'),
        fn () => FluentRule::string()->alpha()->message('x'),
        ['alpha' => 'x'],
    ],
    'ascii' => [
        fn () => FluentRule::string()->ascii(message: 'x'),
        fn () => FluentRule::string()->ascii()->message('x'),
        ['ascii' => 'x'],
    ],
    'between' => [
        fn () => FluentRule::string()->between(2, 10, message: 'x'),
        fn () => FluentRule::string()->between(2, 10)->message('x'),
        ['between' => 'x'],
    ],
    'min' => [
        fn () => FluentRule::string()->min(2, message: 'x'),
        fn () => FluentRule::string()->min(2)->message('x'),
        ['min' => 'x'],
    ],
    'max' => [
        fn () => FluentRule::string()->max(100, message: 'x'),
        fn () => FluentRule::string()->max(100)->message('x'),
        ['max' => 'x'],
    ],
    'exactly' => [
        fn () => FluentRule::string()->exactly(5, message: 'x'),
        fn () => FluentRule::string()->exactly(5)->message('x'),
        ['size' => 'x'],
    ],
    'url' => [
        fn () => FluentRule::string()->url(message: 'x'),
        fn () => FluentRule::string()->url()->message('x'),
        ['url' => 'x'],
    ],
    'uuid' => [
        fn () => FluentRule::string()->uuid(message: 'x'),
        fn () => FluentRule::string()->uuid()->message('x'),
        ['uuid' => 'x'],
    ],
    'regex' => [
        fn () => FluentRule::string()->regex('/^[a-z]+$/', message: 'x'),
        fn () => FluentRule::string()->regex('/^[a-z]+$/')->message('x'),
        ['regex' => 'x'],
    ],
    'same' => [
        fn () => FluentRule::string()->same('other', message: 'x'),
        fn () => FluentRule::string()->same('other')->message('x'),
        ['same' => 'x'],
    ],
    'dateFormat' => [
        fn () => FluentRule::string()->dateFormat('Y-m-d', message: 'x'),
        fn () => FluentRule::string()->dateFormat('Y-m-d')->message('x'),
        ['date_format' => 'x'],
    ],
    'confirmed' => [
        fn () => FluentRule::string()->confirmed(message: 'x'),
        fn () => FluentRule::string()->confirmed()->message('x'),
        ['confirmed' => 'x'],
    ],
    'currentPassword' => [
        fn () => FluentRule::string()->currentPassword(message: 'x'),
        fn () => FluentRule::string()->currentPassword()->message('x'),
        ['current_password' => 'x'],
    ],
]);

dataset('phase3b_numeric', [
    'min' => [
        fn () => FluentRule::numeric()->min(0, message: 'x'),
        fn () => FluentRule::numeric()->min(0)->message('x'),
        ['min' => 'x'],
    ],
    'max' => [
        fn () => FluentRule::numeric()->max(100, message: 'x'),
        fn () => FluentRule::numeric()->max(100)->message('x'),
        ['max' => 'x'],
    ],
    'between' => [
        fn () => FluentRule::numeric()->between(0, 100, message: 'x'),
        fn () => FluentRule::numeric()->between(0, 100)->message('x'),
        ['between' => 'x'],
    ],
    'integer' => [
        fn () => FluentRule::numeric()->integer(message: 'x'),
        fn () => FluentRule::numeric()->integer()->message('x'),
        ['integer' => 'x'],
    ],
    'decimal' => [
        fn () => FluentRule::numeric()->decimal(2, message: 'x'),
        fn () => FluentRule::numeric()->decimal(2)->message('x'),
        ['decimal' => 'x'],
    ],
    'positive' => [
        fn () => FluentRule::numeric()->positive(message: 'x'),
        fn () => FluentRule::numeric()->positive()->message('x'),
        ['gt' => 'x'],
    ],
    'greaterThan' => [
        fn () => FluentRule::numeric()->greaterThan('other', message: 'x'),
        fn () => FluentRule::numeric()->greaterThan('other')->message('x'),
        ['gt' => 'x'],
    ],
    'multipleOf' => [
        fn () => FluentRule::numeric()->multipleOf(5, message: 'x'),
        fn () => FluentRule::numeric()->multipleOf(5)->message('x'),
        ['multiple_of' => 'x'],
    ],
]);

it('Phase 3b StringRule: inline message: matches chained ->message()', function (
    Closure $inline,
    Closure $chained,
    array $expected,
): void {
    $inlineRule = $inline();
    $chainedRule = $chained();

    expect($inlineRule->getCustomMessages())->toBe($chainedRule->getCustomMessages())
        ->and($inlineRule->getCustomMessages())->toBe($expected);
})->with('phase3b_string');

it('Phase 3b NumericRule: inline message: matches chained ->message()', function (
    Closure $inline,
    Closure $chained,
    array $expected,
): void {
    $inlineRule = $inline();
    $chainedRule = $chained();

    expect($inlineRule->getCustomMessages())->toBe($chainedRule->getCustomMessages())
        ->and($inlineRule->getCustomMessages())->toBe($expected);
})->with('phase3b_numeric');

// =========================================================================
// Composite-method sub-rule binding (per spec §2, resolved Q#10).
// =========================================================================

it('NumericRule::digits(message: ...) binds to digits, not integer', function (): void {
    $rule = FluentRule::numeric()->digits(5, message: 'Must be 5 digits.');

    expect($rule->getCustomMessages())->toBe(['digits' => 'Must be 5 digits.']);
});

it('NumericRule::digits() + messageFor(integer, ...) targets the integer sub-rule', function (): void {
    $rule = FluentRule::numeric()
        ->digits(5, message: 'Must be 5 digits.')
        ->messageFor('integer', 'Must be a whole number.');

    expect($rule->getCustomMessages())->toBe([
        'digits' => 'Must be 5 digits.',
        'integer' => 'Must be a whole number.',
    ]);
});

it('NumericRule::digitsBetween(message: ...) binds to digits_between', function (): void {
    $rule = FluentRule::numeric()->digitsBetween(2, 5, message: 'Wrong length.');

    expect($rule->getCustomMessages())->toBe(['digits_between' => 'Wrong length.']);
});

it('NumericRule::exactly(message: ...) binds to size', function (): void {
    $rule = FluentRule::numeric()->exactly(42, message: 'Must be exactly 42.');

    expect($rule->getCustomMessages())->toBe(['size' => 'Must be exactly 42.']);
});

// =========================================================================
// Live-validator smoke tests.
// =========================================================================

it('StringRule::min(message: ...) surfaces in validation', function (): void {
    $v = makeValidator(
        ['name' => 'a'],
        ['name' => FluentRule::string()->min(2, message: 'Too short!')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('name'))->toBe('Too short!');
});

it('NumericRule::between(message: ...) surfaces in validation', function (): void {
    $v = makeValidator(
        ['age' => 200],
        ['age' => FluentRule::numeric()->between(0, 150, message: 'Out of range.')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('age'))->toBe('Out of range.');
});
