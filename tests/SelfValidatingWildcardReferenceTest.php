<?php declare(strict_types=1);

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Translation\PotentiallyTranslatedString;
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

it('resolves a sibling reference in each() inside children() under a wildcard like native Laravel', function (array $rows): void {
    $data = ['groups' => [['rows' => $rows]]];

    $fluent = ['groups.*' => FluentRule::array()->children([
        'rows' => FluentRule::array()->each([
            'start' => FluentRule::date()->required(),
            'end' => FluentRule::date()->required()->after('groups.*.rows.*.start'),
        ]),
    ])];

    expect(validator($data, $fluent)->errors()->toArray())
        ->toBe(validator($data, [
            'groups.*.rows' => 'array',
            'groups.*.rows.*.start' => 'required|date',
            'groups.*.rows.*.end' => 'required|date|after:groups.*.rows.*.start',
        ])->errors()->toArray());
})->with([
    'valid' => [[['start' => '2025-06-01', 'end' => '2025-07-01']]],
    'invalid' => [[['start' => '2025-06-01', 'end' => '2025-07-01'], ['start' => '2025-06-01', 'end' => '2025-05-01']]],
]);

it('names a reference to an index that the data does not have like native Laravel', function (): void {
    $data = [
        'items' => [['end_date' => '2025-07-01']],
        'other' => [1 => ['start_date' => '2025-06-01']],
    ];

    expect(validator($data, ['items.*.end_date' => FluentRule::date()->before('other.*.start_date'), 'other.*.start_date' => 'date'])->errors()->toArray())
        ->toBe(validator($data, ['items.*.end_date' => 'date|before:other.*.start_date', 'other.*.start_date' => 'date'])->errors()->toArray())
        ->toHaveKeys(['items.0.end_date']);
});

it('formats a rule object under a wildcard inside a segment like native Laravel', function (): void {
    $data = ['items' => ['first_name' => '']];
    $formatter = static fn (string $attribute): string => 'X' . $attribute;

    $fluent = validator($data, ['items.*_name' => FluentRule::string()->required()]);
    $fluent->setImplicitAttributesFormatter($formatter);

    $native = validator($data, ['items.*_name' => 'required|string']);
    $native->setImplicitAttributesFormatter($formatter);

    expect($fluent->errors()->toArray())
        ->toBe($native->errors()->toArray())
        ->toBe(['items.first_name' => ['The Xitems.first_name field is required.']]);
});

it('escapes raw data that user code passes to setData() like native Laravel', function (): void {
    $data = ['a.b' => 'x', 'email' => null];
    $messages = [];

    FluentRule::string()->requiredWith('a.b')
        ->setValidator(validator([], []))
        ->setData($data)
        ->validate('email', null, static function (string $message) use (&$messages): PotentiallyTranslatedString {
            $messages[] = $message;

            return new PotentiallyTranslatedString($message, resolve(Translator::class));
        });

    expect($messages)->toBe(validator($data, ['email' => 'string|required_with:a.b'])->errors()->get('email'));
});

// A rule object skips its inner validator when a fast check proves the value
// passes. These cases decide a field reference or a date parameter there.
dataset('rule object fast-check references', [
    'different with a parameter that parses as a date' => [
        ['today' => 'x', 'items' => [['a' => 'x'], ['a' => 'y']]],
        'items.*.a',
        static fn () => FluentRule::string()->different('today'),
        'string|different:today',
    ],
    'different with a date-like parameter next to a sibling reference' => [
        ['today' => 'x', 'items' => [['a' => 'x', 'b' => 'x'], ['a' => 'y', 'b' => 'y']]],
        'items.*.a',
        static fn () => FluentRule::string()->same('items.*.b')->different('today'),
        'string|same:items.*.b|different:today',
    ],
    'same with a wildcard sibling' => [
        ['items' => [['a' => 'x', 'b' => 'x'], ['a' => 'x', 'b' => 'y']]],
        'items.*.a',
        static fn () => FluentRule::string()->same('items.*.b'),
        'string|same:items.*.b',
    ],
    'after a sibling that differs per item' => [
        ['items' => [['s' => '2025-06-01', 'e' => '2025-07-01'], ['s' => '2025-08-01', 'e' => '2025-07-01']]],
        'items.*.e',
        static fn () => FluentRule::date()->after('items.*.s'),
        'date|after:items.*.s',
    ],
    'different with an escaped dot' => [
        ['meta.code' => 'x', 'a' => 'x'],
        'a',
        static fn () => FluentRule::string()->different('meta\.code'),
        'string|different:meta\.code',
    ],
    'different with an escaped dot under a wildcard' => [
        ['meta.code' => 'x', 'items' => [['a' => 'x'], ['a' => 'y']]],
        'items.*.a',
        static fn () => FluentRule::string()->different('meta\.code'),
        'string|different:meta\.code',
    ],
    'after an escaped dot' => [
        ['meta.start' => '2025-08-01', 'e' => '2025-07-01'],
        'e',
        static fn () => FluentRule::date()->after('meta\.start'),
        'date|after:meta\.start',
    ],
    'same with a nested field' => [
        ['meta' => ['copy' => 'x'], 'a' => 'x', 'b' => 'y'],
        'a',
        static fn () => FluentRule::string()->same('meta.copy'),
        'string|same:meta.copy',
    ],
    'different with a nested field' => [
        ['meta' => ['copy' => 'x'], 'a' => 'x'],
        'a',
        static fn () => FluentRule::string()->different('meta.copy'),
        'string|different:meta.copy',
    ],
    'different with two fields' => [
        ['a' => 'z', 'b' => 'x', 'f' => 'x'],
        'f',
        static fn () => FluentRule::string()->rule('different:a,b'),
        'string|different:a,b',
    ],
    'gt with a number' => [
        ['n' => 3],
        'n',
        static fn () => FluentRule::numeric()->greaterThan('5'),
        'numeric|gt:5',
    ],
    'after a missing sibling' => [
        ['items' => [['e' => '2025-07-01']]],
        'items.*.e',
        static fn () => FluentRule::date()->after('items.*.s'),
        'date|after:items.*.s',
    ],
    'after today' => [
        ['items' => [['e' => '2000-07-01'], ['e' => '2999-07-01']]],
        'items.*.e',
        static fn () => FluentRule::date()->after('today'),
        'date|after:today',
    ],
]);

it('decides a field reference in the rule object fast check like native Laravel', function (array $data, string $key, Closure $fluent, string $native): void {
    $expected = validator($data, [$key => $native])->errors()->toArray();

    expect(validator($data, [$key => $fluent()])->errors()->toArray())->toBe($expected);
})->with('rule object fast-check references');

it('reads the referenced values again when one rule object validates new data', function (): void {
    $rule = FluentRule::date()->after('items.*.s');
    $passing = ['items' => [['s' => '2025-06-01', 'e' => '2025-07-01']]];
    $failing = ['items' => [['s' => '2025-08-01', 'e' => '2025-07-01']]];

    expect(validator($passing, ['items.*.e' => $rule])->passes())->toBeTrue()
        ->and(validator($failing, ['items.*.e' => $rule])->errors()->toArray())
        ->toBe(validator($failing, ['items.*.e' => 'date|after:items.*.s'])->errors()->toArray());
});

it('decides one rule object under two wildcard keys like native Laravel', function (): void {
    $rule = FluentRule::string()->same('items.*.b');
    $data = ['items' => [['a' => 'x', 'b' => 'x']], 'other' => [['a' => 'y']]];

    expect(validator($data, ['items.*.a' => $rule, 'other.*.a' => $rule])->errors()->toArray())
        ->toBe(validator($data, ['items.*.a' => 'string|same:items.*.b', 'other.*.a' => 'string|same:items.*.b'])->errors()->toArray())
        ->toHaveKey('other.0.a');
});

it('uses the label and the message of a shared each() rule for every item', function (): void {
    $rules = ['items' => FluentRule::array()->each(FluentRule::string('Item name')->max(1, 'Too long: :attribute'))];

    expect(RuleSet::from($rules)->check(['items' => ['xx', 'yy']])->errors()->toArray())
        ->toBe(['items.0' => ['Too long: Item name'], 'items.1' => ['Too long: Item name']]);
});
