<?php declare(strict_types=1);

use SanderMuller\FluentValidation\FluentRule;

// =========================================================================
// Phase 3d — `message:` parity for EmailRule, PasswordRule, BooleanRule,
// AcceptedRule, DeclinedRule, FieldRule (smaller rules + edge cases).
// =========================================================================

dataset('phase3d_email', [
    'max' => [
        fn () => FluentRule::email()->max(255, message: 'x'),
        fn () => FluentRule::email()->max(255)->message('x'),
        ['max' => 'x'],
    ],
    'confirmed' => [
        fn () => FluentRule::email()->confirmed(message: 'x'),
        fn () => FluentRule::email()->confirmed()->message('x'),
        ['confirmed' => 'x'],
    ],
    'same' => [
        fn () => FluentRule::email()->same('other', message: 'x'),
        fn () => FluentRule::email()->same('other')->message('x'),
        ['same' => 'x'],
    ],
    'different' => [
        fn () => FluentRule::email()->different('other', message: 'x'),
        fn () => FluentRule::email()->different('other')->message('x'),
        ['different' => 'x'],
    ],
]);

dataset('phase3d_password', [
    'confirmed' => [
        fn () => FluentRule::password()->confirmed(message: 'x'),
        fn () => FluentRule::password()->confirmed()->message('x'),
        ['confirmed' => 'x'],
    ],
]);

dataset('phase3d_boolean', [
    'accepted' => [
        fn () => FluentRule::boolean()->accepted(message: 'x'),
        fn () => FluentRule::boolean()->accepted()->message('x'),
        ['accepted' => 'x'],
    ],
    'declined' => [
        fn () => FluentRule::boolean()->declined(message: 'x'),
        fn () => FluentRule::boolean()->declined()->message('x'),
        ['declined' => 'x'],
    ],
]);

dataset('phase3d_field', [
    'same' => [
        fn () => FluentRule::field()->same('other', message: 'x'),
        fn () => FluentRule::field()->same('other')->message('x'),
        ['same' => 'x'],
    ],
    'different' => [
        fn () => FluentRule::field()->different('other', message: 'x'),
        fn () => FluentRule::field()->different('other')->message('x'),
        ['different' => 'x'],
    ],
    'confirmed' => [
        fn () => FluentRule::field()->confirmed(message: 'x'),
        fn () => FluentRule::field()->confirmed()->message('x'),
        ['confirmed' => 'x'],
    ],
]);

it('Phase 3d EmailRule: inline message: matches chained ->message()', function (
    Closure $inline,
    Closure $chained,
    array $expected,
): void {
    $inlineRule = $inline();
    $chainedRule = $chained();

    expect($inlineRule->getCustomMessages())->toBe($chainedRule->getCustomMessages())
        ->and($inlineRule->getCustomMessages())->toBe($expected);
})->with('phase3d_email');

it('Phase 3d PasswordRule: inline message: matches chained ->message()', function (
    Closure $inline,
    Closure $chained,
    array $expected,
): void {
    $inlineRule = $inline();
    $chainedRule = $chained();

    expect($inlineRule->getCustomMessages())->toBe($chainedRule->getCustomMessages())
        ->and($inlineRule->getCustomMessages())->toBe($expected);
})->with('phase3d_password');

it('Phase 3d BooleanRule: inline message: matches chained ->message()', function (
    Closure $inline,
    Closure $chained,
    array $expected,
): void {
    $inlineRule = $inline();
    $chainedRule = $chained();

    expect($inlineRule->getCustomMessages())->toBe($chainedRule->getCustomMessages())
        ->and($inlineRule->getCustomMessages())->toBe($expected);
})->with('phase3d_boolean');

it('Phase 3d FieldRule: inline message: matches chained ->message()', function (
    Closure $inline,
    Closure $chained,
    array $expected,
): void {
    $inlineRule = $inline();
    $chainedRule = $chained();

    expect($inlineRule->getCustomMessages())->toBe($chainedRule->getCustomMessages())
        ->and($inlineRule->getCustomMessages())->toBe($expected);
})->with('phase3d_field');

// =========================================================================
// Email/Password configure-only methods (not addRule callers) — skipped.
// =========================================================================

it('EmailRule::rfcCompliant is a mode modifier; does not accept message:', function (): void {
    // Sanity: calling rfcCompliant without message still works.
    $rule = FluentRule::email()->rfcCompliant();

    expect($rule->getCustomMessages())->toBeEmpty();
});

it('PasswordRule::mixedCase is a mode modifier; does not accept message:', function (): void {
    $rule = FluentRule::password()->mixedCase();

    expect($rule->getCustomMessages())->toBeEmpty();
});

// =========================================================================
// Live-validator smoke.
// =========================================================================

it('EmailRule::max(message: ...) surfaces in validation', function (): void {
    $v = makeValidator(
        ['email' => 'a@' . str_repeat('b', 300) . '.com'],
        ['email' => FluentRule::email()->max(50, message: 'Too long!')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('email'))->toBe('Too long!');
});

it('BooleanRule::accepted(message: ...) surfaces in validation', function (): void {
    $v = makeValidator(
        ['tos' => false],
        ['tos' => FluentRule::boolean()->accepted(message: 'Must accept.')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('tos'))->toBe('Must accept.');
});
