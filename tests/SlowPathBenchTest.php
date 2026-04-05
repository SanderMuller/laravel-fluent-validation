<?php declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\RuleSet;

it('benchmarks all code paths', function (): void {
    $users500 = array_map(fn (int $i): array => [
        'name' => "User {$i}",
        'email' => "user{$i}@example.com",
        'username' => 'user-' . $i,
        'phone' => '+1' . str_pad((string) (2000000000 + $i), 10, '0', STR_PAD_LEFT),
        'country' => ['US', 'NL', 'DE', 'GB', 'FR'][$i % 5],
        'website' => 'https://example.com/' . $i,
        'agree_tos' => true,
    ], range(1, 500));

    $nestedData = ['orders' => array_map(fn (int $i): array => [
        'items' => array_map(fn (int $j) => ['qty' => $j + 1], range(0, 3)),
    ], range(0, 99))];

    // Native Laravel baseline (500 users × 7 fields)
    $nativeRules = [
        'users' => 'required|array',
        'users.*.name' => 'required|string|min:2|max:255',
        'users.*.email' => 'required|string|email|max:255',
        'users.*.username' => ['required', 'string', 'regex:/\A[a-zA-Z0-9_-]+\z/', 'max:40'],
        'users.*.phone' => 'nullable|string|max:20',
        'users.*.country' => ['required', 'string', Rule::in(['US', 'NL', 'DE', 'GB', 'FR'])],
        'users.*.website' => 'nullable|string|url',
        'users.*.agree_tos' => 'required|accepted',
    ];
    $nativeData = ['users' => $users500];

    Validator::make($nativeData, $nativeRules)->validate();

    $nativeMedian = benchmarkMedian(fn () => Validator::make($nativeData, $nativeRules)->validate(), 3);

    $scenarios = [
        ['7 fields (registration)', 'fast-check', fn () => RuleSet::from([
            'users' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->min(2)->max(255),
                'email' => FluentRule::email()->required()->max(255),
                'username' => FluentRule::string()->required()->regex('/\A[a-zA-Z0-9_-]+\z/')->max(40),
                'phone' => FluentRule::string()->nullable()->max(20),
                'country' => FluentRule::string()->required()->in(['US', 'NL', 'DE', 'GB', 'FR']),
                'website' => FluentRule::string()->nullable()->url(),
                'agree_tos' => FluentRule::boolean()->accepted(),
            ]),
        ])->validate(['users' => $users500])],

        ['3 fields (simple)', 'fast-check', fn () => RuleSet::from([
            'users' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->min(2)->max(255),
                'email' => FluentRule::string()->required()->max(255),
                'country' => FluentRule::string()->required()->in(['US', 'NL', 'DE', 'GB', 'FR']),
            ]),
        ])->validate(['users' => $users500])],

        ['string+date', 'per-item', fn () => RuleSet::from([
            'users' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->min(2)->max(255),
                'email' => FluentRule::date()->required()->after('2024-01-01'),
            ]),
        ])->validate(['users' => array_map(fn (array $u): array => [...$u, 'email' => '2025-06-15'], $users500)])],

        ['string+boolean', 'per-item', fn () => RuleSet::from([
            'users' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->min(2)->max(255),
                'agree_tos' => FluentRule::boolean()->required(),
            ]),
        ])->validate(['users' => $users500])],

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

    fprintf(STDERR, "\n  Benchmark: 500 users (native baseline: 7 fields)\n");
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
