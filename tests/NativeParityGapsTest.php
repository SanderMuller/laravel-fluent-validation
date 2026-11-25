<?php declare(strict_types=1);

use Illuminate\Validation\Validator;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\RuleSet;

it('validates data keys with a dot or an asterisk like native Laravel', function (): void {
    $data = ['items' => ['a.b' => ['name' => 'x'], 'c*d' => ['name' => '']]];

    expect(validator($data, ['items.*.name' => FluentRule::string()->required()])->errors()->toArray())
        ->toBe(validator($data, ['items.*.name' => 'required|string'])->errors()->toArray())
        ->toHaveKeys(['items.c*d.name']);
});

it('validates an optional field under a data key with a dot like native Laravel', function (): void {
    $data = ['items' => ['a.b' => ['name' => 'xx'], 'c*d' => ['name' => 'yy']]];

    expect(validator($data, ['items.*.name' => FluentRule::string()->max(1)])->errors()->toArray())
        ->toBe(validator($data, ['items.*.name' => 'string|max:1'])->errors()->toArray())
        ->toHaveKeys(['items.a.b.name', 'items.c*d.name']);
});

it('uses the label and the inline message under a data key with a dot', function (): void {
    $data = ['items' => ['a.b' => ['name' => 'xx']]];

    expect(validator($data, ['items.*.name' => FluentRule::string('Item name')->max(1, 'Too long: :attribute')])->errors()->toArray())
        ->toBe(['items.a.b.name' => ['Too long: Item name']]);
});

it('drops unvalidated keys under each() from validated() like native Laravel', function (): void {
    $data = ['groups' => [['rows' => [['start' => 'a', 'extra' => 1]], 'junk' => 2]]];

    $fluent = validator($data, ['groups.*.rows' => FluentRule::array()->each(['start' => FluentRule::string()->required()])]);
    $fluent->excludeUnvalidatedArrayKeys = true;

    $native = validator($data, ['groups.*.rows' => 'array', 'groups.*.rows.*.start' => 'required|string']);
    $native->excludeUnvalidatedArrayKeys = true;

    expect($fluent->validated())->toBe($native->validated());
});

it('drops unvalidated keys under children() and nested each() like native Laravel', function (): void {
    $data = ['order' => ['meta' => ['note' => 'n', 'secret' => 1], 'lines' => [['parts' => [['sku' => 's', 'cost' => 9]], 'junk' => 2]]]];

    $fluent = validator($data, ['order' => FluentRule::array()->required()->children([
        'meta' => FluentRule::array()->children(['note' => FluentRule::string()]),
        'lines' => FluentRule::array()->each(['parts' => FluentRule::array()->each(['sku' => FluentRule::string()])]),
    ])]);

    $native = validator($data, [
        'order' => 'required|array',
        'order.meta' => 'array',
        'order.meta.note' => 'string',
        'order.lines' => 'array',
        'order.lines.*.parts' => 'array',
        'order.lines.*.parts.*.sku' => 'string',
    ]);

    expect($fluent->validated())->toBe($native->validated());
});

it('keeps the same errors when a validator with each() runs twice', function (): void {
    $validator = validator(
        ['rows' => [['start' => ''], 'x']],
        ['rows' => FluentRule::array()->label('Rows')->each(['start' => FluentRule::string()->required()])],
    );

    $first = $validator->errors()->toArray();
    $validator->passes();

    expect($validator->errors()->toArray())->toBe($first)->not->toBeEmpty();
});

it('names the full path of a reference in RuleSet like native Laravel', function (): void {
    $data = ['groups' => [['rows' => [['start' => '2025-06-01', 'end' => '2025-05-01']]]]];
    $rules = ['groups' => FluentRule::array()->required()->each([
        'rows' => FluentRule::array()->required()->each([
            'start' => FluentRule::date()->required(),
            'end' => FluentRule::date()->required()->after('groups.*.rows.*.start'),
        ]),
    ])];

    expect(RuleSet::from($rules)->check($data)->errors()->toArray())
        ->toBe(validator($data, [
            'groups' => 'required|array',
            'groups.*.rows' => 'required|array',
            'groups.*.rows.*.start' => 'required|date',
            'groups.*.rows.*.end' => 'required|date|after:groups.*.rows.*.start',
        ])->errors()->toArray());
});

it('names item fields by the full path in RuleSet like native Laravel', function (): void {
    $data = ['items' => [['name' => '', 'type' => 'a']], 'tags' => ['x', 5]];
    $rules = [
        'items' => FluentRule::array()->required()->each([
            'type' => FluentRule::string(),
            'name' => FluentRule::string()->requiredIf('items.*.type', 'a'),
        ]),
        'tags' => FluentRule::array()->each(FluentRule::string()),
    ];

    expect(RuleSet::from($rules)->check($data)->errors()->toArray())
        ->toBe(validator($data, RuleSet::from($rules)->toArray())->errors()->toArray());
});

it('applies wildcard-keyed messages and attribute names in RuleSet like native Laravel', function (): void {
    $data = ['items' => [['n' => '', 'm' => '']], 'tags' => [5]];
    $rules = [
        'items' => FluentRule::array()->required()->each(['n' => FluentRule::string()->required(), 'm' => FluentRule::string()->required()]),
        'tags' => FluentRule::array()->each(FluentRule::string()),
    ];
    $messages = ['items.*.n.required' => 'Need :attribute', 'tags.*.string' => 'Tag :attribute is text'];
    $attributes = ['items.*.m' => 'Em', 'tags.*' => 'tag'];

    expect(RuleSet::from($rules)->check($data, $messages, $attributes)->errors()->toArray())
        ->toBe(validator($data, RuleSet::from($rules)->toArray(), $messages, $attributes)->errors()->toArray())
        ->toBe([
            'items.0.n' => ['Need items.0.n'],
            'items.0.m' => ['The Em field is required.'],
            'tags.0' => ['Tag tag is text'],
        ]);
});

it('names a referenced field without a rule like native Laravel in RuleSet', function (): void {
    $data = ['items' => [['start_at' => '2025-06-01', 'end_at' => '2025-05-01']]];
    $rules = ['items.*.end_at' => 'date|after:items.*.start_at'];

    expect(RuleSet::from($rules)->check($data)->errors()->toArray())
        ->toBe(validator($data, $rules)->errors()->toArray())
        ->toBe(['items.0.end_at' => ['The items.0.end_at field must be a date after items.0.start at.']]);
});

it('prefers a literal data key with a dot over a nested key like native Laravel', function (): void {
    $data = ['items' => ['a' => ['b' => ['name' => 'ok']], 'a.b' => ['name' => 12]]];

    expect(validator($data, ['items.*.name' => FluentRule::string()])->errors()->toArray())
        ->toBe(validator($data, ['items.*.name' => 'string'])->errors()->toArray())
        ->toHaveKey('items.a.b.name');
});

it('uses a message for one item before the wildcard message in the order Laravel does', function (bool $itemFirst): void {
    $data = ['items' => [['n' => ''], ['n' => '']]];
    $rules = ['items' => FluentRule::array()->each(['n' => FluentRule::string()->required()])];
    $messages = $itemFirst
        ? ['items.0.n.required' => 'first', 'items.*.n.required' => 'all']
        : ['items.*.n.required' => 'all', 'items.0.n.required' => 'first'];

    expect(RuleSet::from($rules)->check($data, $messages)->errors()->toArray())
        ->toBe(validator($data, RuleSet::from($rules)->toArray(), $messages)->errors()->toArray())
        ->toBe(['items.0.n' => [$itemFirst ? 'first' : 'all'], 'items.1.n' => ['all']]);
})->with(['item first' => [true], 'wildcard first' => [false]]);

it('reads a fixed dotted rule key as a nested path like native Laravel', function (): void {
    $data = ['a' => ['b' => 12], 'a.b' => 'ok'];

    expect(validator($data, ['a.b' => FluentRule::string()])->errors()->toArray())
        ->toBe(validator($data, ['a.b' => 'string'])->errors()->toArray())
        ->toHaveKey('a.b');
});

it('uses an attribute name for one item before the wildcard one like native Laravel', function (): void {
    $data = ['items' => [['n' => ''], ['n' => '']]];
    $rules = ['items' => FluentRule::array()->each(['n' => FluentRule::string()->required()])];
    $attributes = ['items.*.n' => 'all', 'items.0.n' => 'first'];

    expect(RuleSet::from($rules)->check($data, [], $attributes)->errors()->toArray())
        ->toBe(validator($data, RuleSet::from($rules)->toArray(), [], $attributes)->errors()->toArray())
        ->toBe(['items.0.n' => ['The first field is required.'], 'items.1.n' => ['The all field is required.']]);
});

it('gives an after() callback the same validated() as native Laravel', function (): void {
    $data = ['rows' => [['n' => 'ok', 'secret' => 1]]];
    $seen = [];

    $fluent = validator($data, ['rows' => FluentRule::array()->each(['n' => FluentRule::string()])]);
    $fluent->after(function (Validator $validator) use (&$seen): void {
        $seen['fluent'] = $validator->validated();
    });
    $native = validator($data, ['rows' => 'array', 'rows.*.n' => 'string']);
    $native->after(function (Validator $validator) use (&$seen): void {
        $seen['native'] = $validator->validated();
    });

    $fluent->passes();
    $native->passes();

    expect($seen['fluent'])->toBe($seen['native'])->toBe(['rows' => [['n' => 'ok']]]);
});

it('finds a dotted data key under a later wildcard like native Laravel', function (): void {
    $data = ['items' => ['a' => ['b.c' => ['name' => 12]]]];

    expect(validator($data, ['items.*.*.name' => FluentRule::string()])->errors()->toArray())
        ->toBe(validator($data, ['items.*.*.name' => 'string'])->errors()->toArray())
        ->toHaveKey('items.a.b.c.name');
});

/**
 * @param  array<string, mixed>  $data
 * @param  array<string, mixed>  $rules
 * @return array<string, mixed>
 */
function ruleSetOutcome(array $data, array $rules): array
{
    $result = RuleSet::from($rules)->check($data);

    return $result->fails() ? ['errors' => $result->errors()->toArray()] : ['validated' => $result->validated()];
}

/**
 * @param  array<string, mixed>  $data
 * @param  array<string, mixed>  $rules
 * @return array<string, mixed>
 */
function nativeOutcome(array $data, array $rules): array
{
    $validator = validator($data, $rules);

    return $validator->fails() ? ['errors' => $validator->errors()->toArray()] : ['validated' => $validator->validated()];
}

/** @return array<string, array{array<string, mixed>, array<string, mixed>}> */
function resolvedDateCases(): array
{
    return [
        // Laravel parses `x.1.0.from` and `x.now.from` as dates, not as fields.
        'a numeric key in the prefix' => [
            ['x' => [1 => [['from' => '2025-01-05', 'to' => '2025-01-10']]]],
            ['x.1.*.from' => 'date', 'x.1.*.to' => 'date|after:x.1.*.from'],
        ],
        'the item key now' => [
            ['x' => ['now' => ['from' => '2099-01-10', 'to' => '2099-01-05']]],
            ['x.*.from' => 'date', 'x.*.to' => 'date|after:x.*.from'],
        ],
        'the item key now, before now' => [
            ['x' => ['now' => ['from' => '2020-01-01', 'to' => '2021-01-01']]],
            ['x.*.from' => 'date', 'x.*.to' => 'date|after:x.*.from'],
        ],
        'the item key just now, which Carbon reads as now' => [
            ['x' => ['just now' => ['from' => '2020-01-01', 'to' => '2021-01-01']]],
            ['x.*.from' => 'date', 'x.*.to' => 'date|after:x.*.from'],
        ],
        'the item key today' => [
            ['x' => ['today' => ['from' => '2099-01-10', 'to' => '2099-01-05']]],
            ['x.*.from' => 'date', 'x.*.to' => 'date|after:x.*.from'],
        ],
        'the item key today in a fluent rule' => [
            ['x' => ['today' => ['from' => '2099-01-10', 'to' => '2099-01-05']]],
            ['x' => FluentRule::array()->each(['from' => FluentRule::date(), 'to' => FluentRule::date()->after('x.*.from')])],
        ],
        // Laravel reads these paths as fields.
        'a named prefix' => [
            ['a' => ['b' => [['from' => '2025-01-10', 'to' => '2025-01-05']]]],
            ['a.b.*.from' => 'date', 'a.b.*.to' => 'date|after:a.b.*.from'],
        ],
        'the item key monday' => [
            ['events' => ['monday' => ['from' => '2099-01-10', 'to' => '2099-01-05']]],
            ['events.*.from' => 'date', 'events.*.to' => 'date|after:events.*.from'],
        ],
    ];
}

it('reads a resolved date reference as a date when Laravel does in RuleSet', function (string $case): void {
    [$data, $rules] = resolvedDateCases()[$case];

    expect(ruleSetOutcome($data, $rules))->toBe(nativeOutcome($data, $rules));
})->with(array_keys(resolvedDateCases()));

/**
 * The keys come in as strings, as from a rule key built at run time. PHP
 * stores a key such as '1' as an integer.
 *
 * @return array<string, array{array<string, mixed>, array<string, mixed>}>
 */
function integerKeyCases(string $one = '1', string $two = '2', string $minusOne = '-1'): array
{
    return [
        'valid' => [[$one => [['n' => 5]]], [$one . '.*.n' => 'required|integer']],
        'invalid' => [[$one => [['n' => 'x']]], [$one . '.*.n' => 'required|integer']],
        'a negative key next to a string key' => [[$minusOne => [['n' => 'x']], 'a' => [['n' => 5]]], [$minusOne . '.*.n' => 'integer', 'a.*.n' => 'integer']],
        'a parent rule and a nested wildcard' => [[$two => [['rows' => [['n' => 'x']]]]], [$two => 'array', $two . '.*.rows.*.n' => 'integer']],
        'an integer key without a wildcard' => [[$one => 'x', 'items' => [['n' => 5]]], [$one => 'integer', 'items.*.n' => 'integer']],
        'an integer key alone' => [[$one => 'x'], [$one => 'integer']],
        'a parent that fails its own rule' => [[$two => [['n' => 'x']]], [$two => 'array|min:2', $two . '.*.n' => 'integer']],
        'a key with a leading zero' => [['01' => [['n' => 'x']]], ['01.*.n' => 'integer']],
    ];
}

it('validates an integer top-level key like native Laravel in RuleSet', function (string $case): void {
    [$data, $rules] = integerKeyCases()[$case];

    expect(ruleSetOutcome($data, $rules))->toBe(nativeOutcome($data, $rules));
})->with(array_keys(integerKeyCases()));

it('uses the label of a fluent rule under an integer top-level key in RuleSet', function (string $key): void {
    $rule = FluentRule::array()->required()->each(['n' => FluentRule::string()->max(1)->label('Number')]);

    expect(RuleSet::from([$key => $rule])->check([$key => [['n' => 'xx']]])->errors()->toArray())
        ->toBe([$key . '.0.n' => ['The Number field must not be greater than 1 characters.']]);
})->with(['an integer key' => '1', 'a string key' => 'a']);

it('rejects an unknown field under an integer top-level key in RuleSet', function (string $key): void {
    $result = RuleSet::from([$key . '.*.n' => 'integer'])->failOnUnknownFields()->check([$key => [['n' => 5]], 'extra' => 1]);

    expect($result->errors()->keys())->toBe(['extra']);
})->with(['an integer key' => '1']);
