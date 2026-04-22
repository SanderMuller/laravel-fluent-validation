<?php declare(strict_types=1);

use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\Tests\Fixtures\TestStringEnum;

// =========================================================================
// Phase 3a — `message:` named-arg parity across HasFieldModifiers +
// HasEmbeddedRules non-variadic methods. Every row asserts that
// method(..., message: 'x') and method(...)->message('x') produce equal
// customMessages. Variadic-trailing methods (requiredWith, presentIf,
// excludeIf, etc.) can't take `message:` after a variadic param — covered
// by a dedicated exclusion test.
// =========================================================================

dataset('phase3a_methods', [
    'required' => [
        fn () => FluentRule::string()->required(message: 'x'),
        fn () => FluentRule::string()->required()->message('x'),
        ['required' => 'x'],
    ],
    'sometimes' => [
        fn () => FluentRule::string()->sometimes(message: 'x'),
        fn () => FluentRule::string()->sometimes()->message('x'),
        ['sometimes' => 'x'],
    ],
    'filled' => [
        fn () => FluentRule::string()->filled(message: 'x'),
        fn () => FluentRule::string()->filled()->message('x'),
        ['filled' => 'x'],
    ],
    'present' => [
        fn () => FluentRule::field()->present(message: 'x'),
        fn () => FluentRule::field()->present()->message('x'),
        ['present' => 'x'],
    ],
    'prohibited' => [
        fn () => FluentRule::string()->prohibited(message: 'x'),
        fn () => FluentRule::string()->prohibited()->message('x'),
        ['prohibited' => 'x'],
    ],
    'missing' => [
        fn () => FluentRule::string()->missing(message: 'x'),
        fn () => FluentRule::string()->missing()->message('x'),
        ['missing' => 'x'],
    ],
    'requiredIfAccepted' => [
        fn () => FluentRule::string()->requiredIfAccepted('tos', message: 'x'),
        fn () => FluentRule::string()->requiredIfAccepted('tos')->message('x'),
        ['required_if_accepted' => 'x'],
    ],
    'requiredIfDeclined' => [
        fn () => FluentRule::string()->requiredIfDeclined('tos', message: 'x'),
        fn () => FluentRule::string()->requiredIfDeclined('tos')->message('x'),
        ['required_if_declined' => 'x'],
    ],
    'prohibitedIfAccepted' => [
        fn () => FluentRule::string()->prohibitedIfAccepted('tos', message: 'x'),
        fn () => FluentRule::string()->prohibitedIfAccepted('tos')->message('x'),
        ['prohibited_if_accepted' => 'x'],
    ],
    'prohibitedIfDeclined' => [
        fn () => FluentRule::string()->prohibitedIfDeclined('tos', message: 'x'),
        fn () => FluentRule::string()->prohibitedIfDeclined('tos')->message('x'),
        ['prohibited_if_declined' => 'x'],
    ],
    'rule-string' => [
        fn () => FluentRule::string()->rule('max:100', message: 'x'),
        fn () => FluentRule::string()->rule('max:100')->message('x'),
        ['max' => 'x'],
    ],
    'unique' => [
        fn () => FluentRule::string()->unique('users', 'email', message: 'x'),
        fn () => FluentRule::string()->unique('users', 'email')->message('x'),
        ['unique' => 'x'],
    ],
    'exists' => [
        fn () => FluentRule::string()->exists('users', 'email', message: 'x'),
        fn () => FluentRule::string()->exists('users', 'email')->message('x'),
        ['exists' => 'x'],
    ],
    'enum' => [
        fn () => FluentRule::string()->enum(TestStringEnum::class, message: 'x'),
        fn () => FluentRule::string()->enum(TestStringEnum::class)->message('x'),
        ['enum' => 'x'],
    ],
    'in' => [
        fn () => FluentRule::string()->in(['admin', 'user'], message: 'x'),
        fn () => FluentRule::string()->in(['admin', 'user'])->message('x'),
        ['in' => 'x'],
    ],
    'notIn' => [
        fn () => FluentRule::string()->notIn(['banned'], message: 'x'),
        fn () => FluentRule::string()->notIn(['banned'])->message('x'),
        ['not_in' => 'x'],
    ],
]);

it('Phase 3a: inline message: matches chained ->message() and records expected key', function (
    Closure $inline,
    Closure $chained,
    array $expected,
): void {
    $inlineRule = $inline();
    $chainedRule = $chained();

    expect($inlineRule->getCustomMessages())->toBe($chainedRule->getCustomMessages())
        ->and($inlineRule->getCustomMessages())->toBe($expected);
})->with('phase3a_methods');

// =========================================================================
// Live-validator smoke test — inline `message:` surfaces in errors.
// =========================================================================

it('required(message: ...) surfaces in live validation', function (): void {
    $v = makeValidator(
        ['name' => ''],
        ['name' => FluentRule::string()->required(message: 'Name is required!')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('name'))->toBe('Name is required!');
});

it('rule(..., message: ...) surfaces in live validation', function (): void {
    $v = makeValidator(
        ['name' => str_repeat('a', 300)],
        ['name' => FluentRule::string()->rule('max:100', message: 'Too long!')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('name'))->toBe('Too long!');
});

// =========================================================================
// Variadic-trailing methods cannot accept message: — users rely on
// ->message() or messageFor(). Document with explicit test.
// =========================================================================

it('variadic methods route messages via ->message() instead', function (): void {
    $rule = FluentRule::string()->requiredWith('email', 'phone')->message('Required when email or phone is set.');

    expect($rule->getCustomMessages())->toBe(['required_with' => 'Required when email or phone is set.']);
});
