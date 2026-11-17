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
