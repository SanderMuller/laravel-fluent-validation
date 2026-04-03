<?php

declare(strict_types=1);

use SanderMuller\FluentValidation\Rule;
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

    $nestedData = ['orders' => array_map(fn ($i) => [
        'items' => array_map(fn (int $j) => ['qty' => $j + 1], range(0, 3)),
    ], range(0, 99))];

    $scenarios = [
        'string+numeric (fast-check)' => fn () => RuleSet::from([
            'items' => Rule::array()->required()->each([
                'name' => Rule::string()->required()->min(2)->max(255),
                'email' => Rule::string()->required()->max(255),
                'age' => Rule::numeric()->required()->integer()->min(0)->max(150),
                'role' => Rule::string()->required()->in(['admin', 'editor', 'viewer']),
            ]),
        ])->validate(['items' => $items500]),

        'with date (no fast-check)' => fn () => RuleSet::from([
            'items' => Rule::array()->required()->each([
                'name' => Rule::string()->required()->min(2)->max(255),
                'starts_at' => Rule::date()->required()->after('2024-01-01'),
            ]),
        ])->validate(['items' => $items500]),

        'with boolean (no fast-check)' => fn () => RuleSet::from([
            'items' => Rule::array()->required()->each([
                'name' => Rule::string()->required()->min(2)->max(255),
                'active' => Rule::boolean()->required(),
            ]),
        ])->validate(['items' => $items500]),

        'nested wildcards (fallback)' => fn () => RuleSet::from([
            'orders' => Rule::array()->required()->each([
                'items' => Rule::array()->required()->each([
                    'qty' => Rule::numeric()->required()->integer()->min(1),
                ]),
            ]),
        ])->validate($nestedData),

        'with unique (non-Stringable obj)' => fn () => RuleSet::from([
            'items' => Rule::array()->required()->each([
                'name' => Rule::string()->required()->min(2)->max(255),
                'age' => Rule::numeric()->required()->integer()->min(0)->max(150),
            ]),
        ])->validate(['items' => $items500]),
    ];

    // Warmup
    foreach ($scenarios as $fn) {
        $fn();
    }

    foreach ($scenarios as $label => $fn) {
        $times = [];
        for ($i = 0; $i < 5; ++$i) {
            $t = hrtime(true);
            $fn();
            $times[] = (hrtime(true) - $t) / 1e6;
        }

        sort($times);
        fprintf(STDERR, "  %-40s %7.2fms\n", $label, $times[2]);
    }

    expect(true)->toBeTrue();
})->group('benchmark');
