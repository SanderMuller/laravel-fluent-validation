<?php declare(strict_types=1);

/**
 * Standalone benchmark — compares HasFluentRules performance against native Laravel.
 * Runs without a full Laravel app by using the ValidatorFactory directly.
 *
 * For the full benchmark suite (including RuleSet::validate()), run:
 *   vendor/bin/pest --group=benchmark
 *
 * Usage:
 *   php benchmark.php                  Run and display results
 *   php benchmark.php --snapshot       Save results as baseline
 *   php benchmark.php --ci             Output markdown table (for PR comments)
 *   php benchmark.php --json           Output JSON
 */

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidatorFactory;
use Illuminate\Validation\Rule;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\OptimizedValidator;
use SanderMuller\FluentValidation\RuleSet;

$isSnapshot = in_array('--snapshot', $argv);
$isCi = in_array('--ci', $argv);
$isJson = in_array('--json', $argv);

$translator = new Translator(new ArrayLoader(), 'en');
$factory = new ValidatorFactory($translator);

// ── Scenario: 500 items, 7 fields ──
$items = array_map(fn (int $i): array => [
    'name' => "Item {$i}",
    'email' => "user{$i}@example.com",
    'age' => $i % 80 + 18,
    'role' => ['admin', 'editor', 'viewer'][$i % 3],
    'starts_at' => '2025-06-' . str_pad((string) ($i % 28 + 1), 2, '0', STR_PAD_LEFT),
    'active' => $i % 2 === 0,
    'notes' => $i % 3 === 0 ? "Note for item {$i}" : null,
], range(1, 500));
$data = ['items' => $items];

$nativeRules = [
    'items' => 'required|array',
    'items.*.name' => 'required|string|min:2|max:255',
    'items.*.email' => 'required|string|max:255',
    'items.*.age' => 'required|numeric|integer|min:0|max:150',
    'items.*.role' => ['required', 'string', Rule::in(['admin', 'editor', 'viewer'])],
    'items.*.starts_at' => 'required|date',
    'items.*.active' => 'required|boolean',
    'items.*.notes' => 'nullable|string|max:1000',
];

$makeRuleSet = fn (): RuleSet => RuleSet::from([
    'items' => FluentRule::array(label: 'Import Items')->required()->each([
        'name' => FluentRule::string('Item Name')->required()->min(2)->max(255),
        'email' => FluentRule::string('Email')->required()->max(255),
        'age' => FluentRule::numeric('Age')->required()->integer()->min(0)->max(150),
        'role' => FluentRule::string('Role')->required()->in(['admin', 'editor', 'viewer']),
        'starts_at' => FluentRule::date('Start Date')->required(),
        'active' => FluentRule::boolean('Active')->required(),
        'notes' => FluentRule::string('Notes')->nullable()->max(1000),
    ]),
]);

/**
 * Simulate what HasFluentRules does: prepare() + OptimizedValidator with fast-checks.
 */
$runTraitPath = function () use ($translator, $makeRuleSet, $data): void {
    $prepared = $makeRuleSet()->prepare($data);

    $fastChecks = OptimizedValidator::buildFastChecks($prepared->rules);
    $attributePatternMap = [];
    foreach ($prepared->implicitAttributes as $pattern => $paths) {
        if (isset($fastChecks[$pattern])) {
            foreach ($paths as $path) {
                $attributePatternMap[$path] = $pattern;
            }
        }
    }

    $validator = new OptimizedValidator($translator, $data, $prepared->rules, $prepared->messages, $prepared->attributes);
    $validator->withFastChecks($fastChecks, $attributePatternMap);

    if ($prepared->implicitAttributes !== []) {
        (new ReflectionProperty($validator, 'implicitAttributes'))->setValue($validator, $prepared->implicitAttributes);
    }

    $validator->validate();
};

// Warmup
$factory->make($data, $nativeRules)->validate();
$runTraitPath();

// Benchmark
$rounds = 7;
$nativeTimes = [];
$traitTimes = [];

for ($r = 0; $r < $rounds; $r++) {
    $t = hrtime(true);
    $factory->make($data, $nativeRules)->validate();
    $nativeTimes[] = (hrtime(true) - $t) / 1e6;

    $t = hrtime(true);
    $runTraitPath();
    $traitTimes[] = (hrtime(true) - $t) / 1e6;
}

$median = function (array $v): float {
    sort($v);
    $c = count($v);
    $m = intdiv($c, 2);

    return $c % 2 === 0 ? ($v[$m - 1] + $v[$m]) / 2 : $v[$m];
};

$nativeMs = round($median($nativeTimes), 1);
$traitMs = round($median($traitTimes), 1);
$speedup = $nativeMs / $traitMs;

$results = [
    'native_ms' => $nativeMs,
    'trait_ms' => $traitMs,
    'speedup' => round($speedup, 1),
    'items' => 500,
    'fields' => 7,
];

$snapshotFile = __DIR__ . '/benchmark-snapshot.json';
$snapshot = file_exists($snapshotFile) ? json_decode(file_get_contents($snapshotFile), true) : null;

if ($isSnapshot) {
    file_put_contents($snapshotFile, json_encode($results, JSON_PRETTY_PRINT) . "\n");
    echo "Snapshot saved to benchmark-snapshot.json\n";
    exit(0);
}

if ($isJson) {
    echo json_encode($results, JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

$formatSpeedup = fn (float $x): string => $x >= 1.5 ? sprintf('**%.0fx**', $x) : sprintf('%.1fx', $x);

if ($isCi) {
    echo "## Benchmark: {$results['items']} items × {$results['fields']} fields\n\n";
    echo "| Approach | Time | Speedup |\n";
    echo "|----------|-----:|--------:|\n";
    echo sprintf("| Native Laravel | %.1fms | 1x |\n", $nativeMs);
    echo sprintf("| HasFluentRules | %.1fms | %s |\n", $traitMs, $formatSpeedup($speedup));
    exit(0);
}

// Console output
echo sprintf("\n=== Benchmark: %d items × %d fields ===\n\n", $results['items'], $results['fields']);
echo sprintf("  Native Laravel:  %7.1fms\n", $nativeMs);
echo sprintf("  HasFluentRules:  %7.1fms  (%s)\n", $traitMs, $formatSpeedup($speedup));
