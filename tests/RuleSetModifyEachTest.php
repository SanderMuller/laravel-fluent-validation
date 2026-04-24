<?php declare(strict_types=1);

use Illuminate\Contracts\Validation\ValidationRule;
use SanderMuller\FluentValidation\Exceptions\CannotExtendListShapedEach;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\Rules\ArrayRule;
use SanderMuller\FluentValidation\Rules\FieldRule;
use SanderMuller\FluentValidation\RuleSet;

// =========================================================================
// 1.24.0 — RuleSet::modifyEach / modifyChildren sugar.
// Each is a one-liner wrapper around modify(KEY, fn ($r) => $r->mergeEachRules(...))
// / ->mergeChildRules(...). Later-wins semantic (array_merge style).
// =========================================================================

it('modifyEach merges keyed rules into the target ArrayRule (later-wins)', function (): void {
    $parent = FluentRule::string()->required();
    $child = FluentRule::numeric()->required();

    $ruleSet = RuleSet::from([
        'items' => FluentRule::array()->nullable()->each(['name' => $parent]),
    ])->modifyEach('items', ['name' => $child, 'id' => FluentRule::numeric()]);

    /** @var array<string, ValidationRule> $each */
    $each = targetEach($ruleSet, 'items');
    expect($each['name'])->toBe($child)
        ->and($each)->toHaveKeys(['name', 'id']);
});

it('modifyEach preserves base constraints (nullable, max)', function (): void {
    $ruleSet = RuleSet::from([
        'items' => FluentRule::array()->nullable()->max(20)->each([
            'text' => FluentRule::string()->required(),
        ]),
    ])->modifyEach('items', ['id' => FluentRule::numeric()->nullable()]);

    $compiled = RuleSet::compileToArrays($ruleSet->toArray());

    $itemsRule = $compiled['items'];
    $itemsString = is_array($itemsRule)
        ? implode('|', array_map(static fn (mixed $r): string => is_string($r) ? $r : '', $itemsRule))
        : (string) $itemsRule;
    expect($itemsString)->toContain('nullable')->toContain('max:20')
        ->and($compiled)->toHaveKeys(['items.*.text', 'items.*.id']);
});

it('modifyEach throws when field is not in the rule set', function (): void {
    $ruleSet = RuleSet::from(['a' => FluentRule::array()->each(['x' => FluentRule::string()])]);

    expect(fn () => $ruleSet->modifyEach('missing', ['y' => FluentRule::string()]))
        ->toThrow(LogicException::class, 'not in the rule set');
});

it('modifyEach throws when field is not an ArrayRule', function (): void {
    $ruleSet = RuleSet::from(['email' => FluentRule::email()->required()]);

    expect(fn () => $ruleSet->modifyEach('email', ['x' => FluentRule::string()]))
        ->toThrow(LogicException::class, 'not an ArrayRule');
});

it('modifyEach propagates CannotExtendListShapedEach from list-form parent', function (): void {
    $ruleSet = RuleSet::from(['items' => FluentRule::array()->each(FluentRule::string())]);

    expect(fn () => $ruleSet->modifyEach('items', ['id' => FluentRule::numeric()]))
        ->toThrow(CannotExtendListShapedEach::class);
});

it('modifyChildren merges child rules into the target FieldRule (later-wins)', function (): void {
    $parent = FluentRule::string()->required();
    $child = FluentRule::email()->required();

    $ruleSet = RuleSet::from([
        'answer' => FluentRule::field()->required()->children(['email' => $parent]),
    ])->modifyChildren('answer', ['email' => $child, 'name' => FluentRule::string()]);

    /** @var array<string, ValidationRule> $children */
    $children = targetChildren($ruleSet, 'answer');
    expect($children['email'])->toBe($child)
        ->and($children)->toHaveKeys(['email', 'name']);
});

it('modifyChildren preserves base constraints on the FieldRule', function (): void {
    $ruleSet = RuleSet::from([
        'answer' => FluentRule::field()->required()->children([
            'text' => FluentRule::string()->required(),
        ]),
    ])->modifyChildren('answer', ['id' => FluentRule::numeric()->nullable()]);

    $compiled = RuleSet::compileToArrays($ruleSet->toArray());

    $answerRule = $compiled['answer'];
    $answerString = is_array($answerRule)
        ? implode('|', array_map(static fn (mixed $r): string => is_string($r) ? $r : '', $answerRule))
        : (string) $answerRule;
    expect($answerString)->toContain('required')
        ->and($compiled)->toHaveKeys(['answer.text', 'answer.id']);
});

it('modifyChildren throws when field is not a FieldRule', function (): void {
    $ruleSet = RuleSet::from(['items' => FluentRule::array()->each(['x' => FluentRule::string()])]);

    expect(fn () => $ruleSet->modifyChildren('items', ['y' => FluentRule::string()]))
        ->toThrow(LogicException::class, 'not a FieldRule');
});

it('modifyChildren throws when field is not in the rule set', function (): void {
    $ruleSet = RuleSet::from(['answer' => FluentRule::field()->children(['a' => FluentRule::string()])]);

    expect(fn () => $ruleSet->modifyChildren('missing', ['b' => FluentRule::string()]))
        ->toThrow(LogicException::class, 'not in the rule set');
});

it('modifyEach returns the same RuleSet instance for chaining', function (): void {
    $ruleSet = RuleSet::from(['items' => FluentRule::array()->each(['a' => FluentRule::string()])]);

    expect($ruleSet->modifyEach('items', ['b' => FluentRule::string()]))->toBe($ruleSet);
});

it('modifyChildren returns the same RuleSet instance for chaining', function (): void {
    $ruleSet = RuleSet::from(['answer' => FluentRule::field()->children(['a' => FluentRule::string()])]);

    expect($ruleSet->modifyChildren('answer', ['b' => FluentRule::string()]))->toBe($ruleSet);
});

// -------------------------------------------------------------------------
// Helpers — poke at the stored rule without triggering deprecation warnings.
// -------------------------------------------------------------------------

/** @return array<string, ValidationRule> */
function targetEach(RuleSet $ruleSet, string $field): array
{
    $rule = $ruleSet->get($field);
    assert($rule instanceof ArrayRule);

    /** @var array<string, ValidationRule> */
    return $rule->getEachKeyedRules() ?? [];
}

/** @return array<string, ValidationRule> */
function targetChildren(RuleSet $ruleSet, string $field): array
{
    $rule = $ruleSet->get($field);
    assert($rule instanceof FieldRule);

    return $rule->getChildRules() ?? [];
}
