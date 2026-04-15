<?php declare(strict_types=1);

/**
 * Benchmark suite for laravel-fluent-validation.
 *
 * Tests all validation paths across different rule types and data shapes.
 *
 * Usage:
 *   php benchmark.php                  Run and display results
 *   php benchmark.php --snapshot       Save results as baseline
 *   php benchmark.php --ci             Output markdown table (for PR comments)
 *   php benchmark.php --json           Output JSON
 */

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Orchestra\Testbench\Foundation\Application;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\RuleSet;

$isSnapshot = in_array('--snapshot', $argv);
$isCi = in_array('--ci', $argv);
$isJson = in_array('--json', $argv);

$app = (new Application())->createApplication();

// =========================================================================
// Data generators
// =========================================================================

function makeProducts(int $count): array
{
    return array_map(fn (int $i): array => [
        'sku' => "SKU-{$i}",
        'name' => "Product {$i}",
        'price' => round(9.99 + ($i % 100) * 0.5, 2),
        'quantity' => $i % 1000,
        'category' => ['electronics', 'clothing', 'food', 'books', 'toys'][$i % 5],
        'active' => $i % 2 === 0,
        'tags' => 'tag-' . ($i % 20),
    ], range(1, $count));
}

function makeOrders(int $orders, int $lineItemsPerOrder): array
{
    return array_map(fn (int $i): array => [
        'order_number' => "ORD-{$i}",
        'status' => ['pending', 'processing', 'shipped'][$i % 3],
        'line_items' => array_map(fn (int $j): array => [
            'product_id' => ($i * 10 + $j) % 9999 + 1,
            'quantity' => $j + 1,
            'price' => round(10.00 + $j * 2.5, 2),
        ], range(1, $lineItemsPerOrder)),
    ], range(1, $orders));
}

function makeEvents(int $count): array
{
    return array_map(fn (int $i): array => [
        'name' => "Event {$i}",
        'start_date' => '2025-06-' . str_pad((string) ($i % 28 + 1), 2, '0', STR_PAD_LEFT),
        'end_date' => '2025-07-' . str_pad((string) ($i % 28 + 1), 2, '0', STR_PAD_LEFT),
        'registration_deadline' => '2025-05-' . str_pad((string) ($i % 28 + 1), 2, '0', STR_PAD_LEFT),
    ], range(1, $count));
}

function makeArticles(int $count): array
{
    return array_map(fn (int $i): array => [
        'title' => "Article {$i}",
        'slug' => "article-{$i}",
        'content' => str_repeat('Word ', 120),
        'category' => ['tech', 'science', 'art'][$i % 3],
        'priority' => $i % 10,
    ], range(1, $count));
}

// =========================================================================
// Benchmark runner
// =========================================================================

function benchMedian(Closure $fn, int $iterations = 7): float
{
    // Warmup
    $fn();

    $times = [];

    for ($i = 0; $i < $iterations; $i++) {
        $t = hrtime(true);
        $fn();
        $times[] = (hrtime(true) - $t) / 1e6;
    }

    sort($times);

    $c = count($times);
    $m = intdiv($c, 2);

    return $c % 2 === 0 ? ($times[$m - 1] + $times[$m]) / 2 : $times[$m];
}

function benchRuleSet(array $rules, array $data): float
{
    return benchMedian(function () use ($rules, $data): void {
        try {
            RuleSet::from($rules)->validate($data);
        } catch (ValidationException) {
        }
    });
}

function benchNativeLaravel(array $rules, array $data): float
{
    return benchMedian(function () use ($rules, $data): void {
        try {
            Validator::make($data, $rules)->validate();
        } catch (ValidationException) {
        }
    });
}

// =========================================================================
// Scenarios
// =========================================================================

$products500 = makeProducts(500);
$orders1000 = makeOrders(1000, 5);
$events100 = makeEvents(100);
$articles50 = makeArticles(50);

$minWordCount = new class implements ValidationRule {
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || str_word_count($value) < 100) {
            $fail('The :attribute must have at least 100 words.');
        }
    }
};

$validCategory = new class implements ValidationRule {
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! in_array($value, ['tech', 'science', 'art', 'music'], true)) {
            $fail('The :attribute must be a valid category.');
        }
    }
};

$validPriority = new class implements ValidationRule {
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_int($value) || $value < 0 || $value > 10) {
            $fail('The :attribute must be between 0 and 10.');
        }
    }
};

$scenarios = [];

// --- 1. Product import — 500 items, all fast-checkable ---
$scenarios['Product import — 500 items, simple rules'] = [
    'optimizations' => 'Wildcard, fast-check',
    'native' => [
        ['products' => 'required|array', 'products.*.sku' => 'required|string|max:50|regex:/^SKU-/', 'products.*.name' => 'required|string|min:2|max:255', 'products.*.price' => 'required|numeric|min:0', 'products.*.quantity' => 'required|numeric|integer|min:0', 'products.*.category' => ['required', 'string', Rule::in(['electronics', 'clothing', 'food', 'books', 'toys'])], 'products.*.active' => 'required|boolean', 'products.*.tags' => 'nullable|string|max:50'],
        ['products' => $products500],
    ],
    'fluent' => [
        ['products' => FluentRule::array()->required()->each(['sku' => FluentRule::string()->required()->max(50)->regex('/^SKU-/'), 'name' => FluentRule::string()->required()->min(2)->max(255), 'price' => FluentRule::numeric()->required()->min(0), 'quantity' => FluentRule::numeric()->required()->integer()->min(0), 'category' => FluentRule::string()->required()->in(['electronics', 'clothing', 'food', 'books', 'toys']), 'active' => FluentRule::boolean()->required(), 'tags' => FluentRule::string()->nullable()->max(50)])],
        ['products' => $products500],
    ],
];

// --- 2. Nested order lines — 1000 orders × 5 line items ---
$scenarios['Nested order lines — 1000 orders × 5 line items'] = [
    'optimizations' => 'Wildcard, fast-check (nested)',
    'native' => [
        ['orders' => 'required|array', 'orders.*.order_number' => 'required|string|alpha_dash|min:5', 'orders.*.status' => ['required', 'string', Rule::in(['pending', 'processing', 'shipped'])], 'orders.*.line_items' => 'required|array|min:1', 'orders.*.line_items.*.product_id' => 'required|integer|min:1', 'orders.*.line_items.*.quantity' => 'required|integer|min:1', 'orders.*.line_items.*.price' => 'required|numeric|min:0.01'],
        ['orders' => $orders1000],
    ],
    'fluent' => [
        ['orders' => FluentRule::array()->required()->each(['order_number' => FluentRule::string()->required()->alphaDash()->min(5), 'status' => FluentRule::string()->required()->in(['pending', 'processing', 'shipped']), 'line_items' => FluentRule::array()->required()->min(1)->each(['product_id' => FluentRule::numeric()->required()->integer()->min(1), 'quantity' => FluentRule::numeric()->required()->integer()->min(1), 'price' => FluentRule::numeric()->required()->min(0.01)])])],
        ['orders' => $orders1000],
    ],
];

// --- 3. Event scheduling — field-reference date comparisons (partial fast-check) ---
$scenarios['Event scheduling — 100 items, field-ref dates'] = [
    'optimizations' => 'Wildcard, partial fast-check',
    'native' => [
        ['events' => 'required|array', 'events.*.name' => 'required|string|min:3|max:255', 'events.*.start_date' => 'required|date|after:2025-01-01', 'events.*.end_date' => 'required|date|after:events.*.start_date', 'events.*.registration_deadline' => 'required|date|before:events.*.start_date'],
        ['events' => $events100],
    ],
    'fluent' => [
        ['events' => FluentRule::array()->required()->each(['name' => FluentRule::string()->required()->min(3)->max(255), 'start_date' => FluentRule::date()->required()->after('2025-01-01'), 'end_date' => FluentRule::date()->required()->after('start_date'), 'registration_deadline' => FluentRule::date()->required()->before('start_date')])],
        ['events' => $events100],
    ],
];

// --- 4. Article submission — custom Rule objects (wildcard expansion only) ---
$scenarios['Article submission — 50 items, custom Rule objects'] = [
    'optimizations' => 'Wildcard only',
    'native' => [
        ['articles' => 'required|array', 'articles.*.title' => 'required|string|min:3|max:255', 'articles.*.slug' => 'required|string|alpha_dash|max:255', 'articles.*.content' => ['required', 'string', $minWordCount], 'articles.*.category' => ['required', $validCategory], 'articles.*.priority' => ['required', $validPriority]],
        ['articles' => $articles50],
    ],
    'fluent' => [
        ['articles' => FluentRule::array()->required()->each(['title' => FluentRule::string()->required()->min(3)->max(255), 'slug' => FluentRule::string()->required()->alphaDash()->max(255), 'content' => ['required', 'string', $minWordCount], 'category' => ['required', $validCategory], 'priority' => ['required', $validPriority]])],
        ['articles' => $articles50],
    ],
];

// --- 5. Conditional import — 100 items, ~47 wildcard patterns with exclude_unless ---
$interactionTypes = ['button', 'hotspot', 'scroll_area', 'image', 'chapter', 'menu', 'frame', 'text', 'iframe'];
$interactions100 = array_map(fn (int $i): array => [
    'type' => $interactionTypes[$i % count($interactionTypes)],
    'title' => "Interaction {$i}",
    'start_time' => $i * 10,
    'end_time' => $i * 10 + 5,
    'position' => 'bottom',
    'should_start_collapsed' => false,
    'should_collapse_after_menu_item_click' => true,
    'should_pause_when_shown' => false,
    'should_not_use_time' => false,
    'should_use_menu_layout' => false,
    'text' => '<p>Sample text</p>',
    'text_stroke' => null,
    'should_fade_volume' => false,
    'sound_url' => null,
    'should_enable_sound' => false,
    'should_fade_in' => true,
    'should_fade_out' => true,
    'image_url' => $interactionTypes[$i % count($interactionTypes)] === 'image' ? 'https://example.com/image.png' : null,
    'style' => [
        'top' => '10%', 'left' => '20%', 'height' => '30%', 'width' => '40%',
        'background_color' => '#ff0000', 'border_radius' => 5,
        'padding_top' => 10, 'padding_bottom' => 10,
        'border' => ['width' => 1, 'style' => 'solid', 'color' => '#000000'],
    ],
    'action' => ['type' => 'link', 'link' => 'https://example.com', 'time' => 0],
    'attributes' => [
        'show_indicator' => true, 'indicator_color' => '#00ff00', 'blinking_speed' => 'normal',
        'options' => ['menu_button_location' => 'top', 'menu_button_name' => 'Menu'],
    ],
    'chapters' => $i % count($interactionTypes) === 4 ? array_map(fn (int $j): array => [
        'title' => "Chapter {$j}", 'title_short' => null,
        'start_time' => $j * 5, 'end_time' => $j * 5 + 4, 'sort_order' => $j,
    ], range(1, 4)) : [],
], range(1, 100));

$conditionalRules = [
    'interactions' => 'required|array|min:1',
    'interactions.*.type' => ['required', 'string', Rule::in($interactionTypes)],
    'interactions.*.title' => ['nullable', 'string'],
    'interactions.*.start_time' => ['required', 'numeric', 'min:0'],
    'interactions.*.end_time' => ['required', 'numeric', 'gte:interactions.*.start_time'],
    'interactions.*.position' => ['bail', ['exclude_unless', 'interactions.*.type', 'chapter', 'menu'], 'string'],
    'interactions.*.should_start_collapsed' => [['exclude_unless', 'interactions.*.type', 'chapter'], 'boolean'],
    'interactions.*.should_collapse_after_menu_item_click' => [['exclude_unless', 'interactions.*.type', 'chapter'], 'boolean'],
    'interactions.*.should_pause_when_shown' => [['exclude_unless', 'interactions.*.type', 'chapter', 'menu'], 'nullable', 'boolean'],
    'interactions.*.should_not_use_time' => [['exclude_unless', 'interactions.*.type', 'menu'], 'nullable', 'boolean'],
    'interactions.*.should_use_menu_layout' => [['exclude_unless', 'interactions.*.type', 'chapter'], 'nullable', 'boolean'],
    'interactions.*.text' => [['exclude_unless', 'interactions.*.type', 'button', 'hotspot', 'text'], 'nullable', 'string'],
    'interactions.*.text_stroke' => [['exclude_unless', 'interactions.*.type', 'text'], 'nullable', 'string'],
    'interactions.*.should_fade_volume' => ['boolean'],
    'interactions.*.sound_url' => ['bail', ['exclude_unless', 'interactions.*.type', 'button', 'hotspot', 'image'], 'nullable', 'string'],
    'interactions.*.should_enable_sound' => [['exclude_unless', 'interactions.*.type', 'button', 'hotspot', 'image'], 'boolean'],
    'interactions.*.should_fade_in' => [['exclude_unless', 'interactions.*.type', 'button', 'hotspot', 'image', 'text'], 'boolean'],
    'interactions.*.should_fade_out' => [['exclude_unless', 'interactions.*.type', 'button', 'hotspot', 'image', 'text'], 'boolean'],
    'interactions.*.image_url' => ['bail', ['exclude_unless', 'interactions.*.type', 'image', 'hotspot'], ['required_if', 'interactions.*.type', 'image'], 'nullable', 'string'],
    'interactions.*.style.top' => [['exclude_unless', 'interactions.*.type', 'button', 'hotspot', 'image', 'text', 'iframe'], 'string'],
    'interactions.*.style.left' => [['exclude_unless', 'interactions.*.type', 'button', 'hotspot', 'image', 'text', 'iframe'], 'string'],
    'interactions.*.style.height' => [['exclude_unless', 'interactions.*.type', 'button', 'hotspot', 'image', 'text', 'iframe'], 'string'],
    'interactions.*.style.width' => [['exclude_unless', 'interactions.*.type', 'button', 'hotspot', 'image', 'text', 'iframe'], 'string'],
    'interactions.*.style.background_color' => [['exclude_unless', 'interactions.*.type', 'button', 'hotspot', 'text'], 'string'],
    'interactions.*.style.border_radius' => ['bail', ['exclude_unless', 'interactions.*.type', 'button', 'hotspot'], 'nullable', 'numeric', 'integer', 'between:0,360'],
    'interactions.*.style.padding_top' => [['exclude_unless', 'interactions.*.type', 'button', 'hotspot', 'text'], 'nullable', 'numeric', 'integer'],
    'interactions.*.style.padding_bottom' => [['exclude_unless', 'interactions.*.type', 'button', 'hotspot', 'text'], 'nullable', 'numeric', 'integer'],
    'interactions.*.style.border' => [['exclude_unless', 'interactions.*.type', 'button', 'hotspot', 'text'], 'nullable', 'array'],
    'interactions.*.style.border.width' => ['nullable', 'numeric', 'integer', 'min:0'],
    'interactions.*.style.border.style' => ['nullable', 'string', Rule::in(['solid', 'dashed', 'dotted', 'none'])],
    'interactions.*.style.border.color' => ['nullable', 'string'],
    'interactions.*.action' => ['nullable', 'array'],
    'interactions.*.action.type' => ['nullable', 'string', Rule::in(['link', 'time', 'video', 'none'])],
    'interactions.*.action.link' => ['nullable', 'string'],
    'interactions.*.action.time' => ['nullable', 'numeric'],
    'interactions.*.attributes' => ['nullable', 'array'],
    'interactions.*.attributes.show_indicator' => ['nullable', 'boolean'],
    'interactions.*.attributes.indicator_color' => ['nullable', 'string'],
    'interactions.*.attributes.blinking_speed' => ['nullable', 'string', Rule::in(['slow', 'normal', 'fast', 'none'])],
    'interactions.*.attributes.options' => ['nullable', 'array'],
    'interactions.*.attributes.options.menu_button_location' => ['nullable', 'string'],
    'interactions.*.attributes.options.menu_button_name' => ['nullable', 'string'],
    'interactions.*.chapters' => [['exclude_unless', 'interactions.*.type', 'chapter'], 'array', 'min:1', 'max:16'],
    'interactions.*.chapters.*.title' => [['exclude_unless', 'interactions.*.type', 'chapter'], 'required', 'string'],
    'interactions.*.chapters.*.title_short' => [['exclude_unless', 'interactions.*.type', 'chapter'], 'nullable', 'string'],
    'interactions.*.chapters.*.start_time' => [['exclude_unless', 'interactions.*.type', 'chapter'], 'required', 'numeric', 'min:0'],
    'interactions.*.chapters.*.end_time' => [['exclude_unless', 'interactions.*.type', 'chapter'], 'required', 'numeric'],
    'interactions.*.chapters.*.sort_order' => [['exclude_unless', 'interactions.*.type', 'chapter'], 'required', 'integer', 'min:0'],
];

$scenarios['Conditional import — 100 items, 47 conditional fields'] = [
    'optimizations' => 'Wildcard, pre-evaluation',
    'native' => [
        $conditionalRules,
        ['interactions' => $interactions100],
    ],
    'fluent' => [
        $conditionalRules,
        ['interactions' => $interactions100],
    ],
];

// --- 6. Login form — no wildcards, minimal rules ---
$scenarios['Login form — 3 fields, no wildcards'] = [
    'optimizations' => 'Fast-check (flat)',
    'native' => [
        ['email' => 'required|string|email|max:255', 'password' => 'required|string|min:8', 'remember' => 'nullable|boolean'],
        ['email' => 'user@example.com', 'password' => 'secure-password-123', 'remember' => true],
    ],
    'fluent' => [
        ['email' => FluentRule::email()->required()->max(255), 'password' => FluentRule::string()->required()->min(8), 'remember' => FluentRule::boolean()->nullable()],
        ['email' => 'user@example.com', 'password' => 'secure-password-123', 'remember' => true],
    ],
];

// =========================================================================
// Run benchmarks
// =========================================================================

$results = [];

foreach ($scenarios as $label => $config) {
    [$nativeRules, $nativeData] = $config['native'];
    [$fluentRules, $fluentData] = $config['fluent'];

    $native = benchNativeLaravel($nativeRules, $nativeData);
    $ruleSet = benchRuleSet($fluentRules, $fluentData);

    $speedup = $ruleSet > 0 ? $native / $ruleSet : 0;

    $results[$label] = [
        'optimizations' => $config['optimizations'],
        'native_ms' => round($native, 1),
        'optimized_ms' => round($ruleSet, 1),
        'speedup' => round($speedup, 1),
    ];
}

// =========================================================================
// Output
// =========================================================================

$snapshotFile = __DIR__ . '/benchmark-snapshot.json';

if ($isSnapshot) {
    file_put_contents($snapshotFile, json_encode($results, JSON_PRETTY_PRINT) . "\n");
    echo "Snapshot saved to benchmark-snapshot.json\n";
    exit(0);
}

if ($isJson) {
    echo json_encode($results, JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

$formatSpeedup = fn (float $x): string => $x >= 1.5 ? sprintf('**~%.0fx**', $x) : sprintf('~%.0fx', $x);

if ($isCi) {
    $snapshot = file_exists($snapshotFile) ? json_decode(file_get_contents($snapshotFile), true) : null;

    // Ignore legacy flat-format snapshots from the old single-scenario benchmark.php
    if ($snapshot !== null && isset($snapshot['native_ms']) && ! isset($snapshot['native_ms']['native_ms'])) {
        $snapshot = null;
    }

    echo "## Benchmark results\n\n";
    echo '| Scenario | Optimizations | Native Laravel | Optimized | Speedup |';

    if ($snapshot !== null) {
        echo ' Δ vs base |';
    }

    echo "\n";
    echo '|----------|---------------|---------------:|----------:|--------:|';

    if ($snapshot !== null) {
        echo '----------:|';
    }

    echo "\n";

    foreach ($results as $label => $r) {
        echo sprintf(
            '| %s | %s | %.1fms | %.1fms | %s |',
            $label,
            $r['optimizations'],
            $r['native_ms'],
            $r['optimized_ms'],
            $formatSpeedup($r['speedup']),
        );

        if ($snapshot !== null && isset($snapshot[$label])) {
            $baseMs = $snapshot[$label]['optimized_ms'];

            if ($baseMs > 0) {
                $delta = (($r['optimized_ms'] - $baseMs) / $baseMs) * 100;
                echo sprintf(' %+.0f%% |', $delta);
            } else {
                echo ' — |';
            }
        } elseif ($snapshot !== null) {
            echo ' new |';
        }

        echo "\n";
    }

    exit(0);
}

// Console output (default)
fprintf(STDERR, "\n");
fprintf(STDERR, "  %-50s %-28s %10s %10s %10s\n", 'Scenario', 'Optimizations', 'Native', 'Optimized', 'Speedup');
fprintf(STDERR, "  %s\n", str_repeat('─', 112));

foreach ($results as $label => $r) {
    fprintf(
        STDERR,
        "  %-50s %-28s %9.1fms %9.1fms %9.0fx\n",
        $label,
        $r['optimizations'],
        $r['native_ms'],
        $r['optimized_ms'],
        $r['speedup'],
    );
}

fprintf(STDERR, "\n");
