<?php declare(strict_types=1);

use Illuminate\Support\Arr;
use SanderMuller\FluentValidation\Internal\ExcludedData;

/**
 * @param  array<array-key, mixed>  $data
 * @param  list<string>  $attributes
 */
function expectForgetLikeArr(array $data, array $attributes): void
{
    $expected = $data;

    foreach ($attributes as $attribute) {
        Arr::forget($expected, $attribute);
    }

    ExcludedData::forget($data, $attributes);

    expect($data)->toBe($expected);
}

/** @return array<string, array{array<array-key, mixed>, list<string>}> */
function excludedDataCases(): array
{
    return [
        'attributes of one item' => [['items' => [['a' => 1, 'b' => 2, 'c' => 3], ['a' => 4]]], ['items.0.a', 'items.0.c', 'items.1.a']],
        'a top-level attribute' => [['a' => 1, 'b' => 2], ['a']],
        'a top-level key with a dot' => [['x.y' => 1, 'x' => ['y' => 2]], ['x.y']],
        'a missing path' => [['items' => [['a' => 1]]], ['items.3.a', 'other.a', 'items.0.a.b']],
        'a null on the path' => [['items' => [null, ['a' => null]]], ['items.0.a', 'items.1.a']],
        'a scalar on the path' => [['items' => ['text', ['a' => 1]]], ['items.0.a', 'items.1.a']],
        'a parent removed before its child' => [['items' => [['s' => ['a' => 1, 'b' => 2]]]], ['items.0.s', 'items.0.s.a']],
        'a child removed before its parent' => [['items' => [['s' => ['a' => 1, 'b' => 2]]]], ['items.0.s.a', 'items.0.s', 'items.0.s.b']],
    ];
}

it('removes the data of excluded attributes as Arr::forget does', function (string $case): void {
    expectForgetLikeArr(...excludedDataCases()[$case]);
})->with(array_keys(excludedDataCases()));

it('removes the data through an ArrayAccess object as Arr::forget does', function (): void {
    $object = new ArrayObject(['a' => 1, 'b' => 2]);
    $data = ['items' => [$object]];

    ExcludedData::forget($data, ['items.0.a']);

    expect($object->getArrayCopy())->toBe(['b' => 2]);
});

it('leaves copies of the data independent', function (): void {
    $data = ['items' => [['a' => 1, 'b' => 2]]];
    $copy = $data;

    ExcludedData::forget($data, ['items.0.a']);
    $later = $data;
    $later['items'][0]['b'] = 4;
    $data['items'][0]['b'] = 3;

    expect($copy)->toBe(['items' => [['a' => 1, 'b' => 2]]])
        ->and($data)->toBe(['items' => [['b' => 3]]])
        ->and($later)->toBe(['items' => [['b' => 4]]]);
});
