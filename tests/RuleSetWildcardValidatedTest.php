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

/**
 * A rule for the parent puts the parent in the validated data as the input
 * gave it, so the per-item validator must report the fields it excluded.
 *
 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}>
 */
function excludedUnderParentRuleCases(): array
{
    $data = ['items' => [['type' => 'bag', 'size' => 'x', 'name' => 'a'], ['type' => 'box', 'size' => 2, 'name' => 'b']]];
    $sizeExcluded = ['items' => [['type' => 'bag', 'name' => 'a'], ['type' => 'box', 'size' => 2, 'name' => 'b']]];

    return [
        'string form' => [['items' => 'required|array', 'items.*.name' => 'string', 'items.*.type' => 'required|string', 'items.*.size' => 'exclude_unless:items.*.type,box|integer'], $data, $sizeExcluded],
        'tuple form' => [['items' => ['required', 'array'], 'items.*.name' => 'string', 'items.*.type' => 'string', 'items.*.size' => [['exclude_unless', 'items.*.type', 'box'], 'integer']], $data, $sizeExcluded],
        'exclude_if' => [['items' => 'array', 'items.*.name' => 'string', 'items.*.type' => 'string', 'items.*.size' => 'exclude_if:items.*.type,bag|integer'], $data, $sizeExcluded],
        'fluent each()' => [['items' => FluentRule::array()->required()->each([
            'name' => FluentRule::string(),
            'type' => FluentRule::string()->required(),
            'size' => FluentRule::integer()->excludeUnless('items.*.type', 'box'),
        ])], $data, $sizeExcluded],
        'kept field' => [['items' => 'array', 'items.*.name' => 'string', 'items.*.type' => 'string', 'items.*.size' => 'exclude_unless:items.*.type,bag,box'], $data, $data],
        'two dependencies' => [
            ['items' => 'array', 'items.*.type' => 'string', 'items.*.kind' => 'string', 'items.*.size' => 'exclude_unless:items.*.type,box|integer', 'items.*.weight' => 'exclude_if:items.*.kind,light|integer'],
            ['items' => [['type' => 'bag', 'kind' => 'light', 'size' => 'x', 'weight' => 'y'], ['type' => 'box', 'kind' => 'heavy', 'size' => 2, 'weight' => 3]]],
            ['items' => [['type' => 'bag', 'kind' => 'light'], ['type' => 'box', 'kind' => 'heavy', 'size' => 2, 'weight' => 3]]],
        ],
        'slow kept rule, excluded at the last index' => [
            ['items' => 'array', 'items.*.type' => 'string', 'items.*.size' => ['exclude_unless:items.*.type,box', 'integer', static function (string $attribute, mixed $value, Closure $fail): void {}]],
            ['items' => [['type' => 'box', 'size' => 2], ['type' => 'bag', 'size' => 'x']]],
            ['items' => [['type' => 'box', 'size' => 2], ['type' => 'bag']]],
        ],
        'dotted parent' => [
            ['order.items' => 'array', 'order.items.*.type' => 'string', 'order.items.*.size' => 'exclude_unless:order.items.*.type,box|integer'],
            ['order' => ['items' => [['type' => 'bag', 'size' => 'x'], ['type' => 'box', 'size' => 2]]]],
            ['order' => ['items' => [['type' => 'bag'], ['type' => 'box', 'size' => 2]]]],
        ],
        'item without the field' => [
            ['items' => 'array', 'items.*.type' => 'string', 'items.*.size' => 'exclude_unless:items.*.type,box|integer'],
            ['items' => [['type' => 'bag'], ['type' => 'box', 'size' => 2]]],
            ['items' => [['type' => 'bag'], ['type' => 'box', 'size' => 2]]],
        ],
        'literal key with a dot' => [
            ['items' => 'array', 'items.*.type' => 'string', 'items.*.meta.size' => 'exclude_unless:items.*.type,box|integer'],
            ['items' => [['type' => 'bag', 'meta.size' => 'literal', 'meta' => ['size' => 'x']]]],
            // Like native, validated() leaves out the literal key and the emptied parent, which no rule validates.
            ['items' => [['type' => 'bag']]],
        ],
        'scalar items' => [
            ['tags' => 'array', 'tags.*' => 'exclude_if:tags.*,b|string'],
            ['tags' => ['a', 'b', 'c']],
            ['tags' => [0 => 'a', 2 => 'c']],
        ],
        'field below the item' => [
            ['items' => 'array', 'items.*.type' => 'string', 'items.*.meta' => 'array', 'items.*.meta.size' => 'exclude_unless:items.*.type,box|integer'],
            ['items' => [['type' => 'bag', 'meta' => ['size' => 'x']], ['type' => 'box', 'meta' => ['size' => 2]]]],
            ['items' => [['type' => 'bag', 'meta' => []], ['type' => 'box', 'meta' => ['size' => 2]]]],
        ],
    ];
}

it('leaves out wildcard fields that an exclude rule removes under a parent rule', function (string $case): void {
    [$rules, $data, $expected] = excludedUnderParentRuleCases()[$case];

    expect(RuleSet::from($rules)->validate($data))->toEqual($expected);
})->with(array_keys(excludedUnderParentRuleCases()));

it('leaves an object in the input unchanged when it removes an excluded field', function (): void {
    $meta = (object) ['size' => 'x'];

    RuleSet::from(['items' => 'array', 'items.*.type' => 'string', 'items.*.meta.size' => 'exclude_unless:items.*.type,box|integer'])
        ->validate(['items' => [['type' => 'bag', 'meta' => $meta]]]);

    expect($meta)->toEqual((object) ['size' => 'x']);
});
