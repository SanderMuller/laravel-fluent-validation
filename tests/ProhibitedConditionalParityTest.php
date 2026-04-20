<?php declare(strict_types=1);

use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\RuleSet;
use Symfony\Component\HttpFoundation\File\File;

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

it('prohibited|required: contradictory — every value fails (match Laravel)', function (): void {
    // Raw rule string path — the closure's contradictory-combination branch
    // is what this exercises. `prohibited|required` is nonsensical (user error)
    // but fast-check must never pass when native Laravel fails.
    $ruleSet = RuleSet::from(['items.*.field' => 'prohibited|required']);

    foreach ([['field' => 'X'], ['field' => null], ['field' => ''], ['field' => []], []] as $shape) {
        $native = validator($shape, ['field' => 'prohibited|required'])->fails();
        $fluent = $ruleSet->check(['items' => [$shape]])->fails();
        expect($fluent)->toBe($native, 'shape=' . json_encode($shape));
    }
});

it('prohibited|accepted: contradictory — every value fails (match Laravel)', function (): void {
    $ruleSet = RuleSet::from(['items.*.field' => 'prohibited|accepted']);

    foreach ([['field' => 'yes'], ['field' => null], ['field' => ''], ['field' => 'random']] as $shape) {
        $native = validator($shape, ['field' => 'prohibited|accepted'])->fails();
        $fluent = $ruleSet->check(['items' => [$shape]])->fails();
        expect($fluent)->toBe($native, 'shape=' . json_encode($shape));
    }
});

it('prohibited|declined: contradictory — every value fails (match Laravel)', function (): void {
    $ruleSet = RuleSet::from(['items.*.field' => 'prohibited|declined']);

    foreach ([['field' => 'no'], ['field' => null], ['field' => ''], ['field' => 'random']] as $shape) {
        $native = validator($shape, ['field' => 'prohibited|declined'])->fails();
        $fluent = $ruleSet->check(['items' => [$shape]])->fails();
        expect($fluent)->toBe($native, 'shape=' . json_encode($shape));
    }
});

it('prohibited|string|max:10: empty value passes (string is non-implicit, Laravel skips it)', function (): void {
    // Laravel's `passesOptionalCheck`: non-implicit rules are skipped when the
    // value is absent. So `prohibited|string|max:10` with null lets prohibited
    // pass and skips string/max. Fast-check must match.
    $ruleSet = RuleSet::from(['items.*.field' => 'prohibited|string|max:10']);

    foreach ([['field' => null], [], ['field' => ''], ['field' => '   ']] as $shape) {
        $native = validator($shape, ['field' => 'prohibited|string|max:10'])->fails();
        $fluent = $ruleSet->check(['items' => [$shape]])->fails();
        expect($fluent)->toBe($native, 'shape=' . json_encode($shape));
    }

    // Non-empty value: prohibited fails regardless of string/max.
    $native = validator(['field' => 'X'], ['field' => 'prohibited|string|max:10'])->fails();
    expect($native)->toBeTrue()
        ->and($ruleSet->check(['items' => [['field' => 'X']]])->fails())->toBe($native);
});

it('prohibited|nullable: empty passes, non-empty fails', function (): void {
    $ruleSet = RuleSet::from(['items.*.field' => 'prohibited|nullable']);

    $native = validator([], ['field' => 'prohibited|nullable'])->fails();
    expect($ruleSet->check(['items' => [[]]])->fails())->toBe($native);

    $native = validator(['field' => 'X'], ['field' => 'prohibited|nullable'])->fails();
    expect($ruleSet->check(['items' => [['field' => 'X']]])->fails())->toBe($native);
});

// ---------- Item-aware path — prohibited + sibling-ref rules ---------------

it('prohibited|same:other: absent and explicit-null both match Laravel (slow-path fallback)', function (): void {
    $ruleSet = RuleSet::from(['items.*.field' => 'prohibited|same:other']);

    foreach ([[], ['other' => 'X'], ['field' => null, 'other' => 'X'], ['field' => 'X', 'other' => 'X']] as $shape) {
        $native = validator($shape, ['field' => 'prohibited|same:other'])->fails();
        $fluent = $ruleSet->check(['items' => [$shape]])->fails();
        expect($fluent)->toBe($native, 'shape=' . json_encode($shape));
    }
});

it('prohibited|different:other: absent and explicit-null both match Laravel', function (): void {
    $ruleSet = RuleSet::from(['items.*.field' => 'prohibited|different:other']);

    foreach ([[], ['other' => 'X'], ['field' => null, 'other' => 'X'], ['field' => 'Y', 'other' => 'X']] as $shape) {
        $native = validator($shape, ['field' => 'prohibited|different:other'])->fails();
        $fluent = $ruleSet->check(['items' => [$shape]])->fails();
        expect($fluent)->toBe($native, 'shape=' . json_encode($shape));
    }
});

it('prohibited|after:start: date-ref path matches Laravel for absent and explicit-null', function (): void {
    $ruleSet = RuleSet::from(['items.*.field' => 'prohibited|after:start']);

    foreach ([
        [],
        ['start' => '2026-01-01'],
        ['field' => null, 'start' => '2026-01-01'],
        ['field' => '2026-06-01', 'start' => '2026-01-01'],
    ] as $shape) {
        $native = validator($shape, ['field' => 'prohibited|after:start'])->fails();
        $fluent = $ruleSet->check(['items' => [$shape]])->fails();
        expect($fluent)->toBe($native, 'shape=' . json_encode($shape));
    }
});

// ---------- File / UploadedFile emptiness parity ---------------------------

it('bare prohibited: File with empty path counts as empty (match Laravel)', function (): void {
    // Laravel's validateRequired treats a File with empty path as absent.
    // Prohibited (inverse of required) should pass for such a file.
    // Symfony's File::__construct accepts `checkPath: false` so an empty path
    // doesn't trigger FileNotFoundException. `instanceof File` still holds.
    $file = new File('', checkPath: false);

    $ruleSet = RuleSet::from(['field' => FluentRule::field()->prohibited()]);
    $nativeFails = validator(['field' => $file], ['field' => 'prohibited'])->fails();
    $fluentFails = $ruleSet->check(['field' => $file])->fails();

    expect($fluentFails)->toBe($nativeFails);
});
