<?php declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

it('benchmarks batched exists vs native exists', function (): void {
    // Setup in-memory SQLite with 200 known emails
    config(['database.default' => 'bench_db']);
    config(['database.connections.bench_db' => ['driver' => 'sqlite', 'database' => ':memory:']]);

    Schema::connection('bench_db')->create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('email')->unique();
    });

    $knownEmails = array_map(static fn (int $i): string => "user{$i}@example.com", range(1, 200));
    foreach (array_chunk($knownEmails, 50) as $chunk) {
        DB::connection('bench_db')->table('users')->insert(
            array_map(static fn (string $email): array => ['email' => $email], $chunk),
        );
    }

    $items = array_map(static fn (string $email): array => ['email' => $email], $knownEmails);

    // Native Laravel: N individual queries
    $nativeRules = [
        'items' => 'required|array',
        'items.*.email' => ['required', 'string', Rule::exists('bench_db.users', 'email')],
    ];
    $nativeData = ['items' => $items];

    // Warmup
    Validator::make($nativeData, $nativeRules)->validate();

    $nativeMedian = benchmarkMedian(
        fn () => Validator::make($nativeData, $nativeRules)->validate(),
        3,
    );

    // Batched: 1 whereIn query via RuleSet
    $batchFn = fn () => RuleSet::from([
        'items' => FluentRule::array()->required()->each([
            'email' => FluentRule::string()->required()->exists('bench_db.users', 'email'),
        ]),
    ])->validate($nativeData);

    $batchFn(); // warmup
    $batchMedian = benchmarkMedian($batchFn, 3);

    $speedup = $nativeMedian / $batchMedian;

    fprintf(STDERR, "\n  Benchmark: 200 items × exists rule (DB validation)\n");
    fprintf(STDERR, "  %-30s %8s %8s\n", 'Approach', 'Time', 'Speedup');
    fprintf(STDERR, "  %s\n", str_repeat('─', 50));
    fprintf(STDERR, "  %-30s %7.1fms %8s\n", 'Native (N queries)', $nativeMedian, '1x');
    fprintf(STDERR, "  %-30s %7.1fms %7.0fx\n", 'Batched (1 whereIn)', $batchMedian, $speedup);
    fprintf(STDERR, "\n");

    expect($batchMedian)->toBeLessThan($nativeMedian);
})->group('benchmark');

it('benchmarks presence conditionals with nested dependent fields vs native', function (): void {
    // 500 contacts, each with a nested `profile.birthdate` dependent field.
    // FastCheckCompiler::compileWithPresenceConditionals rejects dotted field
    // names at its identifier regex — so without RuleSet::reduceRulesForItem
    // pre-evaluation, the postcode rule would fall through to Laravel. With
    // pre-eval the rule is rewritten to plain `required`/dropped per item
    // and the remainder fast-checks as usual.
    $contacts = array_map(static fn (int $i): array => $i % 2 === 0 ? [
        'first_name' => "Contact {$i}",
        'last_name' => 'Test',
        'postcode' => "12{$i}AB",
        'profile' => [],
        'phone' => '+31612345' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
    ] : [
        'first_name' => "Contact {$i}",
        'last_name' => 'Test',
        'profile' => ['birthdate' => '1990-01-01'],
        'phone' => '+31612345' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
    ], range(1, 500));

    $nativeRules = [
        'contacts' => 'required|array',
        'contacts.*.first_name' => 'required|string|max:255',
        'contacts.*.last_name' => 'required|string|max:255',
        'contacts.*.postcode' => 'required_without:contacts.*.profile.birthdate|nullable|string|max:10',
        'contacts.*.profile.birthdate' => 'nullable|date',
        'contacts.*.phone' => 'nullable|string|max:20',
    ];
    $data = ['contacts' => $contacts];

    // Warmup
    Validator::make($data, $nativeRules)->validate();

    $nativeMedian = benchmarkMedian(
        fn () => Validator::make($data, $nativeRules)->validate(),
        3,
    );

    $ruleSetFn = fn () => RuleSet::from([
        'contacts' => FluentRule::array()->required()->each([
            'first_name' => FluentRule::string()->required()->max(255),
            'last_name' => FluentRule::string()->required()->max(255),
            'postcode' => FluentRule::field()->requiredWithout('profile.birthdate')->nullable()->rule('string')->rule('max:10'),
            'profile.birthdate' => FluentRule::date()->nullable(),
            'phone' => FluentRule::string()->nullable()->max(20),
        ]),
    ])->validate($data);

    $ruleSetFn(); // warmup
    $ruleSetMedian = benchmarkMedian($ruleSetFn, 5);

    $speedup = $nativeMedian / $ruleSetMedian;

    fprintf(STDERR, "\n  Benchmark: 500 contacts × required_without:profile.birthdate (nested dependent)\n");
    fprintf(STDERR, "  %-40s %8s %8s\n", 'Approach', 'Time', 'Speedup');
    fprintf(STDERR, "  %s\n", str_repeat('─', 60));
    fprintf(STDERR, "  %-40s %7.1fms %8s\n", 'Native Laravel', $nativeMedian, '1x');
    fprintf(STDERR, "  %-40s %7.1fms %7.1fx\n", 'RuleSet (pre-eval + fast-check)', $ruleSetMedian, $speedup);
    fprintf(STDERR, "\n");

    expect($ruleSetMedian)->toBeLessThan($nativeMedian);
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
