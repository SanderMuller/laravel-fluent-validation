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
 * @param  array<mixed>  $items  items as the request sends them
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
