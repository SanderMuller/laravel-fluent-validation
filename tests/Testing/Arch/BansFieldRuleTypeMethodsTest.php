<?php declare(strict_types=1);

use SanderMuller\FluentValidation\Testing\Arch\BansFieldRuleTypeMethods;

const FIXTURE_DIR = __DIR__ . '/../../Fixtures/MacroableFootgun';

it('flags FluentRule::field()->min() as a violation', function (): void {
    $violations = BansFieldRuleTypeMethods::scope(FIXTURE_DIR . '/violating_direct.php');

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toEndWith('violating_direct.php');
});

it('flags chained FluentRule::field()->...->between() as a violation', function (): void {
    $violations = BansFieldRuleTypeMethods::scope(FIXTURE_DIR . '/violating_chained.php');

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toEndWith('violating_chained.php');
});

it('does not flag FluentRule::numeric()->min() or ::string()->between()', function (): void {
    $violations = BansFieldRuleTypeMethods::scope(FIXTURE_DIR . '/clean_typed_builder.php');

    expect($violations)->toBeEmpty();
});

it('does not flag legitimate FieldRule methods like exists() or present()', function (): void {
    $violations = BansFieldRuleTypeMethods::scope(FIXTURE_DIR . '/clean_legit_field.php');

    expect($violations)->toBeEmpty();
});

it('does not flag ::field()->min() on an unrelated class', function (): void {
    $violations = BansFieldRuleTypeMethods::scope(FIXTURE_DIR . '/clean_other_class_field.php');

    expect($violations)->toBeEmpty();
});

it('flags violations through an aliased import (use FluentRule as Rule)', function (): void {
    $violations = BansFieldRuleTypeMethods::scope(FIXTURE_DIR . '/violating_aliased.php');

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toEndWith('violating_aliased.php');
});

it('does not flag an unrelated class with the same short name FluentRule', function (): void {
    $violations = BansFieldRuleTypeMethods::scope(FIXTURE_DIR . '/clean_unrelated_fluentrule.php');

    expect($violations)->toBeEmpty();
});

it('walks a directory recursively and returns sorted absolute paths', function (): void {
    $violations = BansFieldRuleTypeMethods::scope(FIXTURE_DIR);

    expect($violations)->toHaveCount(3)
        ->and($violations[0])->toEndWith('violating_aliased.php')
        ->and($violations[1])->toEndWith('violating_chained.php')
        ->and($violations[2])->toEndWith('violating_direct.php');
});

it('returns an empty array for a non-existent path', function (): void {
    expect(BansFieldRuleTypeMethods::scope(__DIR__ . '/does-not-exist'))->toBeEmpty();
});

it('accepts multiple paths in a single call', function (): void {
    $violations = BansFieldRuleTypeMethods::scope(
        FIXTURE_DIR . '/violating_direct.php',
        FIXTURE_DIR . '/violating_chained.php',
    );

    expect($violations)->toHaveCount(2);
});
