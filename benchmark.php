<?php

declare(strict_types=1);

/**
 * Benchmark script for CI — compares RuleSet performance against native Laravel.
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
use SanderMuller\FluentValidation\Rule;
use SanderMuller\FluentValidation\RuleSet;
use SanderMuller\FluentValidation\WildcardExpander;

$isSnapshot = in_array('--snapshot', $argv);
$isCi = in_array('--ci', $argv);
$isJson = in_array('--json', $argv);

$translator = new Translator(new ArrayLoader(), 'en');
$factory = new ValidatorFactory($translator);

// ── Scenario: 500 items, 4 fields (string/numeric/in) ────────────────────
$items = array_map(fn (int $i): array => [
    'name' => "Item {$i}",
    'email' => "user{$i}@example.com",
    'age' => $i % 80 + 18,
    'role' => ['admin', 'editor', 'viewer'][$i % 3],
], range(1, 500));
$data = ['items' => $items];

$nativeRules = [
    'items' => 'required|array',
    'items.*.name' => 'required|string|min:2|max:255',
    'items.*.email' => 'required|string|max:255',
    'items.*.age' => 'required|numeric|integer|min:0|max:150',
    'items.*.role' => ['required', 'string', Illuminate\Validation\Rule::in(['admin', 'editor', 'viewer'])],
];

$makeRuleSet = fn (): RuleSet => RuleSet::from([
    'items' => Rule::array()->required()->each([
        'name' => Rule::string()->required()->min(2)->max(255),
        'email' => Rule::string()->required()->max(255),
        'age' => Rule::numeric()->required()->integer()->min(0)->max(150),
        'role' => Rule::string()->required()->in(['admin', 'editor', 'viewer']),
    ]),
]);

// Warmup
$factory->make($data, $nativeRules)->validate();
$rs = $makeRuleSet();
[$expanded, $ia] = $rs->expand($data);
$compiled = RuleSet::compile($expanded);
$factory->make($data, $compiled)->validate();

// Per-item warmup (mimics RuleSet::validate fast path)
$perItemRules = RuleSet::compile([
    'name' => Rule::string()->required()->min(2)->max(255),
    'email' => Rule::string()->required()->max(255),
    'age' => Rule::numeric()->required()->integer()->min(0)->max(150),
    'role' => Rule::string()->required()->in(['admin', 'editor', 'viewer']),
]);
$fastChecks = (new ReflectionMethod(RuleSet::class, 'buildFastChecks'))->invoke(null, $perItemRules);
$pv = $factory->make($items[0], $perItemRules);
$pv->passes();

// Benchmark
$rounds = 7;
$nativeTimes = [];
$rulesetTimes = [];
$expandOnlyTimes = [];

$patterns = array_keys(array_filter($nativeRules, fn ($k) => str_contains($k, '*'), ARRAY_FILTER_USE_KEY));

for ($r = 0; $r < $rounds; $r++) {
    // Native Laravel (wildcard expansion by Laravel)
    $t = hrtime(true);
    $factory->make($data, $nativeRules)->validate();
    $nativeTimes[] = (hrtime(true) - $t) / 1e6;

    // RuleSet pipeline: flatten → expand → compile → fast-check → per-item
    $t = hrtime(true);
    $rs = $makeRuleSet();
    $flat = (new ReflectionMethod($rs, 'flatten'))->invoke($rs);
    $topRules = [];
    $groupRules = [];
    foreach ($flat as $field => $rule) {
        if (! str_contains($field, '*')) {
            $topRules[$field] = $rule;

            continue;
        }
        $pos = strpos($field, '.*');
        $parent = substr($field, 0, $pos);
        $child = substr($field, $pos + 2);
        $child = $child === '' ? '*' : ltrim($child, '.');
        $groupRules[$parent][$child] = $rule;
    }
    $factory->make($data, RuleSet::compile($topRules))->validate();
    foreach ($groupRules as $parent => $itemRulesRaw) {
        $itemRulesCompiled = RuleSet::compile($itemRulesRaw);
        $checks = (new ReflectionMethod(RuleSet::class, 'buildFastChecks'))->invoke(null, $itemRulesCompiled);
        $parentItems = data_get($data, $parent, []);
        $iv = null;
        foreach ($parentItems as $item) {
            if ($checks !== null) {
                $pass = true;
                foreach ($checks as $check) {
                    if (! $check($item)) {
                        $pass = false;
                        break;
                    }
                }
                if ($pass) {
                    continue;
                }
            }
            if ($iv === null) {
                $iv = $factory->make($item, $itemRulesCompiled);
            } else {
                $iv->setData($item);
            }
            $iv->passes();
        }
    }
    $rulesetTimes[] = (hrtime(true) - $t) / 1e6;

    // Expansion only
    $t = hrtime(true);
    foreach ($patterns as $pattern) {
        WildcardExpander::expand($pattern, $data);
    }
    $expandOnlyTimes[] = (hrtime(true) - $t) / 1e6;
}

$median = function (array $v): float {
    sort($v);
    $c = count($v);
    $m = intdiv($c, 2);

    return $c % 2 === 0 ? ($v[$m - 1] + $v[$m]) / 2 : $v[$m];
};

$results = [
    'native_ms' => round($median($nativeTimes), 2),
    'ruleset_ms' => round($median($rulesetTimes), 2),
    'expand_ms' => round($median($expandOnlyTimes), 2),
    'ratio' => round($median($rulesetTimes) / $median($nativeTimes), 3),
    'items' => 500,
    'patterns' => count($patterns),
];

$snapshotFile = __DIR__ . '/benchmark-snapshot.json';
$snapshot = file_exists($snapshotFile) ? json_decode(file_get_contents($snapshotFile), true) : null;

if ($isSnapshot) {
    file_put_contents($snapshotFile, json_encode($results, JSON_PRETTY_PRINT) . "\n");
    echo "Snapshot saved to benchmark-snapshot.json\n";
    exit(0);
}

$formatDelta = function (float $current, float $baseline): string {
    $delta = (($current - $baseline) / $baseline) * 100;
    if (abs($delta) < 2.0) {
        return '(~)';
    }

    return $delta > 0 ? sprintf('(+%.1f%%)', $delta) : sprintf('(%.1f%%)', $delta);
};

if ($isJson) {
    echo json_encode($results, JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

if ($isCi) {
    echo "## Benchmark: 500 items × 4 fields\n\n";
    echo '| Metric | Value |' . ($snapshot ? " Delta |\n" : "\n");
    echo '|--------|------:|' . ($snapshot ? "------:|\n" : "\n");

    $rows = [
        ['Native Laravel', sprintf('%.2fms', $results['native_ms'])],
        ['RuleSet::validate()', sprintf('%.2fms', $results['ruleset_ms'])],
        ['WildcardExpander', sprintf('%.2fms', $results['expand_ms'])],
        ['Ratio (lower=better)', sprintf('%.3fx', $results['ratio'])],
    ];

    foreach ($rows as $row) {
        echo "| {$row[0]} | {$row[1]} |";
        if ($snapshot) {
            $key = match ($row[0]) {
                'Native Laravel' => 'native_ms',
                'RuleSet::validate()' => 'ruleset_ms',
                'WildcardExpander' => 'expand_ms',
                'Ratio (lower=better)' => 'ratio',
            };
            echo ' ' . $formatDelta($results[$key], $snapshot[$key]) . ' |';
        }
        echo "\n";
    }
    exit(0);
}

// Console output
echo "\n=== Benchmark: {$results['items']} items × 4 fields, {$results['patterns']} wildcard patterns ===\n\n";
echo sprintf("Native Laravel:        %7.2fms%s\n", $results['native_ms'], $snapshot ? ' ' . $formatDelta($results['native_ms'], $snapshot['native_ms']) : '');
echo sprintf("RuleSet::validate():   %7.2fms%s\n", $results['ruleset_ms'], $snapshot ? ' ' . $formatDelta($results['ruleset_ms'], $snapshot['ruleset_ms']) : '');
echo sprintf("WildcardExpander only: %7.2fms%s\n", $results['expand_ms'], $snapshot ? ' ' . $formatDelta($results['expand_ms'], $snapshot['expand_ms']) : '');
echo sprintf("Ratio:                 %7.3fx%s\n", $results['ratio'], $snapshot ? ' ' . $formatDelta($results['ratio'], $snapshot['ratio']) : '');
