<?php declare(strict_types=1);

use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\RuleSet;

// A rule object under a wildcard key in a plain Validator::make() validates
// one concrete attribute, such as `events.0.end_date`. A parameter that names
// a wildcard sibling, such as `after:events.*.start_date`, must resolve
// against the same item, as it does for native string rules.

dataset('wildcard sibling references', [
    'after' => [
        'events.*.end_date',
        static fn () => FluentRule::date()->required()->after('events.*.start_date'),
        'required|date|after:events.*.start_date',
        ['start_date' => '2025-06-01', 'end_date' => '2025-07-01'],
        ['start_date' => '2025-06-01', 'end_date' => '2025-05-01'],
    ],
    'before' => [
        'events.*.deadline',
        static fn () => FluentRule::date()->required()->before('events.*.start_date'),
        'required|date|before:events.*.start_date',
        ['start_date' => '2025-06-01', 'deadline' => '2025-05-01'],
        ['start_date' => '2025-06-01', 'deadline' => '2025-07-01'],
    ],
    'gte' => [
        'events.*.max',
        static fn () => FluentRule::numeric()->required()->greaterThanOrEqualTo('events.*.min'),
        'required|numeric|gte:events.*.min',
        ['min' => 5, 'max' => 10],
        ['min' => 5, 'max' => 1],
    ],
    'required_unless' => [
        'events.*.end_date',
        static fn () => FluentRule::string()->requiredUnless('events.*.type', 'open'),
        'required_unless:events.*.type,open|string',
        ['type' => 'open'],
        ['type' => 'closed'],
    ],
]);

it('resolves a wildcard sibling reference in a rule object like native Laravel', function (string $key, Closure $fluent, string $native, array $valid, array $invalid): void {
    foreach ([$valid, $invalid] as $item) {
        $data = ['events' => [$item, $item]];

        $expected = validator($data, [$key => $native])->errors()->toArray();
        $actual = validator($data, [$key => $fluent()])->errors()->toArray();

        expect($actual)->toBe($expected, 'item: ' . json_encode($item));
    }

    expect(validator(['events' => [$invalid]], [$key => $native])->fails())->toBeTrue();
})->with('wildcard sibling references');

it('passes valid data through Validator::make() with the toArray() output of a rule set', function (): void {
    $rules = RuleSet::from([
        'events' => FluentRule::array()->required()->each([
            'start_date' => FluentRule::date()->required(),
            'deadline' => FluentRule::date()->required()->before('events.*.start_date'),
        ]),
    ])->toArray();

    $data = ['events' => [
        ['start_date' => '2025-06-01', 'deadline' => '2025-05-01'],
        ['start_date' => '2025-06-02', 'deadline' => '2025-05-02'],
    ]];

    expect(validator($data, $rules)->errors()->toArray())
        ->toBeEmpty();
});

dataset('children under a wildcard', [
    'top-level wildcard' => [
        '*',
        static fn () => FluentRule::array()->children(['sort_order' => FluentRule::integer()->required()->min(0)]),
        ['*.sort_order' => 'required|integer|min:0'],
        [['title' => 'x'], ['sort_order' => -1]],
    ],
    'wildcard with a prefix' => [
        'items.*',
        static fn () => FluentRule::array()->children(['sort_order' => FluentRule::integer()->required()]),
        ['items.*.sort_order' => 'required|integer'],
        ['items' => [['title' => 'x'], ['sort_order' => 1.5]]],
    ],
    'nested children' => [
        'items.*',
        static fn () => FluentRule::array()->children([
            'meta' => FluentRule::array()->required()->children(['sort_order' => FluentRule::integer()->required()]),
        ]),
        ['items.*.meta' => 'required|array', 'items.*.meta.sort_order' => 'required|integer'],
        ['items' => [['meta' => ['x' => 1]], ['meta' => ['sort_order' => 1.5]]]],
    ],
    'fixed parent, not a wildcard' => [
        'address',
        static fn () => FluentRule::array()->required()->children(['zip_code' => FluentRule::string()->required()]),
        ['address' => 'required|array', 'address.zip_code' => 'required|string'],
        ['address' => ['street' => 'x']],
    ],
]);

it('names a children() field under a wildcard like native Laravel', function (string $key, Closure $fluent, array $native, array $data): void {
    expect(validator($data, [$key => $fluent()])->errors()->toArray())
        ->toBe(validator($data, $native)->errors()->toArray())
        ->not->toBeEmpty();
})->with('children under a wildcard');

it('resolves a sibling reference in children() under a top-level wildcard like native Laravel', function (): void {
    $data = [
        ['start_date' => '2025-06-01', 'end_date' => '2025-07-01'],
        ['start_date' => '2025-06-01', 'end_date' => '2025-05-01'],
    ];

    $fluent = ['*' => FluentRule::array()->children([
        'start_date' => FluentRule::date()->required(),
        'end_date' => FluentRule::date()->required()->after('*.start_date'),
    ])];

    expect(validator($data, $fluent)->errors()->toArray())
        ->toBe(validator($data, ['*.start_date' => 'required|date', '*.end_date' => 'required|date|after:*.start_date'])->errors()->toArray())
        ->toHaveKeys(['1.end_date']);
});

it('checks a rule under a top-level wildcard against the other items like native Laravel', function (): void {
    $data = ['a', 'a'];

    expect(validator($data, ['*' => FluentRule::string()->distinct()])->errors()->toArray())
        ->toBe(validator($data, ['*' => 'string|distinct'])->errors()->toArray())
        ->toHaveKeys(['0', '1']);
});

it('formats a children() field under a wildcard with the implicit attributes formatter', function (): void {
    $data = ['items' => [['title' => 'x']]];
    $formatter = static fn (string $attribute): string => 'X' . $attribute;

    $fluent = validator($data, ['items.*' => FluentRule::array()->children(['sort_order' => FluentRule::integer()->required()])]);
    $fluent->setImplicitAttributesFormatter($formatter);

    $native = validator($data, ['items.*.sort_order' => 'required|integer']);
    $native->setImplicitAttributesFormatter($formatter);

    expect($fluent->errors()->toArray())
        ->toBe($native->errors()->toArray())
        ->toBe(['items.0.sort_order' => ['The Xitems.0.sort_order field is required.']]);
});
