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
