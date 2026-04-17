<?php declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use SanderMuller\FluentValidation\FastCheckCompiler;

/**
 * Parity suite: for every fast-checkable rule, the compiled closure's
 * pass/fail verdict MUST match Laravel's validator across a grid of
 * edge values (null, '', [], scalars, type mismatches). A drift here
 * means the fast path silently accepts input Laravel would reject
 * (or the reverse) — the same class of bug as the `filled` regression.
 *
 * Only value-present cases are tested. The fast-check wrapper handles
 * absence via `array_key_exists` before the closure runs, so absence
 * isn't the closure's responsibility.
 */

/** @return list<mixed> */
function parityValues(): array
{
    return [
        null,
        '',
        '0',
        '1',
        'abc',
        'abcdef',
        'a@b.co',
        '2026-01-01',
        0,
        1,
        5,
        -1,
        true,
        false,
        [],
        ['a'],
        ['a', 'b'],
        ['a', 'b', 'c', 'd', 'e', 'f'],
    ];
}

/** @return list<string> */
function parityRules(): array
{
    return [
        'required',
        'string',
        'string|max:5',
        'string|min:2',
        'string|min:2|max:5',
        'numeric',
        'numeric|min:1',
        'numeric|max:10',
        'integer',
        'integer|min:1',
        'boolean',
        'array',
        'array|min:1',
        'array|max:2',
        'email',
        'url',
        'ip',
        'uuid',
        'ulid',
        'date',
        'in:a,b,c',
        'not_in:x,y',
        'alpha',
        'alpha_dash',
        'alpha_num',
        'accepted',
        'declined',
        'regex:/^[a-z]+$/',
        'required|string',
        'required|array|min:1',
        'required|integer|min:1|max:10',
        'nullable|string|max:5',
        'nullable|accepted',
        'nullable|declined',
        'nullable|required',
        'sometimes|string',
        'sometimes|required|string',
        'date|after:2025-01-01',
        'date|before:2030-01-01',
        'date_format:Y-m-d',
        'not_regex:/[a-z]+/',
    ];
}

/** @return list<array{0: string, 1: mixed}> */
function parityGrid(): array
{
    $grid = [];

    foreach (parityRules() as $rule) {
        foreach (parityValues() as $value) {
            $grid[] = [$rule, $value];
        }
    }

    return $grid;
}

it('fast-check closure verdict matches Laravel validator', function (string $rule, mixed $value): void {
    $closure = FastCheckCompiler::compile($rule);

    if (! $closure instanceof Closure) {
        // Rule not fast-checkable — nothing to compare.
        expect(true)->toBeTrue();

        return;
    }

    $fastResult = $closure($value);
    $laravelResult = Validator::make(['f' => $value], ['f' => $rule])->passes();

    expect($fastResult)->toBe(
        $laravelResult,
        sprintf(
            'Parity drift for rule "%s" with value %s: fast=%s, Laravel=%s',
            $rule,
            var_export($value, true),
            $fastResult ? 'pass' : 'fail',
            $laravelResult ? 'pass' : 'fail',
        ),
    );
})->with(parityGrid());

/**
 * Parity grid for item-aware date field-ref rules. The closure receives both
 * the target value and the item array (so `after:start_date` can resolve).
 *
 * @return iterable<string, array{string, mixed, array<string, mixed>}>
 */
function itemAwareDateParityGrid(): iterable
{
    $rules = [
        'required|date|after:start_date',
        'required|date|before:start_date',
        'required|date|after_or_equal:start_date',
        'required|date|before_or_equal:start_date',
        'required|date|date_equals:start_date',
        'nullable|date|after:start_date',
        'nullable|date|before:start_date',
        'nullable|date|after_or_equal:start_date',
        'nullable|date|before_or_equal:start_date',
        'nullable|date|date_equals:start_date',
    ];

    $items = [
        'both-valid-after' => ['value' => '2030-06-05', 'start_date' => '2030-06-01'],
        'both-valid-before' => ['value' => '2030-05-15', 'start_date' => '2030-06-01'],
        'both-valid-equal' => ['value' => '2030-06-01', 'start_date' => '2030-06-01'],
        'value-invalid-date' => ['value' => 'not-a-date', 'start_date' => '2030-06-01'],
        'ref-invalid-date' => ['value' => '2030-06-01', 'start_date' => 'not-a-date'],
        'value-null' => ['value' => null, 'start_date' => '2030-06-01'],
        'value-empty' => ['value' => '', 'start_date' => '2030-06-01'],
        'ref-null' => ['value' => '2030-06-01', 'start_date' => null],
        'ref-missing' => ['value' => '2030-06-01'],
    ];

    foreach ($rules as $rule) {
        foreach ($items as $itemLabel => $item) {
            $value = $item['value'] ?? null;
            yield "{$rule} :: {$itemLabel}" => [$rule, $value, $item];
        }
    }
}

it('item-aware fast-check verdict matches Laravel validator for date field-refs', function (string $rule, mixed $value, array $item): void {
    $closure = FastCheckCompiler::compileWithItemContext($rule);

    if (! $closure instanceof Closure) {
        // Rule not item-aware fast-checkable — skip.
        expect(true)->toBeTrue();

        return;
    }

    $fastResult = $closure($value, $item);

    // Laravel needs the full item context for field-ref rules.
    $laravelResult = Validator::make($item, ['value' => $rule])->passes();

    expect($fastResult)->toBe(
        $laravelResult,
        sprintf(
            'Parity drift for rule "%s" on item %s: fast=%s, Laravel=%s',
            $rule,
            json_encode($item, JSON_UNESCAPED_SLASHES),
            $fastResult ? 'pass' : 'fail',
            $laravelResult ? 'pass' : 'fail',
        ),
    );
})->with(itemAwareDateParityGrid());

/**
 * Parity grid for item-aware `same:FIELD` and `different:FIELD` rules.
 * Laravel's `validateSame` / `validateDifferent` use strict `===` / `!==`
 * against the referenced field resolved via `Arr::get($data, FIELD)`.
 *
 * @return iterable<string, array{string, mixed, array<string, mixed>}>
 */
function itemAwareSameDifferentParityGrid(): iterable
{
    $rules = [
        'required|same:other',
        'required|different:other',
        'nullable|same:other',
        'nullable|different:other',
        'required|string|same:other',
        'required|string|different:other',
    ];

    $items = [
        'equal-strings' => ['value' => 'foo', 'other' => 'foo'],
        'different-strings' => ['value' => 'foo', 'other' => 'bar'],
        'equal-ints' => ['value' => 7, 'other' => 7],
        'int-vs-string' => ['value' => 1, 'other' => '1'],
        'string-vs-int' => ['value' => '1', 'other' => 1],
        'both-null' => ['value' => null, 'other' => null],
        'value-null-other-string' => ['value' => null, 'other' => 'foo'],
        'value-string-other-null' => ['value' => 'foo', 'other' => null],
        'value-empty' => ['value' => '', 'other' => 'foo'],
        'other-missing' => ['value' => 'foo'],
        'value-and-other-empty' => ['value' => '', 'other' => ''],
    ];

    foreach ($rules as $rule) {
        foreach ($items as $itemLabel => $item) {
            $value = $item['value'] ?? null;
            yield "{$rule} :: {$itemLabel}" => [$rule, $value, $item];
        }
    }
}

it('item-aware fast-check verdict matches Laravel validator for same/different field-refs', function (string $rule, mixed $value, array $item): void {
    $closure = FastCheckCompiler::compileWithItemContext($rule);

    if (! $closure instanceof Closure) {
        // Rule not yet item-aware fast-checkable — skip (implementation pending).
        expect(true)->toBeTrue();

        return;
    }

    $fastResult = $closure($value, $item);
    $laravelResult = Validator::make($item, ['value' => $rule])->passes();

    expect($fastResult)->toBe(
        $laravelResult,
        sprintf(
            'Parity drift for rule "%s" on item %s: fast=%s, Laravel=%s',
            $rule,
            json_encode($item, JSON_UNESCAPED_SLASHES),
            $fastResult ? 'pass' : 'fail',
            $laravelResult ? 'pass' : 'fail',
        ),
    );
})->with(itemAwareSameDifferentParityGrid());

/**
 * Targeted assertion that drives the implementation: compileWithItemContext
 * MUST return a closure for `same:FIELD` / `different:FIELD` rules once
 * support lands. Until then this test fails, keeping the scope honest.
 */
it('compileWithItemContext compiles same:FIELD and different:FIELD rules', function (): void {
    expect(FastCheckCompiler::compileWithItemContext('required|same:other'))
        ->toBeInstanceOf(Closure::class)
        ->and(FastCheckCompiler::compileWithItemContext('required|different:other'))->toBeInstanceOf(Closure::class)
        ->and(FastCheckCompiler::compileWithItemContext('nullable|same:password_confirmation'))->toBeInstanceOf(Closure::class);

    // Multi-param `different:a,b` is not (yet) fast-checkable — must bail.
    expect(FastCheckCompiler::compileWithItemContext('required|different:a,b'))
        ->toBeNull();
});

it('compileWithItemContext returns null for rules that have no date comparison or same/different', function (string $rule): void {
    // Pre-filter guard: the item-aware path only triggers for rules containing
    // date-ref markers (`after:`, `before:`, `date_equals:`) or equality-ref
    // markers (`same:`, `different:`). Everything else bails early so the
    // caller (RuleSet::buildFastChecks) doesn't pay for a redundant parse.
    expect(FastCheckCompiler::compileWithItemContext($rule))->toBeNull();
})->with([
    'plain string rule' => ['required|string|max:255'],
    'numeric rule' => ['required|numeric|min:0'],
    'email rule' => ['nullable|email'],
    'in-list rule' => ['required|in:a,b,c'],
    'regex rule' => ['required|regex:/^[a-z]+$/'],
    'integer with size' => ['required|integer|min:1|max:100'],
]);

it('compileWithItemContext compiles only when a field-ref is present', function (): void {
    // Positive: contains a date field-ref → non-null closure returned.
    expect(FastCheckCompiler::compileWithItemContext('required|date|after:start_date'))
        ->toBeInstanceOf(Closure::class);

    // Positive: literal date still compiles (same path handles both).
    expect(FastCheckCompiler::compileWithItemContext('required|date|after:2025-01-01'))
        ->toBeInstanceOf(Closure::class);

    // Negative: unknown rule part still bails, even with field-ref context.
    expect(FastCheckCompiler::compileWithItemContext('required|date|after:start_date|custom_unknown_rule'))
        ->toBeNull();
});

/**
 * Parity grid for the `confirmed` rule, which rewrites to
 * `same:${attr}_confirmation` at compile time (or `same:X` when written
 * as `confirmed:X`). Without the attribute name the rule can't be
 * fast-checked.
 *
 * @return iterable<string, array{string, string, mixed, array<string, mixed>}>
 */
function itemAwareConfirmedParityGrid(): iterable
{
    // [rule, attribute name, value, item]
    $cases = [
        'default match' => [
            'required|confirmed', 'password',
            'hunter2', ['password' => 'hunter2', 'password_confirmation' => 'hunter2'],
        ],
        'default mismatch' => [
            'required|confirmed', 'password',
            'hunter2', ['password' => 'hunter2', 'password_confirmation' => 'hunter3'],
        ],
        'default confirmation missing' => [
            'required|confirmed', 'password',
            'hunter2', ['password' => 'hunter2'],
        ],
        'default confirmation null' => [
            'required|confirmed', 'password',
            'hunter2', ['password' => 'hunter2', 'password_confirmation' => null],
        ],
        'custom name match' => [
            'required|confirmed:check', 'pwd',
            'hunter2', ['pwd' => 'hunter2', 'check' => 'hunter2'],
        ],
        'custom name mismatch' => [
            'required|confirmed:check', 'pwd',
            'hunter2', ['pwd' => 'hunter2', 'check' => 'hunter3'],
        ],
        'nullable value null' => [
            'nullable|confirmed', 'password',
            null, ['password' => null],
        ],
    ];

    foreach ($cases as $label => [$rule, $attr, $value, $item]) {
        yield "{$rule} on {$attr} :: {$label}" => [$rule, $attr, $value, $item];
    }
}

it('item-aware fast-check verdict matches Laravel validator for confirmed rule', function (string $rule, string $attr, mixed $value, array $item): void {
    $closure = FastCheckCompiler::compileWithItemContext($rule, $attr);

    if (! $closure instanceof Closure) {
        expect(true)->toBeTrue();

        return;
    }

    $fastResult = $closure($value, $item);

    // Laravel sees the rule under the attribute name — the attribute's key
    // in $item is what drives the `${attr}_confirmation` lookup.
    $laravelResult = Validator::make($item, [$attr => $rule])->passes();

    expect($fastResult)->toBe(
        $laravelResult,
        sprintf(
            'Parity drift for rule "%s" on attr "%s" with item %s: fast=%s, Laravel=%s',
            $rule,
            $attr,
            json_encode($item, JSON_UNESCAPED_SLASHES),
            $fastResult ? 'pass' : 'fail',
            $laravelResult ? 'pass' : 'fail',
        ),
    );
})->with(itemAwareConfirmedParityGrid());

/**
 * Targeted assertion for the `confirmed` compile path. Drives the
 * implementation: compileWithItemContext with an attribute name MUST
 * return a closure for `confirmed` / `confirmed:X`; without an attribute
 * name it MUST bail (the rule can't be fast-checked without knowing the
 * field it applies to).
 */
it('compileWithItemContext compiles confirmed rule only when attribute name is provided', function (): void {
    // Positive: with attribute name → closure.
    expect(FastCheckCompiler::compileWithItemContext('required|confirmed', 'password'))
        ->toBeInstanceOf(Closure::class);

    expect(FastCheckCompiler::compileWithItemContext('required|confirmed:pwd_check', 'pwd'))
        ->toBeInstanceOf(Closure::class);

    // Negative: without attribute name → null.
    expect(FastCheckCompiler::compileWithItemContext('required|confirmed'))
        ->toBeNull();
});

/**
 * Parity grid for `gt:FIELD` / `gte:FIELD` / `lt:FIELD` / `lte:FIELD`.
 * Laravel compares via `getSize($attribute, $value)`, which:
 *   - for `numeric`/`integer` rule + numeric value: uses the numeric value
 *   - for `array` value: uses count()
 *   - else: falls through to `mb_strlen((string) $value)`
 *
 * Our closure must mirror that coercion exactly for the ref side, since
 * the value side is already guaranteed type-valid by the type rule that
 * runs before `gt:` (otherwise the type rule fails and the whole closure
 * returns false).
 *
 * @return iterable<string, array{string, mixed, array<string, mixed>}>
 */
function itemAwareSizeComparisonParityGrid(): iterable
{
    $rules = [
        // Numeric family
        'required|numeric|gt:other',
        'required|numeric|gte:other',
        'required|numeric|lt:other',
        'required|numeric|lte:other',
        'required|integer|gt:other',
        'required|integer|lte:other',

        // String family — compares lengths
        'required|string|gt:other',
        'required|string|gte:other',
        'required|string|lt:other',
        'required|string|lte:other',

        // Array family — compares counts
        'required|array|gt:other',
        'required|array|gte:other',
        'required|array|lt:other',
        'required|array|lte:other',

        // Nullable combos
        'nullable|numeric|gt:other',
        'nullable|string|lte:other',
    ];

    $items = [
        // Numeric shapes
        'num:5-vs-3' => ['value' => 5, 'other' => 3],
        'num:3-vs-5' => ['value' => 3, 'other' => 5],
        'num:5-vs-5' => ['value' => 5, 'other' => 5],
        'num:5-vs-str5' => ['value' => 5, 'other' => '5'],
        'num:5-vs-abc' => ['value' => 5, 'other' => 'abc'],  // ref falls through to mb_strlen
        'num:5-vs-null' => ['value' => 5, 'other' => null],
        'num:5-vs-missing' => ['value' => 5],

        // String shapes (lengths)
        'str:hello-vs-hi' => ['value' => 'hello', 'other' => 'hi'],      // 5 vs 2
        'str:hi-vs-hello' => ['value' => 'hi', 'other' => 'hello'],      // 2 vs 5
        'str:same-vs-same' => ['value' => 'same', 'other' => 'same'],    // 4 vs 4
        'str:hi-vs-null' => ['value' => 'hi', 'other' => null],          // 2 vs 0
        'str:hi-vs-array' => ['value' => 'hi', 'other' => ['a', 'b']],   // 2 vs count=2

        // Array shapes (counts)
        'arr:3-vs-1' => ['value' => [1, 2, 3], 'other' => [1]],
        'arr:empty-vs-1' => ['value' => [], 'other' => [1]],
        'arr:1-vs-1' => ['value' => [1], 'other' => [1]],
        'arr:2-vs-str3' => ['value' => [1, 2], 'other' => 'abc'],        // count 2 vs mb_strlen 3

        // Nullable-value-null (should skip on nullable + non-implicit).
        'null-value' => ['value' => null, 'other' => 3],
    ];

    foreach ($rules as $rule) {
        foreach ($items as $itemLabel => $item) {
            $value = $item['value'] ?? null;
            yield "{$rule} :: {$itemLabel}" => [$rule, $value, $item];
        }
    }
}

it('item-aware fast-check verdict matches Laravel validator for size comparisons', function (string $rule, mixed $value, array $item): void {
    $closure = FastCheckCompiler::compileWithItemContext($rule);

    if (! $closure instanceof Closure) {
        expect(true)->toBeTrue();

        return;
    }

    $fastResult = $closure($value, $item);
    $laravelResult = Validator::make($item, ['value' => $rule])->passes();

    expect($fastResult)->toBe(
        $laravelResult,
        sprintf(
            'Parity drift for rule "%s" on item %s: fast=%s, Laravel=%s',
            $rule,
            json_encode($item, JSON_UNESCAPED_SLASHES),
            $fastResult ? 'pass' : 'fail',
            $laravelResult ? 'pass' : 'fail',
        ),
    );
})->with(itemAwareSizeComparisonParityGrid());

/**
 * Targeted assertion that drives the implementation. Size comparison rules
 * (gt/gte/lt/lte with field-ref params) must fast-check when an explicit
 * type flag is present, and bail when the type is ambiguous — matching how
 * min/max are already handled by compile().
 */
it('compileWithItemContext compiles gt/gte/lt/lte with a type flag', function (): void {
    // Positive: explicit type flag → closure.
    expect(FastCheckCompiler::compileWithItemContext('required|numeric|gt:other'))
        ->toBeInstanceOf(Closure::class);
    expect(FastCheckCompiler::compileWithItemContext('required|string|lte:other'))
        ->toBeInstanceOf(Closure::class)
        ->and(FastCheckCompiler::compileWithItemContext('required|array|gte:other'))->toBeInstanceOf(Closure::class);

    // Negative: no type flag → can't decide how to size-compare → null.
    expect(FastCheckCompiler::compileWithItemContext('required|gt:other'))
        ->toBeNull();

    // Negative: multi-param `gt:a,b` is not valid Laravel syntax → bail.
    expect(FastCheckCompiler::compileWithItemContext('required|numeric|gt:a,b'))
        ->toBeNull();
});
