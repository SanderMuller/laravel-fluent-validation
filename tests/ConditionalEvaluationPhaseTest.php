<?php declare(strict_types=1);

use SanderMuller\FluentValidation\Internal\ConditionalEvaluationPhase;
use SanderMuller\FluentValidation\Internal\ConditionalVerdict;

/**
 * Direct unit coverage for {@see ConditionalEvaluationPhase}. Previously
 * exercised only transitively via OptimizedValidator integration tests;
 * extracting the class made these branches reachable in isolation.
 */
it('indexes exclude_unless tuples', function (): void {
    $phase = new ConditionalEvaluationPhase();

    $rules = [
        'name' => [['exclude_unless', 'type', 'A', 'B'], 'string'],
        'age' => ['integer'],
    ];

    expect($phase->indexConditionalAttrs($rules))->toBe([
        'name' => [
            ['action' => 'exclude_unless', 'field' => 'type', 'values' => ['A', 'B']],
        ],
    ]);
});

it('indexes exclude_if tuples', function (): void {
    $phase = new ConditionalEvaluationPhase();

    $rules = [
        'name' => [['exclude_if', 'type', 'X']],
    ];

    expect($phase->indexConditionalAttrs($rules))->toBe([
        'name' => [
            ['action' => 'exclude_if', 'field' => 'type', 'values' => ['X']],
        ],
    ]);
});

it('skips non-conditional tuples', function (): void {
    $phase = new ConditionalEvaluationPhase();

    $rules = [
        'name' => [['required_if', 'other', 'Y']],
        'age' => 'integer',
    ];

    expect($phase->indexConditionalAttrs($rules))
        ->toBeEmpty();
});

it('skips malformed tuples (under 3 elements)', function (): void {
    $phase = new ConditionalEvaluationPhase();

    $rules = [
        'name' => [['exclude_unless', 'type']],
    ];

    expect($phase->indexConditionalAttrs($rules))
        ->toBeEmpty();
});

it('skips tuples with non-string field', function (): void {
    $phase = new ConditionalEvaluationPhase();

    $rules = [
        'name' => [['exclude_unless', 123, 'A']],
    ];

    expect($phase->indexConditionalAttrs($rules))
        ->toBeEmpty();
});

it('exclude_unless excludes when value not in list', function (): void {
    $phase = new ConditionalEvaluationPhase();
    $tuples = [
        ['action' => 'exclude_unless', 'field' => 'type', 'values' => ['A', 'B']],
    ];

    $excluded = $phase->evaluate('name', $tuples, fn (): string => 'C');

    expect($excluded)->toBe(ConditionalVerdict::Exclude);
});

it('exclude_unless does not exclude when value in list', function (): void {
    $phase = new ConditionalEvaluationPhase();
    $tuples = [
        ['action' => 'exclude_unless', 'field' => 'type', 'values' => ['A', 'B']],
    ];

    $excluded = $phase->evaluate('name', $tuples, fn (): string => 'A');

    expect($excluded)->toBe(ConditionalVerdict::NotExcluded);
});

it('exclude_if excludes when value in list', function (): void {
    $phase = new ConditionalEvaluationPhase();
    $tuples = [
        ['action' => 'exclude_if', 'field' => 'type', 'values' => ['X']],
    ];

    $excluded = $phase->evaluate('name', $tuples, fn (): string => 'X');

    expect($excluded)->toBe(ConditionalVerdict::Exclude);
});

it('exclude_if does not exclude when value not in list', function (): void {
    $phase = new ConditionalEvaluationPhase();
    $tuples = [
        ['action' => 'exclude_if', 'field' => 'type', 'values' => ['X']],
    ];

    $excluded = $phase->evaluate('name', $tuples, fn (): string => 'Y');

    expect($excluded)->toBe(ConditionalVerdict::NotExcluded);
});

it('caches getValue lookups across tuples in one evaluate call', function (): void {
    $phase = new ConditionalEvaluationPhase();
    $callCount = 0;
    $getValue = function () use (&$callCount): string {
        ++$callCount;

        return 'A';
    };

    $tuples = [
        ['action' => 'exclude_unless', 'field' => 'type', 'values' => ['A']],
    ];

    $phase->evaluate('first', $tuples, $getValue);
    $phase->evaluate('second', $tuples, $getValue);

    expect($callCount)->toBe(1);
});

it('defers non-scalar dependent values to the validator instead of pre-deciding', function (): void {
    // A non-scalar (array/null/bool) dependent needs Laravel's own coercion to
    // evaluate correctly, so the pre-eval phase must NOT exclude on it — it
    // returns false and leaves the rule for the validator to resolve.
    $phase = new ConditionalEvaluationPhase();
    $tuples = [
        ['action' => 'exclude_if', 'field' => 'type', 'values' => ['']],
    ];

    $excluded = $phase->evaluate('name', $tuples, fn (): array => ['array', 'is', 'not', 'scalar']);

    expect($excluded)->toBe(ConditionalVerdict::Defer);
});

it('resolveWildcard splices indices into condition field', function (): void {
    $resolved = ConditionalEvaluationPhase::resolveWildcard(
        'interactions.5.style.top',
        'interactions.*.type',
    );

    expect($resolved)->toBe('interactions.5.type');
});

it('resolveWildcard leaves * in place when the position has no attribute segment', function (): void {
    // The '*' sits at index 1, but the attribute has only one segment — nothing
    // to map it to, so it stays a wildcard and the caller defers.
    $resolved = ConditionalEvaluationPhase::resolveWildcard(
        'plain',
        'other.*.type',
    );

    expect($resolved)->toBe('other.*.type');
});

it('resolveWildcard maps * to an associative key at the same position', function (): void {
    // Mixed associative + numeric path: the '*' must resolve to the associative
    // parent key ('foo'), not be back-filled from an unrelated numeric descendant.
    $resolved = ConditionalEvaluationPhase::resolveWildcard(
        'items.foo.rows.0.extra',
        'items.*.type',
    );

    expect($resolved)->toBe('items.foo.type');
});

it('resolveWildcard handles multi-level wildcards', function (): void {
    $resolved = ConditionalEvaluationPhase::resolveWildcard(
        'a.3.b.7.c',
        'a.*.b.*.flag',
    );

    expect($resolved)->toBe('a.3.b.7.flag');
});

it('evaluate resolves wildcard before lookup', function (): void {
    $phase = new ConditionalEvaluationPhase();
    $captured = null;
    $getValue = function (string $field) use (&$captured): string {
        $captured = $field;

        return 'X';
    };

    $tuples = [
        ['action' => 'exclude_if', 'field' => 'items.*.flag', 'values' => ['X']],
    ];

    $phase->evaluate('items.2.name', $tuples, $getValue);

    expect($captured)->toBe('items.2.flag');
});
