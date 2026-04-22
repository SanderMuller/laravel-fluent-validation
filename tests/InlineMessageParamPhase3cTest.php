<?php declare(strict_types=1);

use Illuminate\Validation\Rules\Dimensions;
use SanderMuller\FluentValidation\FluentRule;

// =========================================================================
// Phase 3c — `message:` parity for ArrayRule, DateRule, FileRule, ImageRule.
// =========================================================================

dataset('phase3c_array', [
    'min' => [
        fn () => FluentRule::array()->min(1, message: 'x'),
        fn () => FluentRule::array()->min(1)->message('x'),
        ['min' => 'x'],
    ],
    'max' => [
        fn () => FluentRule::array()->max(10, message: 'x'),
        fn () => FluentRule::array()->max(10)->message('x'),
        ['max' => 'x'],
    ],
    'between' => [
        fn () => FluentRule::array()->between(1, 5, message: 'x'),
        fn () => FluentRule::array()->between(1, 5)->message('x'),
        ['between' => 'x'],
    ],
    'exactly' => [
        fn () => FluentRule::array()->exactly(3, message: 'x'),
        fn () => FluentRule::array()->exactly(3)->message('x'),
        ['size' => 'x'],
    ],
    'list' => [
        fn () => FluentRule::array()->list(message: 'x'),
        fn () => FluentRule::array()->list()->message('x'),
        ['list' => 'x'],
    ],
    'distinct' => [
        fn () => FluentRule::array()->distinct(message: 'x'),
        fn () => FluentRule::array()->distinct()->message('x'),
        ['distinct' => 'x'],
    ],
]);

dataset('phase3c_date', [
    'before' => [
        fn () => FluentRule::date()->before('2026-12-31', message: 'x'),
        fn () => FluentRule::date()->before('2026-12-31')->message('x'),
        ['before' => 'x'],
    ],
    'after' => [
        fn () => FluentRule::date()->after('2020-01-01', message: 'x'),
        fn () => FluentRule::date()->after('2020-01-01')->message('x'),
        ['after' => 'x'],
    ],
    'beforeOrEqual' => [
        fn () => FluentRule::date()->beforeOrEqual('2026-12-31', message: 'x'),
        fn () => FluentRule::date()->beforeOrEqual('2026-12-31')->message('x'),
        ['before_or_equal' => 'x'],
    ],
    'afterOrEqual' => [
        fn () => FluentRule::date()->afterOrEqual('2020-01-01', message: 'x'),
        fn () => FluentRule::date()->afterOrEqual('2020-01-01')->message('x'),
        ['after_or_equal' => 'x'],
    ],
    'beforeToday' => [
        fn () => FluentRule::date()->beforeToday(message: 'x'),
        fn () => FluentRule::date()->beforeToday()->message('x'),
        ['before' => 'x'],
    ],
    'past' => [
        fn () => FluentRule::date()->past(message: 'x'),
        fn () => FluentRule::date()->past()->message('x'),
        ['before' => 'x'],
    ],
    'dateEquals' => [
        fn () => FluentRule::date()->dateEquals('2026-04-22', message: 'x'),
        fn () => FluentRule::date()->dateEquals('2026-04-22')->message('x'),
        ['date_equals' => 'x'],
    ],
    'same' => [
        fn () => FluentRule::date()->same('other', message: 'x'),
        fn () => FluentRule::date()->same('other')->message('x'),
        ['same' => 'x'],
    ],
]);

dataset('phase3c_file', [
    'min' => [
        fn () => FluentRule::file()->min(100, message: 'x'),
        fn () => FluentRule::file()->min(100)->message('x'),
        ['min' => 'x'],
    ],
    'max' => [
        fn () => FluentRule::file()->max('2mb', message: 'x'),
        fn () => FluentRule::file()->max('2mb')->message('x'),
        ['max' => 'x'],
    ],
    'between' => [
        fn () => FluentRule::file()->between(1, '2mb', message: 'x'),
        fn () => FluentRule::file()->between(1, '2mb')->message('x'),
        ['between' => 'x'],
    ],
    'exactly' => [
        fn () => FluentRule::file()->exactly(500, message: 'x'),
        fn () => FluentRule::file()->exactly(500)->message('x'),
        ['size' => 'x'],
    ],
]);

it('Phase 3c ArrayRule: inline message: matches chained ->message()', function (
    Closure $inline,
    Closure $chained,
    array $expected,
): void {
    $inlineRule = $inline();
    $chainedRule = $chained();

    expect($inlineRule->getCustomMessages())->toBe($chainedRule->getCustomMessages())
        ->and($inlineRule->getCustomMessages())->toBe($expected);
})->with('phase3c_array');

it('Phase 3c DateRule: inline message: matches chained ->message()', function (
    Closure $inline,
    Closure $chained,
    array $expected,
): void {
    $inlineRule = $inline();
    $chainedRule = $chained();

    expect($inlineRule->getCustomMessages())->toBe($chainedRule->getCustomMessages())
        ->and($inlineRule->getCustomMessages())->toBe($expected);
})->with('phase3c_date');

it('Phase 3c FileRule: inline message: matches chained ->message()', function (
    Closure $inline,
    Closure $chained,
    array $expected,
): void {
    $inlineRule = $inline();
    $chainedRule = $chained();

    expect($inlineRule->getCustomMessages())->toBe($chainedRule->getCustomMessages())
        ->and($inlineRule->getCustomMessages())->toBe($expected);
})->with('phase3c_file');

// =========================================================================
// DateRule composite methods bind message: to the SECOND sub-rule.
// =========================================================================

it('DateRule::between(message: ...) binds to before, not after', function (): void {
    $rule = FluentRule::date()->between('2020-01-01', '2026-12-31', message: 'Out of range.');

    expect($rule->getCustomMessages())->toBe(['before' => 'Out of range.']);
});

it('DateRule::between + messageFor(after) targets the first sub-rule', function (): void {
    $rule = FluentRule::date()
        ->between('2020-01-01', '2026-12-31', message: 'Must be before end.')
        ->messageFor('after', 'Must be after start.');

    expect($rule->getCustomMessages())->toBe([
        'before' => 'Must be before end.',
        'after' => 'Must be after start.',
    ]);
});

it('DateRule::betweenOrEqual(message: ...) binds to before_or_equal', function (): void {
    $rule = FluentRule::date()->betweenOrEqual('2020-01-01', '2026-12-31', message: 'Out of range.');

    expect($rule->getCustomMessages())->toBe(['before_or_equal' => 'Out of range.']);
});

// =========================================================================
// ImageRule — all dimension-wrapping methods bind to 'dimensions' key.
// =========================================================================

it('ImageRule::width(message: ...) binds to dimensions', function (): void {
    $rule = FluentRule::image()->width(100, message: 'Bad width.');

    expect($rule->getCustomMessages())->toBe(['dimensions' => 'Bad width.']);
});

it('ImageRule::dimensions(Dimensions, message: ...) binds to dimensions', function (): void {
    $rule = FluentRule::image()->dimensions(new Dimensions(['ratio' => '16/9']), message: 'Wrong aspect.');

    expect($rule->getCustomMessages())->toBe(['dimensions' => 'Wrong aspect.']);
});

it('ImageRule chained dimension methods: last-write-wins on dimensions key', function (): void {
    $rule = FluentRule::image()
        ->minWidth(100, message: 'too narrow')
        ->maxWidth(500, message: 'too wide');

    // Both bind to 'dimensions' key; last write wins.
    expect($rule->getCustomMessages())->toBe(['dimensions' => 'too wide']);
});

// =========================================================================
// Live-validator smoke.
// =========================================================================

it('ArrayRule::max(message: ...) surfaces in validation', function (): void {
    $v = makeValidator(
        ['tags' => ['a', 'b', 'c']],
        ['tags' => FluentRule::array()->max(2, message: 'Too many.')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('tags'))->toBe('Too many.');
});

it('DateRule::before(message: ...) surfaces in validation', function (): void {
    $v = makeValidator(
        ['start' => '2030-01-01'],
        ['start' => FluentRule::date()->before('2026-12-31', message: 'Must be before 2027.')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('start'))->toBe('Must be before 2027.');
});
