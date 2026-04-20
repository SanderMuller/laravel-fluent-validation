<?php declare(strict_types=1);

use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\RuleSet;

// =========================================================================
// Bare `prohibited` fast-check parity. Laravel's prohibited family in
// pipe-string form is value-conditional (`prohibited_if`, `prohibited_unless`,
// `prohibited_if_accepted`, `prohibited_if_declined`) — there is no
// `prohibited_with*` / `prohibited_without*` family. Those are covered by
// a future value-conditional reducer spec. This file pins only the bare
// `prohibited` rule's fast-check closure, which is the 1.16.0 scope.
// =========================================================================

it('bare prohibited: fast-check passes when value is empty', function (): void {
    $ruleSet = RuleSet::from(['items.*.forbidden' => FluentRule::field()->prohibited()]);

    foreach ([[], ['forbidden' => null], ['forbidden' => ''], ['forbidden' => []], ['forbidden' => '   ']] as $shape) {
        $errors = $ruleSet->check(['items' => [$shape]])->errors()->toArray();
        expect($errors)->toBeEmpty();
    }
});

it('bare prohibited: fast-check fails when value is non-empty', function (): void {
    $ruleSet = RuleSet::from(['items.*.forbidden' => FluentRule::field()->prohibited()]);

    foreach ([['forbidden' => 'X'], ['forbidden' => 0], ['forbidden' => [1]], ['forbidden' => false]] as $shape) {
        $errors = $ruleSet->check(['items' => [$shape]])->errors()->toArray();
        expect($errors)->toHaveKey('items.0.forbidden');
    }
});

it('bare prohibited: verdict matches native Laravel across shape grid', function (): void {
    $shapes = [
        ['forbidden' => 'X'],
        ['forbidden' => 0],
        ['forbidden' => null],
        ['forbidden' => ''],
        ['forbidden' => []],
        ['forbidden' => '   '],
        ['forbidden' => false],
        [],
    ];

    foreach ($shapes as $shape) {
        $native = validator($shape, ['forbidden' => 'prohibited'])->fails();
        $fluent = RuleSet::from(['items.*.forbidden' => FluentRule::field()->prohibited()])
            ->check(['items' => [$shape]])
            ->fails();
        expect($fluent)->toBe($native, 'shape=' . json_encode($shape));
    }
});

it('bare prohibited: top-level fast-check also works', function (): void {
    $ruleSet = RuleSet::from(['forbidden' => FluentRule::field()->prohibited()]);

    expect($ruleSet->check(['forbidden' => 'X'])->fails())->toBeTrue()
        ->and($ruleSet->check(['forbidden' => null])->passes())->toBeTrue()
        ->and($ruleSet->check([])->passes())->toBeTrue();
});
