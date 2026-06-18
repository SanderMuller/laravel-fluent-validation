<?php declare(strict_types=1);

use SanderMuller\FluentValidation\FluentValidator;
use SanderMuller\FluentValidation\Internal\ConditionalValueMatcher;
use SanderMuller\FluentValidation\RuleSet;

// =========================================================================
// exclude_unless / exclude_if dependent-value coercion in RuleSet's per-item
// path (ItemRuleCompiler). It used to force-cast the dependent to a string and
// strict-compare, diverging from native Laravel on null and boolean dependents
// (e.g. `exclude_unless:state,null` with state=null, or `exclude_if:flag,true`
// with flag=true). It now matches through the shared ConditionalValueMatcher,
// reproducing Laravel's coercion. Each test asserts parity with native.
// =========================================================================

/**
 * @param  array<string, mixed>  $native
 * @param  array<string, mixed>  $fluent
 * @param  array<string, mixed>  $data
 */
function assertExcludeParity(array $native, array $fluent, array $data): void
{
    $n = validator($data, $native)->errors()->keys();
    sort($n);
    $f = RuleSet::from($fluent)->check($data)->errors()->keys();
    sort($f);

    expect($f)->toBe($n);
}

it('exclude_unless with null dependent matches native', function (): void {
    assertExcludeParity(
        ['items.*.detail' => ['exclude_unless:items.*.state,null', 'required', 'string']],
        ['items.*.detail' => [['exclude_unless', 'items.*.state', 'null'], 'required', 'string']],
        ['items' => [['state' => null]]], // state IS null → not excluded → required fires
    );
});

it('exclude_if with null dependent matches native', function (): void {
    assertExcludeParity(
        ['items.*.detail' => ['exclude_if:items.*.state,null', 'required', 'string']],
        ['items.*.detail' => [['exclude_if', 'items.*.state', 'null'], 'required', 'string']],
        ['items' => [['state' => null]]], // state IS null → excluded → no error
    );
});

it('exclude_if with boolean dependent matches native', function (): void {
    assertExcludeParity(
        [
            'items.*.flag' => ['boolean'],
            'items.*.name' => ['exclude_if:items.*.flag,true', 'required', 'string'],
        ],
        [
            'items.*.flag' => ['boolean'],
            'items.*.name' => [['exclude_if', 'items.*.flag', 'true'], 'required', 'string'],
        ],
        ['items' => [['flag' => true]]], // flag IS true → excluded → no error
    );
});

it('exclude_unless with boolean-false dependent matches native', function (): void {
    assertExcludeParity(
        [
            'items.*.flag' => ['boolean'],
            'items.*.name' => ['exclude_unless:items.*.flag,false', 'required', 'string'],
        ],
        [
            'items.*.flag' => ['boolean'],
            'items.*.name' => [['exclude_unless', 'items.*.flag', 'false'], 'required', 'string'],
        ],
        ['items' => [['flag' => false]]], // flag IS false → not excluded → required fires
    );
});

it('exclude_unless still loose-matches numeric strings (no regression)', function (): void {
    assertExcludeParity(
        ['items.*.x' => ['exclude_unless:items.*.n,1', 'required', 'string']],
        ['items.*.x' => [['exclude_unless', 'items.*.n', '1'], 'required', 'string']],
        ['items' => [['n' => 1]]], // int 1 ↔ '1' loose match → not excluded → required fires
    );
});

it('exclude_unless:flag,false with a boolean dep sent as "0" matches native', function (): void {
    // flag declared boolean, raw input '0' → PHP-loose '0' == false → condition
    // matches → NOT excluded → required fires. String-cast comparison used to
    // miss this ('0' vs (string) false = '').
    assertExcludeParity(
        [
            'items.*.flag' => ['boolean'],
            'items.*.name' => ['exclude_unless:items.*.flag,false', 'required', 'string'],
        ],
        [
            'items.*.flag' => ['boolean'],
            'items.*.name' => [['exclude_unless', 'items.*.flag', 'false'], 'required', 'string'],
        ],
        ['items' => [['flag' => '0']]],
    );
});

it('exclude_if:flag,true with a boolean dep sent as "1" matches native', function (): void {
    assertExcludeParity(
        [
            'items.*.flag' => ['boolean'],
            'items.*.name' => ['exclude_if:items.*.flag,true', 'required', 'string'],
        ],
        [
            'items.*.flag' => ['boolean'],
            'items.*.name' => [['exclude_if', 'items.*.flag', 'true'], 'required', 'string'],
        ],
        ['items' => [['flag' => '1']]], // '1' == true → excluded → no error
    );
});

it('required_if:flag,true with a boolean dep sent as "1" matches native (shared matcher)', function (): void {
    // Exercises the same matcher via ValueConditionalReducer (required_*).
    assertExcludeParity(
        [
            'items.*.flag' => ['boolean'],
            'items.*.name' => ['required_if:items.*.flag,true', 'string'],
        ],
        [
            'items.*.flag' => ['boolean'],
            'items.*.name' => [['required_if', 'items.*.flag', 'true'], 'string'],
        ],
        ['items' => [['flag' => '1']]], // '1' == true → required → missing name errors
    );
});

it('ConditionalValueMatcher loose-matches boolean rule values against string inputs', function (): void {
    // boolean dep declared via itemRules → 'true'/'false' convert to bool, then
    // compare by truthiness against the raw string input.
    $boolRules = ['flag' => ['boolean']];

    expect(ConditionalValueMatcher::matches('flag', ['false'], ['flag' => '0'], $boolRules))->toBeTrue()
        ->and(ConditionalValueMatcher::matches('flag', ['false'], ['flag' => '1'], $boolRules))->toBeFalse()
        ->and(ConditionalValueMatcher::matches('flag', ['true'], ['flag' => '1'], $boolRules))->toBeTrue()
        ->and(ConditionalValueMatcher::matches('flag', ['true'], ['flag' => '0'], $boolRules))->toBeFalse();
});

it('exclude_if with an ABSENT dependent does not exclude (matches native)', function (): void {
    // Native's exclude_if is inactive when the dependent field is missing
    // (Arr::has short-circuit), so `name` stays required → error.
    assertExcludeParity(
        ['items.*.name' => ['exclude_if:items.*.flag,null', 'required', 'string']],
        ['items.*.name' => [['exclude_if', 'items.*.flag', 'null'], 'required', 'string']],
        ['items' => [['other' => 'x']]], // flag absent
    );
});

it('exclude_if with an EXPLICIT null dependent excludes (matches native)', function (): void {
    assertExcludeParity(
        ['items.*.name' => ['exclude_if:items.*.flag,null', 'required', 'string']],
        ['items.*.name' => [['exclude_if', 'items.*.flag', 'null'], 'required', 'string']],
        ['items' => [['flag' => null]]], // flag present and null → excluded → no error
    );
});

it('FluentValidator exclude_if with absent dependent keeps the field (validated parity)', function (): void {
    // The OptimizedValidator path (preExcludeRules + ConditionalEvaluationPhase)
    // must defer the absent-dependent exclude_if to native, not pre-exclude it.
    $data = ['items' => [['name' => 'present']]]; // flag absent

    $native = validator($data, [
        'items.*.name' => ['exclude_if:items.*.flag,null', 'string'],
    ])->validate();

    $validator = new class ($data, [
        'items.*.name' => [['exclude_if', 'items.*.flag', 'null'], 'string'],
    ]) extends FluentValidator {};

    expect($validator->validate())->toBe($native)
        ->and($native)->toBe(['items' => [['name' => 'present']]]); // name kept, not dropped
});

it('FluentValidator exclude_if boolean dep sent as "1" matches native (validated parity)', function (): void {
    // Missed-optimization case: even if pre-eval doesn't prune it, native must
    // still exclude correctly and the result must match.
    $data = ['items' => [['flag' => '1', 'name' => 'present']]];

    $native = validator($data, [
        'items.*.flag' => ['boolean'],
        'items.*.name' => ['exclude_if:items.*.flag,true', 'string'],
    ])->validate();

    $validator = new class ($data, [
        'items.*.flag' => ['boolean'],
        'items.*.name' => [['exclude_if', 'items.*.flag', 'true'], 'string'],
    ]) extends FluentValidator {};

    expect($validator->validate())->toBe($native); // name excluded in both
});

// ── F1: a field with multiple exclude_* conditions — all must be evaluated ──

it('evaluates ALL exclude conditions on a field, not just the first (second fires)', function (): void {
    // exclude_unless:type,a keeps (type=a); exclude_if:other,z then fires (other=z)
    // → field excluded. Honoring only the first condition would wrongly keep it.
    assertExcludeParity(
        ['items.*.x' => ['exclude_unless:items.*.type,a', 'exclude_if:items.*.other,z', 'required', 'string']],
        ['items.*.x' => [['exclude_unless', 'items.*.type', 'a'], ['exclude_if', 'items.*.other', 'z'], 'required', 'string']],
        ['items' => [['type' => 'a', 'other' => 'z']]],
    );
});

it('evaluates ALL exclude conditions on a field (none fires → kept)', function (): void {
    assertExcludeParity(
        ['items.*.x' => ['exclude_unless:items.*.type,a', 'exclude_if:items.*.other,z', 'required', 'string']],
        ['items.*.x' => [['exclude_unless', 'items.*.type', 'a'], ['exclude_if', 'items.*.other', 'z'], 'required', 'string']],
        ['items' => [['type' => 'a', 'other' => 'keep']]], // type=a (unless ok), other≠z → kept → required fires
    );
});

// ── F2: tuple-form required_if survives an exclude on the same field ──

it('keeps tuple-form required_if when a field survives its exclude condition', function (): void {
    // type=a → exclude_unless keeps the field; required_if(other,y) then fires
    // (other=y) → x required, missing → error. The required_if tuple must not be
    // dropped during exclude reduction.
    assertExcludeParity(
        ['items.*.x' => ['exclude_unless:items.*.type,a', 'required_if:items.*.other,y', 'string']],
        ['items.*.x' => [['exclude_unless', 'items.*.type', 'a'], ['required_if', 'items.*.other', 'y'], 'string']],
        ['items' => [['type' => 'a', 'other' => 'y']]], // x missing
    );
});

it('keeps tuple-form required_unless when a field survives its exclude condition', function (): void {
    assertExcludeParity(
        ['items.*.x' => ['exclude_unless:items.*.type,a', 'required_unless:items.*.other,skip', 'string']],
        ['items.*.x' => [['exclude_unless', 'items.*.type', 'a'], ['required_unless', 'items.*.other', 'skip'], 'string']],
        ['items' => [['type' => 'a', 'other' => 'go']]], // other≠skip → x required, missing → error
    );
});

it('ConditionalValueMatcher coerces null and bool like Laravel', function (): void {
    // null dependent ↔ 'null' literal
    expect(ConditionalValueMatcher::matches('state', ['null'], ['state' => null], []))->toBeTrue()
        ->and(ConditionalValueMatcher::matches('state', ['null'], ['state' => 'x'], []))->toBeFalse();

    // bool dependent ↔ 'true'/'false' literals
    expect(ConditionalValueMatcher::matches('flag', ['true'], ['flag' => true], []))->toBeTrue()
        ->and(ConditionalValueMatcher::matches('flag', ['true'], ['flag' => false], []))->toBeFalse();

    // numeric-string loose match
    expect(ConditionalValueMatcher::matches('n', ['1'], ['n' => 1], []))->toBeTrue();
});
