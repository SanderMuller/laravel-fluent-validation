<?php declare(strict_types=1);

use SanderMuller\FluentValidation\RuleSet;

// =========================================================================
// An item that fails a fast check goes to Laravel for its messages. With
// field-local rules, only the fields that failed go; a field that passed its
// fast check has no errors. Rules that read another field's rules keep all
// fields, so the errors stay the same as native.
// =========================================================================

/**
 * @param  array<string, mixed>  $rules
 * @param  array<mixed>  $items
 */
function assertFailedFieldParity(array $rules, array $items): void
{
    $data = ['items' => $items];

    expect(RuleSet::from($rules)->check($data)->errors()->keys())
        ->toBe(validator($data, $rules)->errors()->keys());
}

it('reports the same errors as native when some fields of an item fail', function (array $item): void {
    assertFailedFieldParity([
        'items.*.name' => 'required|string|max:5',
        'items.*.qty' => 'required|integer|min:1',
        'items.*.tag' => ['nullable', 'string', 'in:a,b'],
        'items.*.code' => 'required|string|regex:/^C-/',
    ], [['name' => 'ok', 'qty' => 1, 'tag' => 'a', 'code' => 'C-1'], $item]);
})->with([
    'one field fails' => [['name' => ['x'], 'qty' => 1, 'tag' => 'a', 'code' => 'C-1']],
    'two fields fail' => [['name' => 'too long', 'qty' => 0, 'tag' => 'a', 'code' => 'C-1']],
    'every field fails' => [['name' => 1, 'qty' => 'x', 'tag' => 'z', 'code' => 'D']],
    'fields absent' => [['tag' => 'a']],
]);

it('keeps the rules of a field that a dependent rule reads', function (): void {
    // after:start parses start with start's date_format. Without start's
    // rules, Laravel parses 01/12/2025 as 12 January and the end passes.
    assertFailedFieldParity([
        'items.*.name' => 'required|string',
        'items.*.start' => 'date_format:d/m/Y',
        'items.*.end' => 'date_format:Y-m-d|after:items.*.start',
    ], [['name' => ['x'], 'start' => '01/12/2025', 'end' => '2025-06-01']]);
});

it('writes the messages for the failed fields only', function (): void {
    $result = RuleSet::from([
        'items.*.name' => 'required|string',
        'items.*.qty' => 'required|integer',
    ])->check(['items' => [['name' => ['x'], 'qty' => 2]]]);

    expect($result->errors()->toArray())->toBe(['items.0.name' => ['The name field must be a string.']]);
});
