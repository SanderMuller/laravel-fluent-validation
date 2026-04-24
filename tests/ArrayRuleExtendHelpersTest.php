<?php declare(strict_types=1);

use Illuminate\Contracts\Validation\ValidationRule;
use SanderMuller\FluentValidation\Exceptions\CannotExtendListShapedEach;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\Rules\ArrayRule;
use SanderMuller\FluentValidation\RuleSet;

// =========================================================================
// Phase 1 — ArrayRule::addEachRule / mergeEachRules.
// Spec: internal/specs/each-children-extend-helpers.md
// =========================================================================

// ---------- List-shape state refuses extension ----------------------------

it('addEachRule throws when each() is list-shaped (single ValidationRule)', function (): void {
    $rule = FluentRule::array()->each(FluentRule::string());

    expect(fn () => $rule->addEachRule('id', FluentRule::numeric()))
        ->toThrow(CannotExtendListShapedEach::class, 'addEachRule()');
});

it('mergeEachRules throws when each() is list-shaped', function (): void {
    $rule = FluentRule::array()->each(FluentRule::string());

    expect(fn () => $rule->mergeEachRules(['id' => FluentRule::numeric()]))
        ->toThrow(CannotExtendListShapedEach::class, 'mergeEachRules()');
});

it('CannotExtendListShapedEach message points at each([…]) remediation', function (): void {
    $e = CannotExtendListShapedEach::on('addEachRule');

    expect($e->getMessage())
        ->toContain('addEachRule()')
        ->toContain('each(')
        ->toContain('keyed form');
});

// ---------- Collision ------------------------------------------------------

it('addEachRule throws on existing-key collision', function (): void {
    $rule = FluentRule::array()->each(['id' => FluentRule::numeric()]);

    expect(fn () => $rule->addEachRule('id', FluentRule::string()))
        ->toThrow(LogicException::class, "addEachRule('id'): key 'id' already exists");
});

it('addEachRule collision message points at mergeEachRules for replacement', function (): void {
    $rule = FluentRule::array()->each(['id' => FluentRule::numeric()]);

    try {
        $rule->addEachRule('id', FluentRule::string());
        $this->fail('expected LogicException');
    } catch (LogicException $logicException) {
        expect($logicException->getMessage())->toContain('mergeEachRules()');
    }
});

// ---------- Happy path: append / merge ------------------------------------

it('addEachRule appends new keyed sub-rule to keyed each()', function (): void {
    $rule = FluentRule::array()->each(['name' => FluentRule::string()]);
    $rule->addEachRule('id', FluentRule::numeric());

    expect($rule->getEachKeyedRules())
        ->toBeArray()
        ->toHaveKeys(['name', 'id']);
});

it('addEachRule on null eachRules state initializes a fresh keyed map', function (): void {
    // No each() called — addEachRule acts as a permissive first-set.
    $rule = FluentRule::array();
    $idRule = FluentRule::numeric();
    $rule->addEachRule('id', $idRule);

    /** @var array<string, ValidationRule> $each */
    $each = $rule->getEachKeyedRules();
    expect($each)->toHaveKey('id')
        ->and($each['id'])->toBe($idRule);
});

it('mergeEachRules later-wins overrides existing keys', function (): void {
    $parent = FluentRule::string()->required();
    $child = FluentRule::numeric()->required();

    $rule = FluentRule::array()->each(['id' => $parent]);
    $rule->mergeEachRules(['id' => $child, 'name' => FluentRule::string()]);

    /** @var array<string, ValidationRule> $each */
    $each = $rule->getEachKeyedRules();
    expect($each['id'])->toBe($child)
        ->and($each)->toHaveKey('name');
});

it('mergeEachRules on null eachRules state initializes a fresh keyed map', function (): void {
    $rule = FluentRule::array();
    $rule->mergeEachRules(['id' => FluentRule::numeric()]);

    expect($rule->getEachKeyedRules())->toBeArray()->toHaveKey('id');
});

it('addEachRule + mergeEachRules return $this for chaining', function (): void {
    $rule = FluentRule::array()->each(['a' => FluentRule::string()]);

    $returned = $rule->addEachRule('b', FluentRule::string());
    expect($returned)->toBe($rule);

    $returned = $rule->mergeEachRules(['c' => FluentRule::string()]);
    expect($returned)->toBe($rule);
});

// ---------- Preservation contract: base constraint chain survives ---------

it('addEachRule preserves base constraints (nullable, max) on the ArrayRule', function (): void {
    $rule = FluentRule::array()->nullable()->max(20)->each(['name' => FluentRule::string()]);
    $rule->addEachRule('id', FluentRule::numeric());

    $compiled = $rule->toArray();
    expect($compiled)->toContain('nullable')
        ->toContain('max:20');
});

it('mergeEachRules preserves base constraints on the ArrayRule', function (): void {
    $rule = FluentRule::array()->required()->min(1)->each(['a' => FluentRule::string()]);
    $rule->mergeEachRules(['b' => FluentRule::string()]);

    $compiled = $rule->toArray();
    expect($compiled)->toContain('required')
        ->toContain('min:1');
});

// ---------- Empty-key guards ---------------------------------------------

it('addEachRule rejects empty-string key', function (): void {
    $rule = FluentRule::array()->each(['name' => FluentRule::string()]);

    expect(fn () => $rule->addEachRule('', FluentRule::numeric()))
        ->toThrow(InvalidArgumentException::class, 'non-empty key');
});

it('mergeEachRules rejects empty-string key', function (): void {
    $rule = FluentRule::array()->each(['name' => FluentRule::string()]);

    expect(fn () => $rule->mergeEachRules(['' => FluentRule::numeric()]))
        ->toThrow(InvalidArgumentException::class, 'non-empty keys');
});

// ---------- Storage-split contract: list and keyed slots are mutually exclusive ----

it('each(ValidationRule) after each([…]) wipes keyed rules', function (): void {
    $rule = FluentRule::array()->each(['name' => FluentRule::string()]);
    $rule->each(FluentRule::string()->required());

    // Now list-shaped — addEachRule must refuse.
    expect(fn () => $rule->addEachRule('id', FluentRule::numeric()))
        ->toThrow(CannotExtendListShapedEach::class);

    expect($rule->getEachListRule())->toBeInstanceOf(ValidationRule::class)
        ->and($rule->getEachKeyedRules())->toBeNull();
});

it('each([…]) after each(ValidationRule) wipes the list rule', function (): void {
    $rule = FluentRule::array()->each(FluentRule::string()->required());
    $rule->each(['name' => FluentRule::string()]);

    // Keyed now — addEachRule must work.
    $rule->addEachRule('id', FluentRule::numeric());

    expect($rule->getEachKeyedRules())->toBeArray()->toHaveKeys(['name', 'id'])
        ->and($rule->getEachListRule())->toBeNull();
});

it('withoutEachRules clears both list and keyed slots', function (): void {
    $listShaped = FluentRule::array()->each(FluentRule::string())->withoutEachRules();
    expect($listShaped->getEachListRule())->toBeNull()
        ->and($listShaped->getEachKeyedRules())->toBeNull();

    $keyedShaped = FluentRule::array()->each(['name' => FluentRule::string()])->withoutEachRules();
    expect($keyedShaped->getEachListRule())->toBeNull()
        ->and($keyedShaped->getEachKeyedRules())->toBeNull();
});

// ---------- Concrete hihaho-style: parent::rules()->modify(KEY, …) --------

it('extends parent each-shape via RuleSet::modify without losing parent constraints', function (): void {
    // Parent shape
    $parentRuleSet = RuleSet::from([
        'answers' => FluentRule::array()->nullable()->max(20)->each([
            'text' => FluentRule::string()->required(),
        ]),
    ]);

    // Child extend pattern
    $childRuleSet = $parentRuleSet->modify('answers', static function (mixed $rule): ArrayRule {
        assert($rule instanceof ArrayRule);

        return $rule->addEachRule('id', FluentRule::numeric()->nullable());
    });

    $compiled = RuleSet::compileToArrays($childRuleSet->toArray());

    // Parent's each-field survives
    expect($compiled)->toHaveKey('answers.*.text');

    // Child's added field is present
    expect($compiled)->toHaveKey('answers.*.id');

    // Parent's ArrayRule base constraints survive on the top-level `answers` rule
    $answersRules = $compiled['answers'];
    $answersString = is_array($answersRules) ? implode('|', array_map(static fn (mixed $r): string => is_string($r) ? $r : '', $answersRules)) : (string) $answersRules;
    expect($answersString)->toContain('nullable')
        ->toContain('max:20');
});
