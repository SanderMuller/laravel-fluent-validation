<?php declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use SanderMuller\FluentValidation\FluentValidator;
use SanderMuller\FluentValidation\Internal\ItemFieldChecks;
use SanderMuller\FluentValidation\Internal\ReferencedAttributes;
use SanderMuller\FluentValidation\Internal\WildcardFastChecks;

/**
 * @return array<string, mixed>
 */
function itemFieldResult(Illuminate\Validation\Validator $validator): array
{
    return $validator->fails() ? ['errors' => $validator->errors()->toArray()] : ['validated' => $validator->validated()];
}

/** @return array<string, array{array<string, mixed>, array<string, mixed>}> */
function itemFieldCases(): array
{
    return iterator_to_array(itemFieldCaseList());
}

/** @return iterable<string, array{array<string, mixed>, array<string, mixed>}> */
function itemFieldCaseList(): iterable
{
    $items = [['min' => 1, 'max' => 5], ['min' => 4, 'max' => 3], ['min' => '2', 'max' => 2.5]];

    yield 'gte on a field of the item' => [
        ['items' => $items],
        ['items.*.min' => 'required|numeric', 'items.*.max' => 'required|numeric|gte:items.*.min'],
    ];
    yield 'a referenced field that fails its own rules' => [
        ['items' => $items],
        ['items.*.min' => 'numeric|min:3', 'items.*.max' => 'numeric|gte:items.*.min'],
    ];
    yield 'a chain of comparisons' => [
        ['items' => [['a' => 1, 'b' => 2, 'c' => 3], ['a' => 3, 'b' => 2, 'c' => 1]]],
        ['items.*.a' => 'numeric', 'items.*.b' => 'numeric|gt:items.*.a', 'items.*.c' => 'numeric|gt:items.*.b'],
    ];
    yield 'same and different' => [
        ['items' => [['a' => 'x', 'b' => 'x', 'c' => 'y'], ['a' => 'x', 'b' => 'z', 'c' => 'x']]],
        ['items.*.a' => 'string', 'items.*.b' => 'string|same:items.*.a', 'items.*.c' => 'string|different:items.*.a'],
    ];
    yield 'a date after a field of the item and a literal date' => [
        ['items' => [['from' => '2025-01-01', 'to' => '2025-02-01'], ['from' => '2025-03-01', 'to' => '2025-02-01']]],
        ['items.*.from' => 'date|after:2024-01-01', 'items.*.to' => 'date|after:items.*.from'],
    ];
    yield 'a missing field and a scalar item' => [
        ['items' => [['max' => 5], 'text', ['min' => null, 'max' => 1]]],
        ['items.*.min' => 'nullable|numeric', 'items.*.max' => 'numeric|gte:items.*.min'],
    ];
    yield 'a reference outside the item' => [
        ['limit' => 3, 'items' => $items],
        ['limit' => 'numeric', 'items.*.max' => 'numeric|gte:limit'],
    ];
    yield 'date_equals on a field of the item' => [
        ['items' => [['a' => '2025-01-01', 'b' => '2025-01-01']]],
        ['items.*.a' => 'date', 'items.*.b' => 'date|date_equals:items.*.a'],
    ];
    yield 'a pattern that ends in a wildcard, compared with a key of its array' => [
        ['items' => ['min' => 2, 0 => 3, 1 => 1]],
        ['items' => 'array', 'items.*' => 'numeric|gte:items.min'],
    ];
    yield 'a size comparison without a numeric rule elsewhere' => [
        ['other' => '12', 'top' => '5', 'items' => $items],
        ['top' => 'string|gte:other', 'other' => 'string', 'items.*.min' => 'numeric', 'items.*.max' => 'numeric|gte:items.*.min'],
    ];
}

/**
 * @param  array<string, mixed>  $data
 * @param  array<string, mixed>  $rules
 */
function expectItemFieldParity(array $data, array $rules): void
{
    $validator = new class ($data, $rules) extends FluentValidator {};
    $validator->excludeUnvalidatedArrayKeys = true;

    expect(itemFieldResult($validator))->toBe(itemFieldResult(Validator::make($data, $rules)));
}

it('matches Laravel for rules that compare with another field of the item', function (string $case): void {
    expectItemFieldParity(...itemFieldCases()[$case]);
})->with(array_keys(itemFieldCases()));

it('leaves to Laravel only the item attributes that fail or that a failing attribute reads', function (): void {
    $data = ['items' => [['min' => 1, 'max' => 5], ['min' => 4, 'max' => 3], ['min' => 2, 'max' => 2]]];
    $validator = new class ($data, ['items.*.min' => 'required|numeric', 'items.*.max' => 'required|numeric|gte:items.*.min']) extends FluentValidator {
        /** @var list<string> */
        public array $validatedByLaravel = [];

        protected function validateAttribute($attribute, $rule): void
        {
            $this->validatedByLaravel[] = $attribute;
            parent::validateAttribute($attribute, $rule);
        }
    };

    expect($validator->fails())->toBeTrue()
        ->and(array_values(array_unique($validator->validatedByLaravel)))->toBe(['items.1.min', 'items.1.max']);
});

it('builds an item check only for fields of the item that Laravel resolves per attribute', function (string $rule, ?array $fields): void {
    $checks = ItemFieldChecks::build(['items.*.b' => $rule], ['items.*.b' => $rule]);

    expect(isset($checks['items.*.b']) ? $checks['items.*.b'][1] : null)->toBe($fields);
})->with([
    'gte' => ['numeric|gte:items.*.a', ['a']],
    'two fields' => ['numeric|gte:items.*.a|lt:items.*.c', ['a', 'c']],
    'a literal date next to a field' => ['date|after:2024-01-01|before:items.*.from', ['from']],
    'a field outside the item' => ['numeric|gte:a', null],
    'a field of another pattern' => ['numeric|gte:other.*.a', null],
    'date_equals' => ['date|date_equals:items.*.a', null],
    'confirmed' => ['string|confirmed|same:items.*.a', null],
    'date_equals with a field outside the item' => ['date|date_equals:start|after:items.*.from', null],
    'a field name that reads as a date' => ['date|after:items.*.today', null],
    'a one-letter field name that reads as a time zone' => ['date|after:items.*.a', null],
    'no field' => ['numeric|min:1', null],
]);

it('builds no size comparison when a rule compares sizes without a numeric rule', function (): void {
    $rules = ['items.*.b' => 'numeric|gte:items.*.a', 'top' => 'string|gte:other'];

    expect(ItemFieldChecks::build(['items.*.b' => $rules['items.*.b']], $rules))
        ->toBeEmpty()
        ->and(ItemFieldChecks::build(['items.*.b' => 'string|same:items.*.a'], $rules))->toHaveKey('items.*.b');
});

it('pairs each referenced attribute with the attributes that name it', function (): void {
    $rules = ['items.0.a' => 'numeric', 'items.1.a' => 'numeric', 'items.0.b' => 'numeric|gte:items.*.a', 'items.1.b' => 'numeric|gte:items.*.a', 'top' => 'numeric', 'items.0.c' => 'numeric|gte:top', 'items.1.c' => 'numeric|gte:top'];
    $implicit = ['items.*.a' => ['items.0.a', 'items.1.a'], 'items.*.b' => ['items.0.b', 'items.1.b'], 'items.*.c' => ['items.0.c', 'items.1.c']];

    expect(ReferencedAttributes::withDependents($rules, $implicit))->toBe([
        'items.0.a' => ['items.0.b'],
        'items.1.a' => ['items.1.b'],
        'top' => ['items.0.c', 'items.1.c'],
    ]);
});

it('marks the dependents unknown when Laravel would not resolve a reference per attribute', function (): void {
    $rules = ['flat' => 'numeric|gte:items.*.a', 'items.0.a' => 'numeric', 'items.0.l.0' => 'numeric|gte:items.*.l.*.x', 'items.0.l.0.x' => 'numeric', 'items.0.y' => 'numeric|gte:items.*.l.*.x'];
    $implicit = ['items.*.a' => ['items.0.a'], 'items.*.l.*' => ['items.0.l.0'], 'items.*.l.*.x' => ['items.0.l.0.x'], 'items.*.y' => ['items.0.y']];

    expect(ReferencedAttributes::withDependents($rules, $implicit))->toBe(['items.0.l.0.x' => false, 'items.0.a' => false]);
});

it('removes a referenced attribute only when no attribute left in the rules reads it', function (): void {
    $passes = static fn (mixed $value): bool => is_int($value);
    $checks = WildcardFastChecks::of(['items.*.a' => $passes, 'items.*.b' => $passes], ['items.0.a' => 'items.*.a', 'items.1.a' => 'items.*.a', 'items.0.b' => 'items.*.b', 'items.1.b' => 'items.*.b'], ['items.0.a' => true, 'items.1.a' => true], [], ['items.0.a' => ['items.0.b'], 'items.1.a' => ['items.1.b']]);
    $data = ['items' => [['a' => 1, 'b' => 2], ['a' => 1, 'b' => 'x']]];
    $rules = ['items.0.a' => ['integer'], 'items.1.a' => ['integer'], 'items.0.b' => ['integer'], 'items.1.b' => ['integer']];
    $getValue = static fn (int|string $attribute): mixed => data_get($data, (string) $attribute);

    $removed = $checks->removePassing($rules, $data, $getValue);

    expect(array_keys($rules))->toBe(['items.0.a', 'items.1.a', 'items.1.b']);

    $checks->removePassingReferenced($rules, $removed, $getValue);

    expect(array_keys($rules))->toBe(['items.1.a', 'items.1.b'])
        ->and(array_keys($removed))->toBe(['items.0.b', 'items.0.a']);
});

it('removes a chain of referenced attributes from the end', function (): void {
    $passes = static fn (mixed $value): bool => is_int($value);
    $checks = WildcardFastChecks::of(['a.*.x' => $passes, 'a.*.y' => $passes], ['a.0.x' => 'a.*.x', 'a.0.y' => 'a.*.y'], ['a.0.x' => true, 'a.0.y' => true], [], ['a.0.x' => ['a.0.y'], 'a.0.y' => ['a.0.z']]);
    $rules = ['a.0.x' => ['integer'], 'a.0.y' => ['integer']];
    $removed = [];

    $checks->removePassingReferenced($rules, $removed, static fn (int|string $attribute): int => 1);

    expect($rules)
        ->toBeEmpty();
});

it('keeps a referenced attribute whose dependents are not known', function (): void {
    $checks = WildcardFastChecks::of(['a.*.x' => static fn (): bool => true], ['a.0.x' => 'a.*.x'], ['a.0.x' => true], [], ['a.0.x' => false]);
    $rules = ['a.0.x' => ['integer']];
    $removed = [];

    $checks->removePassingReferenced($rules, $removed, static fn (int|string $attribute): int => 1);

    expect($rules)->toHaveKey('a.0.x');
});
