<?php declare(strict_types=1);

use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\Rules\NumericRule;
use SanderMuller\FluentValidation\Rules\StringRule;
use SanderMuller\FluentValidation\RuleSet;

// =========================================================================
// toPassWith — runs validation and asserts pass
// =========================================================================

it('passes via toPassWith on an array of rules', function (): void {
    expect([
        'email' => FluentRule::email()->required(),
    ])->toPassWith(['email' => 'a@b.test']);
});

it('passes via toPassWith on a RuleSet instance', function (): void {
    expect(RuleSet::make()->field('name', FluentRule::string()->required()))
        ->toPassWith(['name' => 'Ada']);
});

// =========================================================================
// toFailOn — asserts specific failure
// =========================================================================

it('fails via toFailOn without a rule key', function (): void {
    expect([
        'email' => FluentRule::email()->required(),
    ])->toFailOn(['email' => ''], 'email');
});

it('fails via toFailOn with a Studly rule key', function (): void {
    expect([
        'name' => FluentRule::string()->required()->min(5),
    ])->toFailOn(['name' => 'Jo'], 'name', 'Min');
});

it('fails via toFailOn with a snake_case rule key', function (): void {
    expect([
        'name' => FluentRule::string()->required()->min(5),
    ])->toFailOn(['name' => 'Jo'], 'name', 'min');
});

// =========================================================================
// toBeFluentRuleOf — type assertion on the chain head
// =========================================================================

it('asserts a value is a StringRule via toBeFluentRuleOf', function (): void {
    expect(FluentRule::string()->required()->max(255))
        ->toBeFluentRuleOf(StringRule::class);
});

it('asserts a value is a NumericRule via toBeFluentRuleOf', function (): void {
    expect(FluentRule::numeric()->required()->min(1))
        ->toBeFluentRuleOf(NumericRule::class);
});
