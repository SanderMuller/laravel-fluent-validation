<?php declare(strict_types=1);

use Illuminate\Validation\Rule;
use SanderMuller\FluentValidation\RuleSet;

// =========================================================================
// Laravel merges the rules of every key that expands to one attribute, and
// a rule on `items.*` validates the item itself. RuleSet must give the same
// errors and validated data for these rule shapes.
// =========================================================================

/**
 * @param  array<string, mixed>  $rules
 * @param  array<string, mixed>  $data
 */
function assertShapeParity(array $rules, array $data, bool $stopOnFirstFailure): void
{
    $native = validator($data, $rules);
    $ruleSet = RuleSet::from($rules);

    if ($stopOnFirstFailure) {
        $native->stopOnFirstFailure();
        $ruleSet->stopOnFirstFailure();
    }

    $result = $ruleSet->check($data);

    expect($result->errors()->toArray())->toBe($native->errors()->toArray());

    if ($native->errors()->isEmpty()) {
        expect($result->validated())->toEqual($native->validated());
    }
}

/** @var array<string, array{0: array<string, mixed>, 1: array<string, mixed>}> $shapeCases */
$shapeCases = [
    'a numeric child key, failing' => [['rows' => 'array', 'rows.*.0' => 'integer', 'rows.*.1' => 'integer'], ['rows' => [['a', 'b'], ['c', 4]]]],
    'a numeric child key, passing' => [['rows' => 'array', 'rows.*.0' => 'integer'], ['rows' => [[1], [2]]]],
    'items.* with items.*.x, failing' => [['items' => 'array', 'items.*' => 'array|min:2', 'items.*.x' => 'integer'], ['items' => [['x' => 'a'], ['x' => 1, 'y' => 2]]]],
    'items.* with items.*.x, passing' => [['items' => 'array', 'items.*' => 'array', 'items.*.x' => 'integer'], ['items' => [['x' => 1], ['x' => 2]]]],
    'items.* with items.*.x and no parent rule' => [['items.*' => 'array|min:2', 'items.*.x' => 'integer'], ['items' => [['x' => 'a'], ['x' => 3, 'k' => 1]]]],
    'items.*.x before items.*' => [['items' => 'array', 'items.*.x' => 'integer', 'items.*' => 'array|min:2'], ['items' => [['x' => 'a'], ['x' => 1, 'y' => 2], ['x' => 'b', 'y' => 1]]]],
    'items.* with a non-array item' => [['items' => 'array', 'items.*' => 'array', 'items.*.x' => 'required'], ['items' => [['x' => 1], 'z', null]]],
    'items.* that names a top-level field' => [['type' => 'string', 'items' => 'array', 'items.*' => 'required_if:type,a|array', 'items.*.x' => 'integer'], ['type' => 'a', 'items' => [null, ['x' => 'q']]]],
    'items.* that names a field of the item' => [['items' => 'array', 'items.*' => 'array|required_with:items.*.x', 'items.*.x' => 'integer'], ['items' => [['x' => 1], []]]],
    'items.* with an exclude rule' => [['flag' => 'boolean', 'items' => 'array', 'items.*' => 'exclude_if:flag,true|array', 'items.*.x' => 'integer'], ['flag' => true, 'items' => [['x' => 'a']]]],
    'items.*.*' => [['items' => 'array', 'items.*.*' => 'integer'], ['items' => [[1, 'a'], ['b']]]],
    'items.*.* with items.*.x' => [['items' => 'array', 'items.*.*' => 'integer', 'items.*.x' => 'min:5'], ['items' => [['x' => 3, 'y' => 'a'], ['x' => 'b']]]],
    'items.* with items.*.*' => [['items' => 'array', 'items.*' => 'array', 'items.*.*' => 'integer'], ['items' => [[1, 'a'], 'z']]],
    'items.0.* with items.*.x' => [['items' => 'array', 'items.0.*' => 'integer', 'items.*.x' => 'min:5'], ['items' => [['x' => 3], ['x' => 'b']]]],
    'items.0.x with items.*.x' => [['items' => 'array', 'items.0.x' => 'integer', 'items.*.x' => 'min:10'], ['items' => [['x' => 3], ['x' => 'b']]]],
    'items.1.x between wildcard keys' => [['items' => 'array', 'items.*.y' => 'required', 'items.1.x' => 'integer', 'items.*.x' => 'min:10'], ['items' => [['x' => 3], ['x' => 'b']]]],
];

foreach ($shapeCases as $name => [$rules, $data]) {
    it("validates {$name} like native", function () use ($rules, $data): void {
        assertShapeParity($rules, $data, false);
    });

    it("validates {$name} like native with stopOnFirstFailure", function () use ($rules, $data): void {
        assertShapeParity($rules, $data, true);
    });
}

it('skips the items of a failed parent for a rule shape that needs full expansion', function (): void {
    $result = RuleSet::from(['items' => 'array|max:1', 'items.*' => 'array', 'items.*.x' => 'integer'])
        ->check(['items' => [['x' => 'a'], ['x' => 'b']]]);

    expect($result->errors()->keys())->toBe(['items']);
});

it('runs a custom parent rule once for a rule shape that needs full expansion', function (): void {
    $calls = 0;
    $rule = static function (string $attribute, mixed $value, Closure $fail) use (&$calls): void {
        $calls++;
    };

    $result = RuleSet::from(['items' => ['array', $rule], 'items.*' => 'array', 'items.*.x' => 'integer'])
        ->check(['items' => [['x' => 1]]]);

    expect($result->passes())->toBeTrue()
        ->and($calls)->toBe(1);
});

it('reads a parent rule like Laravel when it skips the items of a failed parent', function (mixed $parentRule): void {
    $result = RuleSet::from(['items' => $parentRule, 'items.*' => 'array', 'items.*.x' => 'integer'])
        ->check(['items' => [['x' => 'a'], ['x' => 'b'], ['x' => 'c']]]);

    expect($result->errors()->keys())->toBe(['items']);
})->with([
    'conditional rule' => [fn (): mixed => Rule::when(true, ['array', 'max:2'])],
    'space before the rule name' => ['array| max:2'],
    'capitalised rule name' => ['array|Max:2'],
]);

it('evaluates a conditional parent rule with a closure once for a rule shape that needs full expansion', function (): void {
    $calls = 0;
    $condition = static function () use (&$calls): bool {
        $calls++;

        return true;
    };

    RuleSet::from(['items' => Rule::when($condition, ['array', 'max:2']), 'items.*' => 'array', 'items.*.x' => 'integer'])
        ->check(['items' => [['x' => 1], ['x' => 2], ['x' => 3]]]);

    expect($calls)->toBe(1);
});

/** @var array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, string>}> $itemMessageCases */
$itemMessageCases = [
    'a message for the item rule' => [['items.*.x' => 'integer|min:5'], ['items' => [['x' => 1]]], ['items.*.min' => 'Item small']],
    'a message for one item' => [['items.*.x' => 'integer|min:5'], ['items' => [['x' => 1]]], ['items.0.min' => 'Item zero small']],
    'a field named like a rule of the item' => [['items.*.min' => 'integer', 'items.*.x' => 'integer|min:5'], ['items' => [['x' => 1, 'min' => 'q']]], ['items.*.min' => 'M']],
    'a wildcard below the item' => [['items.*.x' => 'integer', 'items.*.t' => 'array', 'items.*.t.*' => 'integer'], ['items' => [['x' => 'q', 't' => [1, 'a']]]], ['items.*.*' => 'Any']],
];

foreach ($itemMessageCases as $name => [$fieldRules, $data, $messages]) {
    it("applies {$name} like native next to a rule on items.*", function () use ($fieldRules, $data, $messages): void {
        $rules = ['items' => 'array', 'items.*' => 'array|min:2', ...$fieldRules];

        expect(RuleSet::from($rules)->check($data, $messages)->errors()->toArray())
            ->toBe(validator($data, $rules, $messages)->errors()->toArray());
    });
}
