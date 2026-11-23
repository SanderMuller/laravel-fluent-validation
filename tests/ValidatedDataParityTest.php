<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Validation\Factory;
use SanderMuller\FluentValidation\RuleSet;
use SanderMuller\FluentValidation\Tests\Fixtures\EachChildrenFluentFormRequest;

// =========================================================================
// RuleSet::validate() returns the keys and values of native Laravel's
// validated data: by default it leaves out the array keys no rule validates.
// The keys keep the order of the input. A host factory that includes the
// unvalidated keys gets them back.
// =========================================================================

/** @var array<string, array{0: array<string, mixed>, 1: array<string, mixed>}> $validatedCases */
$validatedCases = [
    'each() with a parent rule' => [['items' => 'required|array', 'items.*.x' => 'integer'], ['items' => [['x' => 1, 'secret' => 'leak']]]],
    'nested children() under each()' => [['items' => 'array', 'items.*.meta' => 'array', 'items.*.meta.t' => 'string'], ['items' => [['meta' => ['t' => 'a', 'secret' => 'leak']]]]],
    'a rule on the item and on its fields' => [['items' => 'array', 'items.*' => 'array', 'items.*.x' => 'integer'], ['items' => [['x' => 1, 'y' => 2], ['x' => 3]]]],
    'an ancestor and a sibling top-level rule' => [['order' => 'array', 'order.name' => 'string', 'order.lines' => 'array', 'order.lines.*.x' => 'integer'], ['order' => ['name' => 'n', 'junk' => 1, 'lines' => [['x' => 1, 's' => 2]]]]],
    'an ancestor rule only' => [['order' => 'array', 'order.lines.*.x' => 'integer'], ['order' => ['junk' => 1, 'lines' => [['x' => 1, 's' => 2]]]]],
    'a parent without an array rule' => [['items' => 'required', 'items.*.x' => 'integer'], ['items' => [['x' => 1, 's' => 2]]]],
    'a parent with array keys' => [['items' => 'array:0,1', 'items.*.x' => 'integer'], ['items' => [['x' => 1, 's' => 2]]]],
    'an empty nested wildcard array' => [['items' => 'array', 'items.*.tags' => 'array', 'items.*.tags.*' => 'integer'], ['items' => [['tags' => [], 's' => 1], ['tags' => [1]]]]],
    'empty and missing fields' => [['items' => 'array', 'items.*.x' => 'nullable|integer', 'items.*.y' => 'nullable'], ['items' => [['x' => null, 's' => 1], []]]],
    'a top-level exclude rule' => [['flag' => 'boolean', 'note' => 'exclude_if:flag,true|string', 'items' => 'array', 'items.*.x' => 'integer'], ['flag' => true, 'note' => 'n', 'items' => [['x' => 1, 's' => 2]]]],
    'a nested top-level exclude rule' => [['order' => 'array', 'order.flag' => 'boolean', 'order.note' => 'exclude_if:order.flag,true|string', 'items' => 'array', 'items.*.x' => 'integer'], ['order' => ['flag' => true, 'note' => 'n', 'junk' => 1], 'items' => [['x' => 1, 's' => 2]]]],
    'an item exclude rule' => [['items' => 'array', 'items.*.t' => 'string', 'items.*.x' => 'exclude_if:items.*.t,a|integer'], ['items' => [['t' => 'a', 'x' => 1, 's' => 2], ['t' => 'b', 'x' => 2]]]],
    'an excluded field that empties its item' => [['items' => 'array', 'items.*' => 'nullable|array', 'items.*.meta' => 'nullable', 'items.*.meta.t' => 'array', 'items.*.x' => 'exclude_if:items.*.y,1|nullable|integer'], ['items' => [['x' => 'a', 'y' => 1]]]],
    'scalar and array items in a list' => [['items' => 'list', 'items.*' => 'required', 'items.*.tags.*' => 'array'], ['items' => [['tags' => 2, 'z' => ['x' => 'a']], 1]]],
    'a scalar item under a wildcard key' => [['items' => 'nullable|array', 'items.*' => 'required', 'items.*.tags.*' => 'nullable|array'], ['items' => [['z' => null], true]]],
    'a named key with an array rule and a wildcard field' => [['order' => 'array', 'order.meta' => 'array', 'order.*.id' => 'nullable'], ['order' => ['meta' => ['id' => 1, 'secret' => 'leak']]]],
    'an indexed item rule and a wildcard field' => [['items' => 'array', 'items.1' => 'array', 'items.*.b' => 'nullable'], ['items' => [['b' => 1, 'z' => 1], ['z' => 2, 'secret' => 'leak']]]],
    'a wildcard item rule and an indexed field' => [['items' => 'array', 'items.*' => 'array', 'items.1.b' => 'nullable'], ['items' => [['b' => 1, 'z' => 1], ['z' => 2, 'secret' => 'leak']]]],
    'a wildcard item rule and an indexed nested field' => [['r' => 'nullable|array', 'r.*' => 'array', 'r.1.1' => 'string|nullable'], ['r' => ['1' => ['a' => null]]]],
    'a false value' => [['flag' => 'boolean', 'items' => 'array', 'items.*.x' => 'nullable'], ['flag' => false, 'items' => [['x' => 1, 's' => 2]]]],
    'a value that looks like an internal marker' => [['a' => 'string', 'items' => 'array', 'items.*.x' => 'nullable'], ['a' => "\0none", 'items' => [['x' => 1]]]],
    'an excluded parent of item rules' => [['r' => 'exclude_if:flag,1|nullable', 'r.*' => 'array', 'flag' => 'nullable'], ['r' => [1], 'flag' => 1]],
    'an excluded parent of item and field rules' => [['r' => 'exclude_if:flag,1|nullable', 'r.*' => 'array', 'r.*.b' => 'exclude_if:r.*.f,1|nullable|array', 'flag' => 'nullable'], ['r' => [1], 'flag' => 1]],
    'records with flat lists' => [['orders' => 'array', 'orders.*.no' => 'string', 'orders.*.lines' => 'nullable|array', 'orders.*.lines.*.id' => 'integer'], ['orders' => [['no' => 'a', 's' => 1, 'lines' => [['id' => 1, 's' => 2], 3, []]], ['no' => 'b', 'lines' => null], ['lines' => []], 'x', []]]],
    'records with flat lists without a list rule' => [['orders' => 'array', 'orders.*.no' => 'nullable', 'orders.*.lines.*.id' => 'integer'], ['orders' => [['no' => 'a', 'lines' => [['id' => 1, 's' => 2]]], ['no' => 'b', 'lines' => null], ['no' => 'c', 'lines' => []]]]],
];

foreach ($validatedCases as $name => [$rules, $data]) {
    it("returns the validated data of native Laravel for {$name}", function () use ($rules, $data): void {
        expect(RuleSet::from($rules)->validate($data))->toEqual(validator($data, $rules)->validated())
            ->and(RuleSet::from($rules)->check($data)->validated())->toEqual(validator($data, $rules)->validated());
    });
}

it('keeps the keys no rule validates when the host factory includes them, like native', function (): void {
    $factory = resolve(Factory::class);
    $factory->includeUnvalidatedArrayKeys();

    try {
        $rules = ['items' => 'array', 'items.*' => 'array', 'items.*.x' => 'integer'];
        $data = ['items' => [['x' => 1, 'secret' => 'keep']]];

        expect(RuleSet::from($rules)->validate($data))->toBe(validator($data, $rules)->validated())
            ->and(RuleSet::from($rules)->validate($data))->toBe($data);
    } finally {
        $factory->excludeUnvalidatedArrayKeys();
    }
});

it('gives the same validated data in a form request, RuleSet and native Laravel for each() with children()', function (): void {
    $data = [
        'title' => 't',
        'secret' => 'leak',
        'items' => [
            ['sku' => 'A-1', 'qty' => 2, 'meta' => ['color' => 'red', 'secret' => 'leak'], 'secret' => 'leak'],
            ['sku' => 'B-2', 'meta' => null],
        ],
    ];
    $expected = [
        'title' => 't',
        'items' => [
            ['sku' => 'A-1', 'qty' => 2, 'meta' => ['color' => 'red']],
            ['sku' => 'B-2', 'meta' => null],
        ],
    ];

    $request = EachChildrenFluentFormRequest::createFrom(Request::create('/test', 'POST', $data));
    $request->setContainer(app());
    $request->setRedirector(resolve(Redirector::class));
    $request->validateResolved();

    $ruleSet = RuleSet::from(EachChildrenFluentFormRequest::fluentRules());

    expect($request->validated())->toEqual($expected)
        ->and($ruleSet->validate($data))->toEqual($expected)
        ->and($ruleSet->validate($data))->toEqual(validator($data, RuleSet::compile($ruleSet->flattenRules()))->validated());
});
