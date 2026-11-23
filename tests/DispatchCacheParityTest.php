<?php declare(strict_types=1);

use SanderMuller\FluentValidation\RuleSet;

// =========================================================================
// ItemValidator reduces each wildcard item's rules once per distinct value of
// the field every exclude condition reads (the dispatch field). That cache is
// safe only when every presence / value conditional reads that field too, and
// when the cache key separates values that reduce differently.
// =========================================================================

/**
 * @param  array<string, mixed>  $rules
 * @param  array<mixed>  $items
 */
function assertDispatchParity(array $rules, array $items): void
{
    $data = ['items' => $items];

    expect(RuleSet::from($rules)->check($data)->errors()->keys())
        ->toBe(validator($data, $rules)->errors()->keys());
}

it('keeps required_if on the dispatch field correct per item', function (): void {
    assertDispatchParity(
        [
            'items.*.type' => 'nullable',
            'items.*.url' => ['exclude_unless:items.*.type,image,video', 'required_if:items.*.type,image', 'nullable', 'string'],
        ],
        [['type' => 'image'], ['type' => 'video'], ['type' => 'image', 'url' => 'x'], ['type' => 'image']],
    );
});

it('keeps required_with on the dispatch field correct per item', function (): void {
    assertDispatchParity(
        [
            'items.*.type' => 'nullable',
            'items.*.url' => ['exclude_if:items.*.type,skip', 'required_with:items.*.type', 'nullable', 'string'],
        ],
        [['type' => 'a'], [], ['type' => null], ['type' => ''], ['type' => 'a', 'url' => 'x']],
    );
});

it('separates dispatch values that differ only in type', function (string $value, array $items): void {
    assertDispatchParity(
        [
            'items.*.flag' => 'nullable',
            'items.*.note' => ['exclude_if:items.*.flag,x', "required_if:items.*.flag,{$value}", 'nullable', 'string'],
        ],
        $items,
    );
})->with([
    'absent, then null' => ['null', [[], ['flag' => null]]],
    'empty string, then null' => ['null', [['flag' => ''], ['flag' => null]]],
    'empty string, then false' => ['false', [['flag' => ''], ['flag' => false]]],
    'absent, then false' => ['false', [[], ['flag' => false]]],
]);

it('keeps reducing per item when a conditional reads another field', function (): void {
    assertDispatchParity(
        [
            'items.*.type' => 'nullable',
            'items.*.kind' => 'nullable',
            'items.*.url' => ['exclude_unless:items.*.type,image', 'required_if:items.*.kind,full', 'nullable', 'string'],
        ],
        [['type' => 'image', 'kind' => 'full'], ['type' => 'image', 'kind' => 'thin'], ['type' => 'image', 'kind' => 'full']],
    );
});

it('reduces tuple-form conditionals like native', function (array $urlRule, array $items, ?string $message): void {
    $data = ['items' => $items];
    $rules = ['items.*.type' => 'nullable', 'items.*.flag' => 'boolean', 'items.*.url' => $urlRule];
    $messages = $message === null ? [] : ['items.*.url.required_if' => $message];

    expect(RuleSet::from($rules)->check($data, $messages)->errors()->keys())
        ->toBe(validator($data, $rules, $messages)->errors()->keys());
})->with([
    'required_if on the dispatch field' => [
        [['exclude_unless', 'items.*.type', 'image', 'video'], ['required_if', 'items.*.type', 'image'], 'nullable', 'string'],
        [['type' => 'image'], ['type' => 'video'], ['type' => 'image', 'url' => 'x']],
        null,
    ],
    'required_if with a custom message' => [
        [['required_if', 'items.*.type', 'image'], 'string'],
        [['type' => 'image'], ['type' => 'video']],
        'Image rows need a url.',
    ],
    'required_with' => [
        [['required_with', 'items.*.type'], 'string'],
        [['type' => 'a'], []],
        null,
    ],
    'value with a comma stays with Laravel' => [
        [['required_if', 'items.*.type', 'a,b'], 'string'],
        [['type' => 'a,b'], ['type' => 'a']],
        null,
    ],
    'boolean dependency sent as "1"' => [
        [['required_if', 'items.*.flag', 'true'], 'string'],
        [['flag' => '1'], ['flag' => '0']],
        null,
    ],
]);

// =========================================================================
// Without one dispatch field, ItemValidator reduces each item's rules once per
// distinct exclude outcome and value of each field a presence or value
// conditional reads. Items that differ in either must not share a reduction.
// =========================================================================

/**
 * @param  array<string, mixed>  $rules
 * @param  array<mixed>  $items
 */
function assertReducedRulesParity(array $rules, array $items): void
{
    $rules = ['items' => 'array', ...$rules];
    $data = ['items' => $items];
    $native = validator($data, $rules);
    $result = RuleSet::from($rules)->check($data);

    $expected = $native->errors()->toArray();
    $actual = $result->errors()->toArray();
    ksort($expected);
    ksort($actual);

    expect($actual)->toBe($expected);

    if (! $native->fails()) {
        expect($result->validated())->toEqual($native->validated());
    }
}

it('reduces items with the same dependency values and other exclude outcomes apart', function (array $items): void {
    assertReducedRulesParity(
        [
            'items.*.type' => 'nullable',
            'items.*.mode' => 'nullable',
            'items.*.kind' => 'nullable',
            'items.*.url' => ['exclude_unless:items.*.type,image', 'required_if:items.*.kind,full', 'nullable', 'string'],
            'items.*.size' => ['exclude_if:items.*.mode,off', 'required_if:items.*.kind,full', 'integer'],
        ],
        $items,
    );
})->with([
    'invalid' => [[
        ['type' => 'image', 'mode' => 'on', 'kind' => 'full', 'size' => 'x'],
        ['type' => 'image', 'mode' => 'off', 'kind' => 'full', 'size' => 'x'],
        ['type' => 'video', 'mode' => 'on', 'kind' => 'full', 'size' => 'x', 'url' => 5],
        ['type' => 'image', 'mode' => 'on', 'kind' => 'full', 'size' => 'x'],
    ]],
    'valid' => [[
        ['type' => 'image', 'mode' => 'off', 'kind' => 'full', 'url' => 'a', 'size' => 'x'],
        ['type' => 'video', 'mode' => 'on', 'kind' => 'full', 'url' => 5, 'size' => 3],
        ['type' => 'image', 'mode' => 'on', 'kind' => 'full', 'url' => 'b', 'size' => 4],
        ['type' => 'video', 'mode' => 'off', 'kind' => 'thin', 'url' => 5, 'size' => 'x'],
    ]],
]);

it('reduces items apart when an exclude condition reads a dependency field', function (array $items): void {
    assertReducedRulesParity(
        [
            'items.*.type' => 'nullable',
            'items.*.mode' => 'nullable',
            'items.*.url' => ['exclude_unless:items.*.type,image', 'nullable', 'string'],
            'items.*.alt' => ['exclude_if:items.*.mode,off', 'required_if:items.*.type,image', 'string'],
        ],
        $items,
    );
})->with([
    'invalid' => [[
        ['type' => 'image', 'mode' => 'on'],
        ['type' => 'video', 'mode' => 'on', 'url' => 5],
        ['type' => 'image', 'mode' => 'off', 'url' => 5],
        ['type' => 'video', 'mode' => 'off', 'alt' => 5],
    ]],
    'valid' => [[
        ['type' => 'image', 'mode' => 'on', 'alt' => 'a', 'url' => 'u'],
        ['type' => 'video', 'mode' => 'on', 'url' => 5],
        ['type' => 'image', 'mode' => 'off', 'url' => 'u', 'alt' => 5],
        ['type' => 'video', 'mode' => 'off', 'url' => 5, 'alt' => 5],
    ]],
]);

it('reduces items apart when dependency values differ only in type', function (string $value, array $items): void {
    assertReducedRulesParity(
        [
            'items.*.mode' => 'nullable',
            'items.*.kind' => 'nullable',
            'items.*.note' => ['exclude_if:items.*.mode,off', "required_if:items.*.kind,{$value}", 'nullable', 'string'],
            'items.*.size' => ['exclude_if:items.*.kind,skip', 'nullable', 'integer'],
        ],
        $items,
    );
})->with([
    'absent, then null' => ['null', [['mode' => 'on'], ['mode' => 'on', 'kind' => null]]],
    'empty string, then null' => ['null', [['mode' => 'on', 'kind' => ''], ['mode' => 'on', 'kind' => null]]],
    'empty string, then false' => ['false', [['mode' => 'on', 'kind' => ''], ['mode' => 'on', 'kind' => false]]],
    'integer, then string' => ['1', [['mode' => 'on', 'kind' => 1], ['mode' => 'on', 'kind' => '1'], ['mode' => 'on', 'kind' => 'x']]],
]);

it('reduces each item on its own when a dependency is not a scalar or not a top-level key', function (array $noteRule, array $sizeRule, array $items): void {
    assertReducedRulesParity(
        ['items.*.mode' => 'nullable', 'items.*.kind' => 'nullable', 'items.*.meta' => 'nullable|array', 'items.*.meta.kind' => 'nullable', 'items.*.note' => $noteRule, 'items.*.size' => $sizeRule],
        $items,
    );
})->with([
    'an array value' => [
        ['exclude_if:items.*.mode,off', 'required_with:items.*.kind', 'string'],
        ['exclude_if:items.*.meta.kind,skip', 'integer'],
        [['mode' => 'on', 'kind' => ['a']], ['mode' => 'on', 'kind' => ['b'], 'note' => 'n'], ['mode' => 'off', 'kind' => ['a']]],
    ],
    'a nested dependency' => [
        ['exclude_if:items.*.mode,off', 'required_if:items.*.meta.kind,full', 'string'],
        ['exclude_if:items.*.kind,skip', 'integer'],
        [['mode' => 'on', 'meta' => ['kind' => 'thin']], ['mode' => 'on', 'meta' => ['kind' => 'full']], ['mode' => 'on', 'meta' => ['kind' => 'full'], 'note' => 'n']],
    ],
]);
