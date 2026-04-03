<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use SanderMuller\FluentValidation\FluentRule;

// =========================================================================
// DateRule
// =========================================================================

it('validates date with after', function (): void {
    $validator = makeValidator(
        ['date' => '2099-01-01'],
        ['date' => FluentRule::date()->after('today')]
    );

    expect($validator->passes())->toBeTrue();
});

it('validates date with required', function (): void {
    $validator = makeValidator([], ['date' => FluentRule::date()->required()]);

    expect($validator->passes())->toBeFalse();
});

it('validates date with nullable', function (): void {
    $validator = makeValidator(['date' => null], ['date' => FluentRule::date()->nullable()]);

    expect($validator->passes())->toBeTrue();
});

it('validates date with format', function (): void {
    $v = makeValidator(
        ['date' => '01/15/2025'],
        ['date' => FluentRule::date()->format('m/d/Y')]
    );

    expect($v->passes())->toBeTrue();

    $v = makeValidator(
        ['date' => '2025-01-15'],
        ['date' => FluentRule::date()->format('m/d/Y')]
    );

    expect($v->passes())->toBeFalse();
});

it('validates datetime shortcut', function (): void {
    $validator = makeValidator(
        ['timestamp' => '2025-01-15 14:30:00'],
        ['timestamp' => FluentRule::dateTime()]
    );

    expect($validator->passes())->toBeTrue();
});

// =========================================================================
// DateRule — before / beforeToday / afterToday / todayOrBefore / todayOrAfter
// =========================================================================

it('validates date with before', function (): void {
    $v = makeValidator(['d' => '2020-01-01'], ['d' => FluentRule::date()->before('2025-01-01')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2030-01-01'], ['d' => FluentRule::date()->before('2025-01-01')]);
    expect($v->passes())->toBeFalse();
});

it('validates date with beforeToday', function (): void {
    $v = makeValidator(['d' => '2000-01-01'], ['d' => FluentRule::date()->beforeToday()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2099-01-01'], ['d' => FluentRule::date()->beforeToday()]);
    expect($v->passes())->toBeFalse();
});

it('validates date with afterToday', function (): void {
    $v = makeValidator(['d' => '2099-01-01'], ['d' => FluentRule::date()->afterToday()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2000-01-01'], ['d' => FluentRule::date()->afterToday()]);
    expect($v->passes())->toBeFalse();
});

it('validates date with todayOrBefore', function (): void {
    $validator = makeValidator(['d' => '2000-01-01'], ['d' => FluentRule::date()->todayOrBefore()]);
    expect($validator->passes())->toBeTrue();
});

it('validates date with todayOrAfter', function (): void {
    $validator = makeValidator(['d' => '2099-01-01'], ['d' => FluentRule::date()->todayOrAfter()]);
    expect($validator->passes())->toBeTrue();
});

// =========================================================================
// DateRule — past / future / nowOrPast / nowOrFuture
// =========================================================================

it('validates date with past', function (): void {
    $v = makeValidator(['d' => '2000-01-01 00:00:00'], ['d' => FluentRule::date()->past()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2099-01-01 00:00:00'], ['d' => FluentRule::date()->past()]);
    expect($v->passes())->toBeFalse();
});

it('validates date with future', function (): void {
    $v = makeValidator(['d' => '2099-01-01 00:00:00'], ['d' => FluentRule::date()->future()]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2000-01-01 00:00:00'], ['d' => FluentRule::date()->future()]);
    expect($v->passes())->toBeFalse();
});

it('validates date with nowOrPast', function (): void {
    $validator = makeValidator(['d' => '2000-01-01 00:00:00'], ['d' => FluentRule::date()->nowOrPast()]);
    expect($validator->passes())->toBeTrue();
});

it('validates date with nowOrFuture', function (): void {
    $validator = makeValidator(['d' => '2099-01-01 00:00:00'], ['d' => FluentRule::date()->nowOrFuture()]);
    expect($validator->passes())->toBeTrue();
});

// =========================================================================
// DateRule — beforeOrEqual / afterOrEqual / between / betweenOrEqual / dateEquals
// =========================================================================

it('validates date with beforeOrEqual', function (): void {
    $v = makeValidator(['d' => '2025-01-01'], ['d' => FluentRule::date()->beforeOrEqual('2025-01-01')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2025-01-02'], ['d' => FluentRule::date()->beforeOrEqual('2025-01-01')]);
    expect($v->passes())->toBeFalse();
});

it('validates date with afterOrEqual', function (): void {
    $v = makeValidator(['d' => '2025-01-01'], ['d' => FluentRule::date()->afterOrEqual('2025-01-01')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2024-12-31'], ['d' => FluentRule::date()->afterOrEqual('2025-01-01')]);
    expect($v->passes())->toBeFalse();
});

it('validates date with between', function (): void {
    $v = makeValidator(['d' => '2025-06-15'], ['d' => FluentRule::date()->between('2025-01-01', '2025-12-31')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2024-06-15'], ['d' => FluentRule::date()->between('2025-01-01', '2025-12-31')]);
    expect($v->passes())->toBeFalse();
});

it('validates date with betweenOrEqual', function (): void {
    $v = makeValidator(['d' => '2025-01-01'], ['d' => FluentRule::date()->betweenOrEqual('2025-01-01', '2025-12-31')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2024-12-31'], ['d' => FluentRule::date()->betweenOrEqual('2025-01-01', '2025-12-31')]);
    expect($v->passes())->toBeFalse();
});

it('validates date with dateEquals', function (): void {
    $v = makeValidator(['d' => '2025-01-15'], ['d' => FluentRule::date()->dateEquals('2025-01-15')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2025-01-16'], ['d' => FluentRule::date()->dateEquals('2025-01-15')]);
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// DateRule — same / different
// =========================================================================

it('validates date with same', function (): void {
    $v = makeValidator(['d' => '2025-01-01', 'other' => '2025-01-01'], ['d' => FluentRule::date()->same('other')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2025-01-01', 'other' => '2025-01-02'], ['d' => FluentRule::date()->same('other')]);
    expect($v->passes())->toBeFalse();
});

it('validates date with different', function (): void {
    $v = makeValidator(['d' => '2025-01-01', 'other' => '2025-01-02'], ['d' => FluentRule::date()->different('other')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2025-01-01', 'other' => '2025-01-01'], ['d' => FluentRule::date()->different('other')]);
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// DateRule — DateTimeInterface arguments
// =========================================================================

it('validates date with DateTimeInterface argument', function (): void {
    $cutoff = CarbonImmutable::parse('2025-06-01');

    $v = makeValidator(['d' => '2025-01-01'], ['d' => FluentRule::date()->before($cutoff)]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['d' => '2025-12-01'], ['d' => FluentRule::date()->before($cutoff)]);
    expect($v->passes())->toBeFalse();
});

it('validates date with DateTimeInterface and custom format', function (): void {
    $cutoff = CarbonImmutable::parse('2025-06-01');

    $validator = makeValidator(['d' => '01/01/2025'], ['d' => FluentRule::date()->format('m/d/Y')->before($cutoff)]);
    expect($validator->passes())->toBeTrue();
});
