<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
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
        'website' => 'https://example.com/' . $i,
        'slug' => 'item-' . $i,
        'zip' => (string) (10000 + $i % 89999),
    ], range(1, 500));

    $nestedData = ['orders' => array_map(fn (int $i): array => [
        'items' => array_map(fn (int $j) => ['qty' => $j + 1], range(0, 3)),
    ], range(0, 99))];

    // Native Laravel baseline (500 items × 7 fields)
    $nativeRules = [
        'items' => 'required|array',
        'items.*.name' => 'required|string|min:2|max:255',
        'items.*.email' => 'required|string|email|max:255',
        'items.*.age' => 'required|numeric|integer|min:0|max:150',
        'items.*.role' => ['required', 'string', Rule::in(['admin', 'editor', 'viewer'])],
        'items.*.website' => 'nullable|string|url',
        'items.*.slug' => ['required', 'string', 'regex:/\A[a-zA-Z0-9_-]+\z/'],
        'items.*.zip' => 'required|numeric|digits:5',
    ];
    $nativeData = ['items' => $items500];

    Validator::make($nativeData, $nativeRules)->validate();

    $nativeMedian = benchmarkMedian(fn () => Validator::make($nativeData, $nativeRules)->validate(), 3);

    $scenarios = [
        ['7 fields (user import)', 'fast-check', fn () => RuleSet::from([
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->min(2)->max(255),
                'email' => FluentRule::email()->required()->max(255),
                'age' => FluentRule::numeric()->required()->integer()->min(0)->max(150),
                'role' => FluentRule::string()->required()->in(['admin', 'editor', 'viewer']),
                'website' => FluentRule::string()->nullable()->url(),
                'slug' => FluentRule::string()->required()->regex('/\A[a-zA-Z0-9_-]+\z/'),
                'zip' => FluentRule::numeric()->required()->digits(5),
            ]),
        ])->validate(['items' => $items500])],

        ['3 fields (simple)', 'fast-check', fn () => RuleSet::from([
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->min(2)->max(255),
                'email' => FluentRule::string()->required()->max(255),
                'age' => FluentRule::numeric()->required()->integer()->min(0)->max(150),
            ]),
        ])->validate(['items' => $items500])],

        ['string+date', 'per-item', fn () => RuleSet::from([
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->min(2)->max(255),
                'starts_at' => FluentRule::date()->required()->after('2024-01-01'),
            ]),
        ])->validate(['items' => $items500])],

        ['string+boolean', 'per-item', fn () => RuleSet::from([
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->min(2)->max(255),
                'active' => FluentRule::boolean()->required(),
            ]),
        ])->validate(['items' => $items500])],

        ['nested wildcards', 'fallback', fn () => RuleSet::from([
            'orders' => FluentRule::array()->required()->each([
                'items' => FluentRule::array()->required()->each([
                    'qty' => FluentRule::numeric()->required()->integer()->min(1),
                ]),
            ]),
        ])->validate($nestedData)],
    ];

    // Warmup
    foreach ($scenarios as [,, $fn]) {
        $fn();
    }

    fprintf(STDERR, "\n  Benchmark: 500 items (native baseline: 7 fields)\n");
    fprintf(STDERR, "  %-30s %8s %8s %8s\n", 'Scenario', 'Path', 'Time', 'Speedup');
    fprintf(STDERR, "  %s\n", str_repeat('─', 62));
    fprintf(STDERR, "  %-30s %8s %7.1fms %8s\n", 'Native Laravel', '', $nativeMedian, '1x');

    foreach ($scenarios as [$label, $path, $fn]) {
        $median = benchmarkMedian($fn, 5);
        $speedup = $nativeMedian / $median;
        fprintf(STDERR, "  %-30s %8s %7.1fms %7.0fx\n", $label, $path, $median, $speedup);
    }

    fprintf(STDERR, "\n");

    expect(true)->toBeTrue();
})->group('benchmark');

function benchmarkMedian(Closure $fn, int $iterations): float
{
    $times = [];

    for ($i = 0; $i < $iterations; ++$i) {
        $t = hrtime(true);
        $fn();
        $times[] = (hrtime(true) - $t) / 1e6;
    }

    sort($times);

    return $times[(int) floor(count($times) / 2)];
}
