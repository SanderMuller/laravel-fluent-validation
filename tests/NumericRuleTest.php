<?php declare(strict_types=1);

use SanderMuller\FluentValidation\FluentRule;

// =========================================================================
// NumericRule
// =========================================================================

it('validates numeric with integer and min', function (): void {
    $validator = makeValidator(['age' => 25], ['age' => FluentRule::numeric()->integer()->min(0)]);

    expect($validator->passes())->toBeTrue();
});

it('validates numeric with required', function (): void {
    $validator = makeValidator([], ['age' => FluentRule::numeric()->required()]);

    expect($validator->passes())->toBeFalse();
});

it('validates numeric with nullable', function (): void {
    $validator = makeValidator(['age' => null], ['age' => FluentRule::numeric()->nullable()]);

    expect($validator->passes())->toBeTrue();
});

it('validates numeric with between', function (): void {
    $v = makeValidator(['price' => 15.5], ['price' => FluentRule::numeric()->between(10, 20)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['price' => 25], ['price' => FluentRule::numeric()->between(10, 20)]);
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// NumericRule — min / max / decimal / digits / digitsBetween
// =========================================================================

it('validates numeric with min', function (): void {
    $v = makeValidator(['age' => 18], ['age' => FluentRule::numeric()->min(18)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['age' => 17], ['age' => FluentRule::numeric()->min(18)]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with max', function (): void {
    $v = makeValidator(['age' => 100], ['age' => FluentRule::numeric()->max(120)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['age' => 150], ['age' => FluentRule::numeric()->max(120)]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with decimal', function (): void {
    $v = makeValidator(['price' => '10.50'], ['price' => FluentRule::numeric()->decimal(2)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['price' => '10.5'], ['price' => FluentRule::numeric()->decimal(2)]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with decimal min and max', function (): void {
    $v = makeValidator(['price' => '10.5'], ['price' => FluentRule::numeric()->decimal(1, 3)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['price' => '10.5000'], ['price' => FluentRule::numeric()->decimal(1, 3)]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with digits', function (): void {
    $v = makeValidator(['pin' => 1234], ['pin' => FluentRule::numeric()->digits(4)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['pin' => 123], ['pin' => FluentRule::numeric()->digits(4)]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with digitsBetween', function (): void {
    $v = makeValidator(['code' => 12345], ['code' => FluentRule::numeric()->digitsBetween(4, 6)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['code' => 123], ['code' => FluentRule::numeric()->digitsBetween(4, 6)]);
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// NumericRule — greaterThan / greaterThanOrEqualTo / lessThan / lessThanOrEqualTo
// =========================================================================

it('validates numeric with greaterThan', function (): void {
    $v = makeValidator(['max' => 20, 'min' => 10], ['max' => FluentRule::numeric()->greaterThan('min')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['max' => 5, 'min' => 10], ['max' => FluentRule::numeric()->greaterThan('min')]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with greaterThanOrEqualTo', function (): void {
    $v = makeValidator(['max' => 10, 'min' => 10], ['max' => FluentRule::numeric()->greaterThanOrEqualTo('min')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['max' => 9, 'min' => 10], ['max' => FluentRule::numeric()->greaterThanOrEqualTo('min')]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with lessThan', function (): void {
    $v = makeValidator(['min' => 5, 'max' => 10], ['min' => FluentRule::numeric()->lessThan('max')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['min' => 15, 'max' => 10], ['min' => FluentRule::numeric()->lessThan('max')]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with lessThanOrEqualTo', function (): void {
    $v = makeValidator(['min' => 10, 'max' => 10], ['min' => FluentRule::numeric()->lessThanOrEqualTo('max')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['min' => 11, 'max' => 10], ['min' => FluentRule::numeric()->lessThanOrEqualTo('max')]);
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// NumericRule — multipleOf / maxDigits / minDigits / exactly / same / different
// =========================================================================

it('validates numeric with multipleOf', function (): void {
    $v = makeValidator(['qty' => 15], ['qty' => FluentRule::numeric()->multipleOf(5)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['qty' => 13], ['qty' => FluentRule::numeric()->multipleOf(5)]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with maxDigits', function (): void {
    $v = makeValidator(['num' => 999], ['num' => FluentRule::numeric()->maxDigits(3)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['num' => 10000], ['num' => FluentRule::numeric()->maxDigits(3)]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with minDigits', function (): void {
    $v = makeValidator(['num' => 100], ['num' => FluentRule::numeric()->minDigits(3)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['num' => 10], ['num' => FluentRule::numeric()->minDigits(3)]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with exactly', function (): void {
    $v = makeValidator(['count' => 5], ['count' => FluentRule::numeric()->exactly(5)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['count' => 6], ['count' => FluentRule::numeric()->exactly(5)]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with same', function (): void {
    $v = makeValidator(['a' => 10, 'b' => 10], ['a' => FluentRule::numeric()->same('b')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['a' => 10, 'b' => 20], ['a' => FluentRule::numeric()->same('b')]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with different', function (): void {
    $v = makeValidator(['a' => 10, 'b' => 20], ['a' => FluentRule::numeric()->different('b')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['a' => 10, 'b' => 10], ['a' => FluentRule::numeric()->different('b')]);
    expect($v->passes())->toBeFalse();
});

it('validates numeric with confirmed', function (): void {
    $v = makeValidator(
        ['amount' => 100, 'amount_confirmation' => 100],
        ['amount' => FluentRule::numeric()->confirmed()]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['amount' => 100, 'amount_confirmation' => 200],
        ['amount' => FluentRule::numeric()->confirmed()]
    );
    expect($v->passes())->toBeFalse();
});

it('validates numeric with inArray', function (): void {
    $v = makeValidator(
        ['val' => 2, 'allowed' => [1, 2, 3]],
        ['val' => FluentRule::numeric()->inArray('allowed.*')]
    );
    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['val' => 9, 'allowed' => [1, 2, 3]],
        ['val' => FluentRule::numeric()->inArray('allowed.*')]
    );
    expect($v->passes())->toBeFalse();
});

it('compiles numeric with distinct rule', function (): void {
    $numericRule = FluentRule::numeric()->distinct();
    expect($numericRule->compiledRules())->toBe('numeric|distinct');
});

it('validates numeric with integer strict mode', function (): void {
    $validator = makeValidator(['num' => 5], ['num' => FluentRule::numeric()->integer(true)]);
    expect($validator->passes())->toBeTrue();
});

it('FluentRule::integer(strict: true) rejects numeric strings', function (): void {
    expect(FluentRule::integer(strict: true)->compiledRules())->toBe('numeric|integer:strict');

    $v = makeValidator(['n' => 5], ['n' => FluentRule::integer(strict: true)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['n' => '5'], ['n' => FluentRule::integer(strict: true)]);
    expect($v->passes())->toBeFalse();
});

it('FluentRule::integer() defaults to non-strict and accepts numeric strings', function (): void {
    expect(FluentRule::integer()->compiledRules())->toBe('numeric|integer');

    $v = makeValidator(['n' => '5'], ['n' => FluentRule::integer()]);
    expect($v->passes())->toBeTrue();
});

it('FluentRule::integer() preserves positional (label, message) call signature', function (): void {
    // BC contract: (label, message) must stay the first two positional slots.
    // Message binds to the `integer` sub-rule (the semantically meaningful one);
    // input must pass `numeric` but fail `integer` for the message to surface.
    $rule = FluentRule::integer('Age', 'Must be a whole number.');

    expect($rule->compiledRules())->toBe('numeric|integer');

    $v = makeValidator(['age' => 5.5], ['age' => $rule]);
    expect($v->passes())->toBeFalse()
        ->and($v->errors()
            ->first('age'))
        ->toBe('Must be a whole number.');
});

it('FluentRule::integer(label:, message:, strict: true) routes label, message, and strictness', function (): void {
    $rule = FluentRule::integer(label: 'User Age', message: 'Whole numbers only.', strict: true);

    expect($rule->compiledRules())->toBe('numeric|integer:strict');

    // Non-strict allows '5'; strict rejects it. Message binds to integer:strict.
    $v = makeValidator(['age' => '5'], ['age' => $rule]);
    expect($v->passes())->toBeFalse()
        ->and($v->errors()
            ->first('age'))
        ->toBe('Whole numbers only.');
});

// =========================================================================
// NumericRule — inArrayKeys
// =========================================================================

it('compiles numeric with inArrayKeys rule', function (): void {
    $numericRule = FluentRule::numeric()->inArrayKeys('options.*');
    expect($numericRule->compiledRules())->toBe('numeric|in_array_keys:options.*');
});

// =========================================================================
// NumericRule — positive / negative / nonNegative / nonPositive
// =========================================================================

it('validates positive', function (): void {
    $v = makeValidator(['n' => 1], ['n' => FluentRule::numeric()->positive()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['n' => 0], ['n' => FluentRule::numeric()->positive()]);
    expect($v->passes())->toBeFalse();

    $v = makeValidator(['n' => -1], ['n' => FluentRule::numeric()->positive()]);
    expect($v->passes())->toBeFalse();
});

it('validates negative', function (): void {
    $v = makeValidator(['n' => -1], ['n' => FluentRule::numeric()->negative()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['n' => 0], ['n' => FluentRule::numeric()->negative()]);
    expect($v->passes())->toBeFalse();

    $v = makeValidator(['n' => 1], ['n' => FluentRule::numeric()->negative()]);
    expect($v->passes())->toBeFalse();
});

it('validates nonNegative', function (): void {
    $v = makeValidator(['n' => 0], ['n' => FluentRule::numeric()->nonNegative()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['n' => 5], ['n' => FluentRule::numeric()->nonNegative()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['n' => -1], ['n' => FluentRule::numeric()->nonNegative()]);
    expect($v->passes())->toBeFalse();
});

it('validates nonPositive', function (): void {
    $v = makeValidator(['n' => 0], ['n' => FluentRule::numeric()->nonPositive()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['n' => -5], ['n' => FluentRule::numeric()->nonPositive()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['n' => 1], ['n' => FluentRule::numeric()->nonPositive()]);
    expect($v->passes())->toBeFalse();
});

it('compiles sign helpers', function (): void {
    expect(FluentRule::numeric()->positive()->compiledRules())->toBe('numeric|gt:0')
        ->and(FluentRule::numeric()->negative()->compiledRules())->toBe('numeric|lt:0')
        ->and(FluentRule::numeric()->nonNegative()->compiledRules())->toBe('numeric|gte:0')
        ->and(FluentRule::numeric()->nonPositive()->compiledRules())->toBe('numeric|lte:0');
});
