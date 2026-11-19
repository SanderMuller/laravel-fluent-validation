<?php declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\RuleSet;

/**
 * validate(), check() and safe() return what Laravel's validated() returns.
 *
 * @param  array<string, string>  $rules
 * @param  array<string, mixed>  $data
 */
function expectNativeValidated(array $rules, array $data): void
{
    $expected = Validator::make($data, $rules)->validated();
    $result = RuleSet::from($rules)->check($data);

    expect(RuleSet::from($rules)->validate($data))->toEqual($expected)
        ->and($result->validated())->toEqual($expected)
        ->and($result->safe()->all())->toEqual($expected);
}

it('returns the validated wildcard data for only wildcard keys', function (): void {
    expectNativeValidated(
        ['items.*.name' => 'required|string'],
        ['items' => [['name' => 'a', 'x' => 1], ['name' => 'b']], 'other' => 1],
    );
});

it('returns the validated wildcard data for a top-level rule next to the wildcard', function (): void {
    expectNativeValidated(
        ['other' => 'integer', 'items.*.name' => 'required|string'],
        ['items' => [['name' => 'a', 'x' => 1]], 'other' => 1, 'extra' => 2],
    );
});

it('returns the validated wildcard data for scalar items', function (): void {
    expectNativeValidated(
        ['tags.*' => 'string'],
        ['tags' => ['a', 'b'], 'other' => 1],
    );
});

it('returns the validated wildcard data for nested wildcards', function (): void {
    expectNativeValidated(
        ['orders.*.lines.*.sku' => 'required|string'],
        ['orders' => [['id' => 1, 'lines' => [['sku' => 'A', 'qty' => 2], ['sku' => 'B']]]]],
    );
});

it('returns the validated wildcard data for an item without the optional key', function (): void {
    expectNativeValidated(
        ['items.*.name' => 'sometimes|string', 'items.*.note' => 'nullable|string'],
        ['items' => [['name' => 'a', 'note' => null], ['x' => 1]]],
    );
});

it('returns the validated wildcard data for a dotted parent', function (): void {
    expectNativeValidated(
        ['order.lines.*.sku' => 'required|string'],
        ['order' => ['id' => 1, 'lines' => [['sku' => 'A', 'qty' => 2]]]],
    );
});

it('returns the validated wildcard data for an array item with child rules', function (): void {
    expectNativeValidated(
        ['items.*.meta' => 'array', 'items.*.meta.size' => 'integer'],
        ['items' => [['meta' => ['size' => 1, 'color' => 'red']]]],
    );
});

it('returns the validated wildcard data for FluentRule objects', function (): void {
    $data = ['items' => [['name' => 'a', 'x' => 1], ['name' => 'b']]];

    expect(RuleSet::from(['items.*.name' => FluentRule::string()->required()])->validate($data))
        ->toEqual(['items' => [['name' => 'a'], ['name' => 'b']]]);
});

it('leaves out wildcard fields that an exclude rule removes', function (): void {
    $rules = ['items.*.type' => 'required|string', 'items.*.size' => 'exclude_unless:items.*.type,box|integer'];
    $data = ['items' => [['type' => 'box', 'size' => 2], ['type' => 'bag', 'size' => 3]]];

    expect(RuleSet::from($rules)->validate($data))
        ->toEqual(Validator::make($data, $rules)->validated());
});

it('leaves out wildcard fields that an exclude rule in tuple form removes', function (): void {
    $rules = ['items.*.type' => ['required', 'string'], 'items.*.size' => [['exclude_unless', 'items.*.type', 'box'], 'integer']];
    $data = ['items' => [['type' => 'box', 'size' => 2], ['type' => 'bag', 'size' => 3]]];

    expect(RuleSet::from($rules)->validate($data))
        ->toEqual(Validator::make($data, $rules)->validated());
});

it('leaves out a wildcard parent that a top-level rule excludes', function (): void {
    expectNativeValidated(
        ['items' => 'exclude', 'items.*.name' => 'string'],
        ['items' => [['name' => 'a']]],
    );
});

it('leaves out a wildcard parent whose ancestor a top-level rule excludes', function (): void {
    expectNativeValidated(
        ['order' => 'exclude_if:skip,1', 'order.lines.*.sku' => 'string'],
        ['skip' => 1, 'order' => ['lines' => [['sku' => 'A']]]],
    );
});

it('keeps a validated array whose child wildcard matches no element', function (): void {
    expectNativeValidated(
        ['items.*.meta' => 'array', 'items.*.meta.*.x' => 'sometimes|integer'],
        ['items' => [['meta' => []], ['meta' => [['x' => 1, 'y' => 2]]], ['meta' => [[]]]]],
    );
});

it('leaves out a validated array whose fixed child key the item does not have', function (): void {
    expectNativeValidated(
        ['items.*.meta' => 'array', 'items.*.meta.size' => 'sometimes|integer'],
        ['items' => [['meta' => ['color' => 'red']]]],
    );
});

it('returns a wildcard field whose rule key escapes a dot', function (): void {
    expectNativeValidated(
        ['items.*.first\.name' => 'required|string'],
        ['items' => [['first.name' => 'Alice', 'x' => 1]]],
    );
});

it('keeps unvalidated array keys when the factory includes them', function (): void {
    Validator::includeUnvalidatedArrayKeys();

    try {
        expectNativeValidated(
            ['items.*.meta' => 'array', 'items.*.meta.size' => 'integer'],
            ['items' => [['meta' => ['size' => 1, 'color' => 'red']]]],
        );
    } finally {
        Validator::excludeUnvalidatedArrayKeys();
    }
});

it('leaves out wildcard fields that a conditional rule excludes', function (): void {
    $rules = ['items.*.type' => 'required|string', 'items.*.name' => [Rule::when(true, ['exclude']), 'string']];
    $data = ['items' => [['type' => 'box', 'name' => 'a']]];

    expect(RuleSet::from($rules)->validate($data))
        ->toEqual(Validator::make($data, $rules)->validated());
});

it('leaves out wildcard fields that a forEach rule excludes', function (): void {
    $rules = ['items.*.type' => 'required|string', 'items.*.name' => Rule::forEach(fn (): array => ['exclude'])];
    $data = ['items' => [['type' => 'box', 'name' => 'a']]];

    expect(RuleSet::from($rules)->validate($data))
        ->toEqual(Validator::make($data, $rules)->validated());
});

it('leaves out wildcard fields that an exclude rule in another spelling removes', function (): void {
    expectNativeValidated(
        ['items.*.type' => 'required|string', 'items.*.name' => 'Exclude|string', 'items.*.size' => 'excludeIf:items.*.type,bag|integer'],
        ['items' => [['type' => 'bag', 'name' => 'a', 'size' => 1]]],
    );
});
