<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Validation\Rule;
use SanderMuller\FluentValidation\FluentFormRequest;
use SanderMuller\FluentValidation\FluentRule;
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
    'items.* alone that excludes on a field of the item' => [['items' => 'array', 'items.*' => 'exclude_if:items.*.f,1|integer'], ['items' => [['f' => 1], 5]]],
    'items.* alone that keeps an item on a field of the item' => [['items' => 'array', 'items.*' => 'exclude_if:items.*.f,1|integer'], ['items' => [['f' => 0]]]],
    'items.* alone that excludes under a nullable parent' => [['items.*' => 'exclude_if:items.*.f,1', 'items' => 'nullable'], ['items' => ['a' => ['f' => 1], 'b' => ['f' => 0]]]],
    'items.* alone with exclude_unless on a field of the item' => [['items' => 'array', 'items.*' => 'exclude_unless:items.*.t,keep|array'], ['items' => [['t' => 'keep', 'x' => 1], ['t' => 'drop']]]],
    'items.* alone with required_if on a field of the item' => [['items' => 'array', 'items.*' => 'required_if:items.*.t,a|nullable'], ['items' => [['t' => 'b'], ['t' => 'a']]]],
    'a nested items.* alone that excludes on a field of the item' => [['o.items' => 'array', 'o.items.*' => 'exclude_if:o.items.*.f,1|integer'], ['o' => ['items' => [['f' => 1], 3]]]],
    'items.* alone with a value that looks like a field' => [['items' => 'array', 'items.*' => 'in:items.*.f'], ['items' => ['items.*.f']]],
    'items.*.a.* that excludes on a field of the item' => [['items' => 'array', 'items.*.a' => 'array', 'items.*.a.*' => 'exclude_if:items.*.f,1|integer', 'items.*.f' => 'integer'], ['items' => [['a' => ['x', 'y'], 'f' => 1], ['a' => [1, 2], 'f' => 0]]]],
    'items.*.a.* that excludes under a nullable parent' => [['items.*.a.*' => 'exclude_if:items.*.f,1', 'items' => 'nullable'], ['items' => [1 => ['a' => ['z' => 's'], 'f' => 1]]]],
    'items.*.a.*.b that excludes on a field of the item' => [['items' => 'array', 'items.*.a' => 'array', 'items.*.a.*.b' => 'exclude_if:items.*.f,1|integer', 'items.*.a.*.c' => 'nullable', 'items.*.f' => 'integer'], ['items' => [['a' => [['b' => 'x', 'c' => 1, 'd' => 2]], 'f' => 1], ['a' => [['b' => 3, 'c' => 4]], 'f' => 0]]]],
    'items.*.a.*.b that excludes on a field of the element' => [['items' => 'array', 'items.*.a' => 'array', 'items.*.a.*.b' => 'exclude_if:items.*.a.*.f,1|integer', 'items.*.a.*.f' => 'integer'], ['items' => [['a' => [['b' => 'x', 'f' => 1], ['b' => 2, 'f' => 0]]]]]],
    'an excluded items.0' => [['items' => 'array', 'items.*.b' => 'string', 'items.0' => 'exclude_if:flag,1', 'flag' => 'nullable'], ['items' => [['b' => []], ['b' => 'ok']], 'flag' => 1]],
    'an excluded items.0 before the wildcard keys' => [['items.0' => 'exclude_if:flag,1', 'items' => 'array', 'items.*.b' => 'string', 'flag' => 'nullable'], ['items' => [['b' => []], ['b' => 'ok']], 'flag' => 1]],
    'an excluded items.0.b' => [['items' => 'array', 'items.*.b' => 'string', 'items.0.b' => 'exclude_if:flag,1', 'flag' => 'nullable'], ['items' => [['b' => [], 'c' => 1], ['b' => 'ok']], 'flag' => 1]],
    'an excluded items.0 of a scalar items.*' => [['items' => 'array', 'items.*' => 'integer', 'items.0' => 'exclude_if:flag,1', 'flag' => 'nullable'], ['items' => ['x', 2], 'flag' => 1]],
    'items.0 that an exclude rule keeps' => [['items' => 'array', 'items.*.b' => 'string', 'items.0' => 'exclude_if:flag,1', 'flag' => 'nullable'], ['items' => [['b' => []]], 'flag' => 0]],
    'items.1 after items.*' => [['items' => 'array', 'items.*' => 'integer', 'items.1' => 'nullable'], ['items' => [1, 'x']]],
    'items.1 after items.*, failing' => [['items' => 'array', 'items.*' => 'integer', 'items.1' => 'nullable'], ['items' => ['a', 'x']]],
    'items.0.x after items.*.x' => [['items' => 'array', 'items.*.x' => 'integer', 'items.0.x' => 'nullable'], ['items' => [['x' => 'q'], ['x' => 'r']]]],
    'items.0.x after items.*.x and items.*.y' => [['items.*.x' => 'integer', 'items.*.y' => 'required', 'items.0.x' => 'string', 'items' => 'array'], ['items' => [['x' => 'q'], ['x' => 2, 'y' => 1]]]],
    'an explicit key inside an item that items.* excludes' => [['items' => 'array', 'items.*' => 'exclude_unless:items.*.t,keep|array', 'items.0.l' => 'string'], ['items' => [['x']]]],
    'an explicit key inside an item that items.* keeps' => [['items' => 'array', 'items.*' => 'exclude_unless:items.*.t,keep|array', 'items.0.l' => 'string'], ['items' => [['x'], ['t' => 'keep']]]],
    'an excluded items.0.l.0' => [['items' => 'array', 'items.*.l' => 'array', 'items.*.l.*.x' => 'string', 'items.0.l.0' => 'exclude_if:flag,1', 'flag' => 'nullable'], ['items' => [['l' => [['x' => []], ['x' => 'ok']]]], 'flag' => 1]],
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

it('excludes an item on a field of the item in a fluent each() rule like native', function (): void {
    $rules = ['items' => FluentRule::array()->each(FluentRule::array()->excludeIf('items.*.f', 1))];
    $data = ['items' => [['f' => 1, 'x' => 2], ['f' => 0]]];

    expect(RuleSet::from($rules)->validate($data))
        ->toEqual(validator($data, RuleSet::compile(RuleSet::from($rules)->flattenRules()))->validated());
});

it('applies an explicit key after a fluent each() rule like native', function (): void {
    $rules = ['items' => FluentRule::array()->each(FluentRule::integer()), 'items.1' => 'nullable|string'];
    $native = RuleSet::compile(RuleSet::from($rules)->flattenRules());

    foreach ([['items' => [1, 'x']], ['items' => ['a', 2]]] as $data) {
        expect(RuleSet::from($rules)->check($data)->errors()->toArray())
            ->toBe(validator($data, $native)->errors()->toArray());
    }
});

it('applies an explicit key after a wildcard key in a form request like native', function (): void {
    $formRequest = new class extends FluentFormRequest {
        /** @return array<string, mixed> */
        public function rules(): array
        {
            return ['items' => 'array', 'items.*' => 'integer', 'items.1' => 'nullable|string'];
        }
    };
    $data = ['items' => [1, 'x']];

    $instance = $formRequest::createFrom(Request::create('/test', 'POST', $data));
    $instance->setContainer(app());
    $instance->setRedirector(resolve(Redirector::class));
    $instance->validateResolved();

    expect($instance->validated())->toBe(validator($data, $formRequest->rules())->validated());
});
