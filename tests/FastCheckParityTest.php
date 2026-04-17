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

it('compileWithItemContext returns null for rules that have no date comparison', function (string $rule): void {
    // Pre-filter guard: the item-aware path only triggers for rules containing
    // `after:`, `before:`, or `date_equals:`. Everything else bails early so
    // the caller (RuleSet::buildFastChecks) doesn't pay for a redundant parse.
    expect(FastCheckCompiler::compileWithItemContext($rule))->toBeNull();
})->with([
    'plain string rule' => ['required|string|max:255'],
    'numeric rule' => ['required|numeric|min:0'],
    'email rule' => ['nullable|email'],
    'in-list rule' => ['required|in:a,b,c'],
    'regex rule' => ['required|regex:/^[a-z]+$/'],
    'integer with size' => ['required|integer|min:1|max:100'],
]);

it('compileWithItemContext compiles only when a date field-ref is present', function (): void {
    // Positive: contains a date field-ref → non-null closure returned.
    expect(FastCheckCompiler::compileWithItemContext('required|date|after:start_date'))
        ->toBeInstanceOf(Closure::class);

    // Positive: literal date still compiles (same path handles both).
    expect(FastCheckCompiler::compileWithItemContext('required|date|after:2025-01-01'))
        ->toBeInstanceOf(Closure::class);

    // Negative: unknown rule part still bails, even with date context.
    expect(FastCheckCompiler::compileWithItemContext('required|date|after:start_date|custom_unknown_rule'))
        ->toBeNull();
});
