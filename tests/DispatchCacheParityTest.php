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
