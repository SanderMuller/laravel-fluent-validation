<?php declare(strict_types=1);

use Illuminate\Contracts\Validation\ValidationRule;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\Rules\FieldRule;
use SanderMuller\FluentValidation\RuleSet;

// =========================================================================
// Phase 2 — FieldRule::addChildRule / mergeChildRules.
// Spec: internal/specs/each-children-extend-helpers.md
// =========================================================================

// ---------- Empty-key guards ---------------------------------------------

it('addChildRule rejects empty-string key', function (): void {
    $rule = FluentRule::field()->children(['email' => FluentRule::email()]);

    expect(fn () => $rule->addChildRule('', FluentRule::string()))
        ->toThrow(InvalidArgumentException::class, 'non-empty key');
});

it('mergeChildRules rejects empty-string key', function (): void {
    $rule = FluentRule::field()->children(['email' => FluentRule::email()]);

    expect(fn () => $rule->mergeChildRules(['' => FluentRule::string()]))
        ->toThrow(InvalidArgumentException::class, 'non-empty keys');
});

// ---------- Collision ------------------------------------------------------

it('addChildRule throws on existing-key collision', function (): void {
    $rule = FluentRule::field()->children(['email' => FluentRule::email()]);

    expect(fn () => $rule->addChildRule('email', FluentRule::string()))
        ->toThrow(LogicException::class, "addChildRule('email'): key 'email' already exists");
});

it('addChildRule collision message points at mergeChildRules for replacement', function (): void {
    $rule = FluentRule::field()->children(['email' => FluentRule::email()]);

    try {
        $rule->addChildRule('email', FluentRule::string());
        $this->fail('expected LogicException');
    } catch (LogicException $logicException) {
        expect($logicException->getMessage())->toContain('mergeChildRules()');
    }
});

// ---------- Happy path: append / merge ------------------------------------

it('addChildRule appends new keyed child rule', function (): void {
    $rule = FluentRule::field()->children(['email' => FluentRule::email()]);
    $rule->addChildRule('name', FluentRule::string()->required());

    expect($rule->getChildRules())
        ->toBeArray()
        ->toHaveKeys(['email', 'name']);
});

it('addChildRule on null childRules state initializes a fresh keyed map', function (): void {
    $rule = FluentRule::field();
    $rule->addChildRule('email', FluentRule::email());

    expect($rule->getChildRules())->toBeArray()->toHaveKey('email');
});

it('mergeChildRules later-wins overrides existing keys', function (): void {
    $parent = FluentRule::string()->required();
    $child = FluentRule::email()->required();

    $rule = FluentRule::field()->children(['email' => $parent]);
    $rule->mergeChildRules(['email' => $child, 'name' => FluentRule::string()]);

    /** @var array<string, ValidationRule> $children */
    $children = $rule->getChildRules();
    expect($children['email'])->toBe($child)
        ->and($children)->toHaveKey('name');
});

it('mergeChildRules on null childRules state initializes a fresh keyed map', function (): void {
    $rule = FluentRule::field();
    $rule->mergeChildRules(['email' => FluentRule::email()]);

    expect($rule->getChildRules())->toBeArray()->toHaveKey('email');
});

it('addChildRule + mergeChildRules return $this for chaining', function (): void {
    $rule = FluentRule::field()->children(['a' => FluentRule::string()]);

    expect($rule->addChildRule('b', FluentRule::string()))->toBe($rule)
        ->and($rule->mergeChildRules(['c' => FluentRule::string()]))->toBe($rule);
});

// ---------- Preservation contract: base constraint chain survives ---------

it('addChildRule preserves base constraints (required, present) on the FieldRule', function (): void {
    $rule = FluentRule::field()->required()->children(['email' => FluentRule::email()]);
    $rule->addChildRule('name', FluentRule::string());

    expect($rule->toArray())->toContain('required');
});

it('mergeChildRules preserves base constraints on the FieldRule', function (): void {
    $rule = FluentRule::field()->present()->children(['email' => FluentRule::email()]);
    $rule->mergeChildRules(['name' => FluentRule::string()]);

    expect($rule->toArray())->toContain('present');
});

// ---------- Concrete hihaho-style: parent::rules()->modify(KEY, …) --------

it('extends parent children-shape via RuleSet::modify without losing parent constraints', function (): void {
    $parentRuleSet = RuleSet::from([
        'answer' => FluentRule::field()->required()->children([
            'text' => FluentRule::string()->required(),
        ]),
    ]);

    $childRuleSet = $parentRuleSet->modify('answer', static function (mixed $rule): FieldRule {
        assert($rule instanceof FieldRule);

        return $rule->addChildRule('id', FluentRule::numeric()->nullable());
    });

    $compiled = RuleSet::compileToArrays($childRuleSet->toArray());

    expect($compiled)->toHaveKeys(['answer.text', 'answer.id']);

    $answerRules = $compiled['answer'];
    $answerString = is_array($answerRules)
        ? implode('|', array_map(static fn (mixed $r): string => is_string($r) ? $r : '', $answerRules))
        : (string) $answerRules;
    expect($answerString)->toContain('required');
});
