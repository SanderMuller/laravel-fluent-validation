<?php

declare(strict_types=1);

use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\RuleSet;

it('benchmarks all code paths', function (): void {
    $items500 = array_map(fn (int $i): array => [
        'name' => "Item {$i}",
        'email' => "user{$i}@example.com",
        'age' => $i % 80 + 18,
        'role' => ['admin', 'editor', 'viewer'][$i % 3],
        'starts_at' => '2025-01-' . str_pad((string) ($i % 28 + 1), 2, '0', STR_PAD_LEFT),
        'active' => $i % 2 === 0,
    ], range(1, 500));

    $nestedData = ['orders' => array_map(fn (int $i): array => [
        'items' => array_map(fn (int $j) => ['qty' => $j + 1], range(0, 3)),
    ], range(0, 99))];

    // Native Laravel baseline for the string+numeric scenario (used for speedup comparison)
    $nativeRules = [
        'items' => 'required|array',
        'items.*.name' => 'required|string|min:2|max:255',
        'items.*.email' => 'required|string|max:255',
        'items.*.age' => 'required|numeric|integer|min:0|max:150',
        'items.*.role' => ['required', 'string', \Illuminate\Validation\Rule::in(['admin', 'editor', 'viewer'])],
    ];
    $nativeData = ['items' => $items500];

    // Warmup native
    \Illuminate\Support\Facades\Validator::make($nativeData, $nativeRules)->validate();

    // Benchmark native
    $nativeTimes = [];
    for ($i = 0; $i < 3; ++$i) {
        $t = hrtime(true);
        \Illuminate\Support\Facades\Validator::make($nativeData, $nativeRules)->validate();
        $nativeTimes[] = (hrtime(true) - $t) / 1e6;
    }
    sort($nativeTimes);
    $nativeMedian = $nativeTimes[1];

    $scenarios = [
        'string+numeric (fast-check)' => fn () => RuleSet::from([
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->min(2)->max(255),
                'email' => FluentRule::string()->required()->max(255),
                'age' => FluentRule::numeric()->required()->integer()->min(0)->max(150),
            ]),
        ])->validate(['items' => $items500]),

        'string+numeric+in (no fast-check)' => fn () => RuleSet::from([
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->min(2)->max(255),
                'email' => FluentRule::string()->required()->max(255),
                'age' => FluentRule::numeric()->required()->integer()->min(0)->max(150),
                'role' => FluentRule::string()->required()->in(['admin', 'editor', 'viewer']),
            ]),
        ])->validate(['items' => $items500]),

        'with date (no fast-check)' => fn () => RuleSet::from([
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->min(2)->max(255),
                'starts_at' => FluentRule::date()->required()->after('2024-01-01'),
            ]),
        ])->validate(['items' => $items500]),

        'with boolean (no fast-check)' => fn () => RuleSet::from([
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->min(2)->max(255),
                'active' => FluentRule::boolean()->required(),
            ]),
        ])->validate(['items' => $items500]),

        'nested wildcards (fallback)' => fn () => RuleSet::from([
            'orders' => FluentRule::array()->required()->each([
                'items' => FluentRule::array()->required()->each([
                    'qty' => FluentRule::numeric()->required()->integer()->min(1),
                ]),
            ]),
        ])->validate($nestedData),

        'with unique (non-Stringable obj)' => fn () => RuleSet::from([
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->min(2)->max(255),
                'age' => FluentRule::numeric()->required()->integer()->min(0)->max(150),
            ]),
        ])->validate(['items' => $items500]),
    ];

    // Warmup
    foreach ($scenarios as $fn) {
        $fn();
    }

    fprintf(STDERR, "  Native Laravel (500 items, 4 fields):  %7.2fms\n", $nativeMedian);

    foreach ($scenarios as $label => $fn) {
        $times = [];
        for ($i = 0; $i < 5; ++$i) {
            $t = hrtime(true);
            $fn();
            $times[] = (hrtime(true) - $t) / 1e6;
        }

        sort($times);
        $median = $times[2];
        $speedup = $nativeMedian / $median;
        fprintf(STDERR, "  %-40s %7.2fms (%.0fx)\n", $label, $median, $speedup);
    }

    expect(true)->toBeTrue();
})->group('benchmark');
