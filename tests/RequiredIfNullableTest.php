<?php declare(strict_types=1);

use SanderMuller\FluentValidation\Contracts\FluentRuleContract;
use SanderMuller\FluentValidation\FluentRule;

// =========================================================================
// Regression: a conditional-required object modifier (requiredIf/requiredUnless
// with a bool/closure) combined with nullable() must not drop the requirement
// for an absent or null value when the condition resolves to "required".
//
// The bug: SelfValidates::isNullable() only scanned $this->constraints for the
// literal string 'required'. requiredIf(true)/requiredIf(fn() => ...) store a
// RequiredIf object in $this->rules instead, so the guard bailed out early and
// the active requirement was silently ignored for a missing/null field.
// =========================================================================

/** @return array<string, callable(): FluentRuleContract> */
function selfValidatingFactories(): array
{
    return [
        'email' => static fn (): FluentRuleContract => FluentRule::email(),
        'string' => static fn (): FluentRuleContract => FluentRule::string(),
        'integer' => static fn (): FluentRuleContract => FluentRule::integer(),
        'numeric' => static fn (): FluentRuleContract => FluentRule::numeric(),
    ];
}

// ---------- bool argument -------------------------------------------------

it('requiredIf(true)->nullable() rejects a missing field', function (string $type): void {
    $factory = selfValidatingFactories()[$type];
    $validator = makeValidator([], ['field' => $factory()->bail()->requiredIf(true)->nullable()]);

    expect($validator->fails())->toBeTrue();
})->with(['email', 'string', 'integer', 'numeric']);

it('requiredIf(true)->nullable() rejects a null field', function (string $type): void {
    $factory = selfValidatingFactories()[$type];
    $validator = makeValidator(['field' => null], ['field' => $factory()->bail()->requiredIf(true)->nullable()]);

    expect($validator->fails())->toBeTrue();
})->with(['email', 'string', 'integer', 'numeric']);

it('requiredIf(false)->nullable() accepts a missing field', function (string $type): void {
    $factory = selfValidatingFactories()[$type];
    $validator = makeValidator([], ['field' => $factory()->bail()->requiredIf(false)->nullable()]);

    expect($validator->passes())->toBeTrue();
})->with(['email', 'string', 'integer', 'numeric']);

it('requiredIf(false)->nullable() accepts a null field', function (string $type): void {
    $factory = selfValidatingFactories()[$type];
    $validator = makeValidator(['field' => null], ['field' => $factory()->bail()->requiredIf(false)->nullable()]);

    expect($validator->passes())->toBeTrue();
})->with(['email', 'string', 'integer', 'numeric']);

// ---------- closure argument (lazily evaluated by RequiredIf) -------------

it('requiredIf(fn () => true)->nullable() rejects a missing field', function (): void {
    $validator = makeValidator([], ['field' => FluentRule::email()->bail()->requiredIf(fn (): bool => true)->nullable()]);

    expect($validator->fails())->toBeTrue();
});

it('requiredIf(fn () => false)->nullable() accepts a missing field', function (): void {
    $validator = makeValidator([], ['field' => FluentRule::email()->bail()->requiredIf(fn (): bool => false)->nullable()]);

    expect($validator->passes())->toBeTrue();
});

// ---------- requiredUnless (internally an inverted RequiredIf) ------------

it('requiredUnless(false)->nullable() rejects a missing field', function (): void {
    // unless(false) === required
    $validator = makeValidator([], ['field' => FluentRule::email()->bail()->requiredUnless(false)->nullable()]);

    expect($validator->fails())->toBeTrue();
});

it('requiredUnless(true)->nullable() accepts a missing field', function (): void {
    // unless(true) === not required
    $validator = makeValidator([], ['field' => FluentRule::email()->bail()->requiredUnless(true)->nullable()]);

    expect($validator->passes())->toBeTrue();
});

it('requiredUnless(fn () => false)->nullable() rejects a missing field', function (): void {
    $validator = makeValidator([], ['field' => FluentRule::email()->bail()->requiredUnless(fn (): bool => false)->nullable()]);

    expect($validator->fails())->toBeTrue();
});

// ---------- full matrix from the bug report (email) ----------------------

it('matches the expected fails() matrix for requiredIf(...)->nullable()', function (bool $enabled, array $data, bool $shouldFail): void {
    /** @var array<string, mixed> $data */
    $validator = makeValidator($data, ['email' => FluentRule::email()->bail()->requiredIf($enabled)->nullable()]);

    expect($validator->fails())->toBe($shouldFail);
})->with([
    'true / missing' => [true, [], true],
    'true / empty string' => [true, ['email' => ''], true],
    'true / valid email' => [true, ['email' => 'a@b.com'], false],
    'false / missing' => [false, [], false],
    'false / null' => [false, ['email' => null], false],
    'false / empty string' => [false, ['email' => ''], false],
    'false / invalid email' => [false, ['email' => 'nope'], true],
]);
