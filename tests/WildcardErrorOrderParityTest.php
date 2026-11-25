<?php declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use SanderMuller\FluentValidation\RuleSet;

// =========================================================================
// Laravel validates every top-level attribute first, then each wildcard rule
// in rule order, for every item in data order. Its errors come in that order,
// and stopOnFirstFailure keeps the errors of the first failing attribute.
// =========================================================================

/**
 * @param  array<string, mixed>  $rules
 * @param  array<string, mixed>  $data
 */
function assertErrorOrderParity(array $rules, array $data, bool $stopOnFirstFailure = false): void
{
    $native = validator($data, $rules);
    $ruleSet = RuleSet::from($rules);

    if ($stopOnFirstFailure) {
        $native->stopOnFirstFailure();
        $ruleSet->stopOnFirstFailure();
    }

    $expected = $native->errors()->toArray();

    try {
        $ruleSet->validate($data);
        $actual = [];
    } catch (ValidationException $validationException) {
        $actual = $validationException->errors();
    }

    expect($expected)->not->toBeEmpty()
        ->and($actual)->toBe($expected)
        ->and($ruleSet->check($data)->errors()->toArray())->toBe($expected);
}

it('reports wildcard errors in rule order, then item order, like native', function (bool $stopOnFirstFailure): void {
    assertErrorOrderParity(
        ['name' => 'nullable', 'items' => 'array', 'items.*.x' => 'integer', 'items.*.y' => 'integer'],
        ['name' => 'n', 'items' => [['x' => 1, 'y' => 'b'], ['x' => 'c', 'y' => 2], ['x' => 'd', 'y' => 'e']]],
        $stopOnFirstFailure,
    );
})->with(['all errors' => [false], 'stop on first failure' => [true]]);

it('reports the wildcard errors next to a failing top-level rule, like native', function (bool $stopOnFirstFailure): void {
    assertErrorOrderParity(
        ['items' => 'array', 'items.*.x' => 'integer', 'name' => 'required'],
        ['items' => [['x' => 'a'], ['x' => 'b']]],
        $stopOnFirstFailure,
    );
})->with(['all errors' => [false], 'stop on first failure' => [true]]);

it('orders the errors of several wildcard groups by rule, like native', function (bool $stopOnFirstFailure): void {
    assertErrorOrderParity(
        ['a.*.x' => 'integer', 'b.*.x' => 'integer', 'a.*.y' => 'integer'],
        ['a' => [['x' => 1, 'y' => 'p']], 'b' => [['x' => 'q']]],
        $stopOnFirstFailure,
    );
})->with(['all errors' => [false], 'stop on first failure' => [true]]);

it('orders a nested wildcard rule before a later field of the item, like native', function (bool $stopOnFirstFailure): void {
    assertErrorOrderParity(
        ['items' => 'array', 'items.*.tags' => 'array', 'items.*.tags.*' => 'integer', 'items.*.x' => 'integer'],
        ['items' => [['x' => 'a', 'tags' => [1, 'b']], ['x' => 'c', 'tags' => ['d']]]],
        $stopOnFirstFailure,
    );
})->with(['all errors' => [false], 'stop on first failure' => [true]]);

it('keeps every message of the first failing attribute, like native', function (): void {
    assertErrorOrderParity(
        ['items' => 'array', 'items.*.x' => 'nullable', 'items.*.y' => 'integer|min:5|max:1'],
        ['items' => [['x' => 1, 'y' => 'z'], ['y' => 3]]],
        true,
    );
});

it('orders items by their position in the data, like native', function (bool $stopOnFirstFailure): void {
    assertErrorOrderParity(
        ['items' => 'array', 'items.*.x' => 'integer', 'items.*.y' => 'integer'],
        ['items' => ['b' => ['x' => 1, 'y' => 'p'], 'a' => ['x' => 'q', 'y' => 2], 10 => ['x' => 'r', 'y' => 3], 2 => ['x' => 4, 'y' => 's']]],
        $stopOnFirstFailure,
    );
})->with(['all errors' => [false], 'stop on first failure' => [true]]);

it('validates the items of a parent that fails a top-level rule, and the other groups, like native', function (bool $stopOnFirstFailure): void {
    assertErrorOrderParity(
        ['items' => 'array|max:1', 'items.*.x' => 'integer', 'other' => 'array', 'other.*.x' => 'integer'],
        ['items' => [['x' => 'a'], ['x' => 'b']], 'other' => [['x' => 'c']]],
        $stopOnFirstFailure,
    );
})->with(['all errors' => [false], 'stop on first failure' => [true]]);

it('orders errors like native when a rule key holds a numeric segment', function (bool $stopOnFirstFailure): void {
    assertErrorOrderParity(
        ['rows' => 'array', 'rows.*.v.0' => 'integer', 'rows.*.v.1' => 'integer'],
        ['rows' => [['v' => ['a', 'b']], ['v' => ['c', 'd']]]],
        $stopOnFirstFailure,
    );
})->with(['all errors' => [false], 'stop on first failure' => [true]]);

it('keeps the top-level validator when only a top-level rule fails', function (): void {
    $result = RuleSet::from(['name' => 'required', 'items' => 'array', 'items.*.x' => 'integer'])
        ->check(['items' => [['x' => 1]]]);

    expect($result->validator()->failed())->toBe(['name' => ['Required' => []]]);
});

it('stops validating items at the first failure of the first wildcard rule, like native', function (): void {
    $calls = 0;
    $rule = static function (string $attribute, mixed $value, Closure $fail) use (&$calls): void {
        $calls++;

        if ($value === 'bad') {
            $fail('The :attribute is bad.');
        }
    };

    $result = RuleSet::from(['items.*.x' => [$rule], 'other.*.y' => [$rule]])
        ->stopOnFirstFailure()
        ->check(['items' => array_fill(0, 50, ['x' => 'bad']), 'other' => array_fill(0, 50, ['y' => 'bad'])]);

    expect($result->errors()->keys())->toBe(['items.0.x'])
        ->and($calls)->toBe(1);
});

it('validates a fallback group and the items of a failed parent after a top-level failure like native', function (bool $stopOnFirstFailure): void {
    assertErrorOrderParity(
        ['items' => 'array|max:1', 'items.*' => 'integer', 'other.*.x' => 'required_with:flag', 'title' => 'required', 'flag' => 'nullable'],
        ['flag' => 1, 'items' => ['a', 'b'], 'other' => [[]]],
        $stopOnFirstFailure,
    );
})->with(['all errors' => [false], 'stop on first failure' => [true]]);

it('keeps the failed top-level rules when wildcard errors also exist', function (): void {
    $result = RuleSet::from(['title' => 'required', 'items.*.x' => 'integer'])->check(['items' => [['x' => 'a']]]);

    expect($result->validator()->failed())->toBe(['title' => ['Required' => []]])
        ->and($result->errors()->keys())->toBe(['title', 'items.0.x']);
});

it('fills in the index and position placeholders of a wildcard message like native', function (): void {
    $rules = ['items' => 'array', 'items.*' => 'array|min:2', 'items.*.x' => 'integer', 'items.*.tags' => 'array', 'items.*.tags.*' => 'integer'];
    $data = ['items' => [['x' => 1], ['x' => 'a', 'tags' => [1, 'b']]]];
    $messages = [
        'items.*.min' => 'Item :position (:index)',
        'items.*.x.integer' => 'X :ordinal-position',
        'items.*.tags.*.integer' => 'Tag :first-position.:second-position',
    ];

    expect(RuleSet::from($rules)->check($data, $messages)->errors()->toArray())
        ->toBe(validator($data, $rules, $messages)->errors()->toArray());
});
