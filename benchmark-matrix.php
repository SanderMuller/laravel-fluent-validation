<?php declare(strict_types=1);

/**
 * Comprehensive benchmark matrix for laravel-fluent-validation.
 *
 * Tests all validation paths across different rule types and data shapes.
 * Run: php benchmark-matrix.php
 *
 * Used by autoresearch agents to measure optimization impact across all scenarios.
 */

require __DIR__ . '/vendor/autoload.php';

$app = (new Application())->createApplication();

// =========================================================================
// Data generators
// =========================================================================

function makeUsers(int $count): array
{
    return array_map(fn (int $i): array => [
        'name' => "User {$i}",
        'email' => "user{$i}@example.com",
        'username' => 'user-' . $i,
        'country' => ['US', 'NL', 'DE', 'GB', 'FR'][$i % 5],
        'website' => 'https://example.com/' . $i,
        'joined' => '2025-06-15',
        'agree_tos' => true,
    ], range(1, $count));
}

function makeInteractions(int $count): array
{
    $types = ['button', 'hotspot', 'scroll_area', 'image', 'chapter', 'menu', 'frame', 'text', 'iframe'];

    return array_map(fn (int $i): array => [
        'type' => $types[$i % count($types)],
        'title' => "Interaction {$i}",
        'start_time' => $i * 10,
        'end_time' => $i * 10 + 5,
        'position' => 'bottom',
        'should_start_collapsed' => false,
        'text' => '<p>Sample</p>',
        'style' => ['top' => '10%', 'left' => '20%', 'height' => '30%', 'width' => '40%'],
    ], range(1, $count));
}

function makeNested(int $items, int $optionsPerItem): array
{
    return array_map(fn (int $i): array => [
        'name' => "Item {$i}",
        'options' => array_map(fn (int $j): array => [
            'label' => "Option {$j}",
            'value' => $j,
        ], range(1, $optionsPerItem)),
    ], range(1, $items));
}

// =========================================================================
// Benchmark runner
// =========================================================================

function benchMedian(Closure $fn, int $iterations = 5): float
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

    return $times[(int) floor(count($times) / 2)];
}

function benchFormRequest(array $rules, array $data): float
{
    $factory = resolve(Factory::class);

    $fr = new class extends FormRequest {
        use HasFluentRules;

        public static array $testRules = [];

        public function rules(): array
        {
            return self::$testRules;
        }

        public function authorize(): bool
        {
            return true;
        }
    };
    $fr::$testRules = $rules;

    $request = Request::create('/test', 'POST', $data);
    $instance = $fr::createFrom($request);
    $instance->setContainer(app());
    $instance->setRedirector(resolve(Redirector::class));

    return benchMedian(fn () => (fn () => $this->createDefaultValidator($factory))->call($instance)->validate());
}

function benchRuleSet(array $rules, array $data): float
{
    return benchMedian(fn () => RuleSet::from($rules)->validate($data));
}

function benchNativeLaravel(array $rules, array $data): float
{
    return benchMedian(fn () => Validator::make($data, $rules)->validate());
}

// =========================================================================
// Scenarios
// =========================================================================

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Orchestra\Testbench\Foundation\Application;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\HasFluentRules;
use SanderMuller\FluentValidation\RuleSet;

$users500 = makeUsers(500);
$users50 = makeUsers(50);
$interactions100 = makeInteractions(100);
$nested100x5 = makeNested(100, 5);

$scenarios = [];

// --- 1. Fast-checkable, no wildcards ---
$scenarios['No wildcards (5 fields)'] = [
    'native' => [
        ['name' => 'required|string|max:255', 'email' => 'required|email', 'age' => 'nullable|numeric|min:0', 'url' => 'nullable|url', 'agree' => 'required|accepted'],
        ['name' => 'John', 'email' => 'john@example.com', 'age' => 25, 'url' => 'https://example.com', 'agree' => true],
    ],
    'fluent' => [
        ['name' => FluentRule::string()->required()->max(255), 'email' => FluentRule::email()->required(), 'age' => FluentRule::numeric()->nullable()->min(0), 'url' => FluentRule::string()->nullable()->url(), 'agree' => FluentRule::boolean()->accepted()],
        ['name' => 'John', 'email' => 'john@example.com', 'age' => 25, 'url' => 'https://example.com', 'agree' => true],
    ],
];

// --- 2. Fast-checkable with wildcards (string/email/in) ---
$scenarios['500 items × 3 fast-checkable'] = [
    'native' => [
        ['users' => 'required|array', 'users.*.name' => 'required|string|min:2|max:255', 'users.*.email' => 'required|string|email|max:255', 'users.*.country' => ['required', 'string', Rule::in(['US', 'NL', 'DE', 'GB', 'FR'])]],
        ['users' => $users500],
    ],
    'fluent' => [
        ['users' => FluentRule::array()->required()->each(['name' => FluentRule::string()->required()->min(2)->max(255), 'email' => FluentRule::email()->required()->max(255), 'country' => FluentRule::string()->required()->in(['US', 'NL', 'DE', 'GB', 'FR'])])],
        ['users' => $users500],
    ],
];

// --- 3. Date rules with wildcards (now fast-checkable) ---
$scenarios['500 items × 4 with date+before'] = [
    'native' => [
        ['users' => 'required|array', 'users.*.name' => 'required|string|max:255', 'users.*.email' => 'required|email', 'users.*.joined' => 'required|date|before:2030-01-01', 'users.*.country' => ['required', 'string', Rule::in(['US', 'NL', 'DE', 'GB', 'FR'])]],
        ['users' => $users500],
    ],
    'fluent' => [
        ['users' => FluentRule::array()->required()->each(['name' => FluentRule::string()->required()->max(255), 'email' => FluentRule::email()->required(), 'joined' => FluentRule::date()->required()->before('2030-01-01'), 'country' => FluentRule::string()->required()->in(['US', 'NL', 'DE', 'GB', 'FR'])])],
        ['users' => $users500],
    ],
];

// --- 4. Mixed fast + slow (string + boolean per-item) ---
$scenarios['500 items × 2 string+boolean'] = [
    'native' => [
        ['users' => 'required|array', 'users.*.name' => 'required|string|min:2|max:255', 'users.*.agree_tos' => 'required|boolean'],
        ['users' => $users500],
    ],
    'fluent' => [
        ['users' => FluentRule::array()->required()->each(['name' => FluentRule::string()->required()->min(2)->max(255), 'agree_tos' => FluentRule::boolean()->required()])],
        ['users' => $users500],
    ],
];

// --- 5. Small array (50 items) ---
$scenarios['50 items × 3 fast-checkable'] = [
    'native' => [
        ['users' => 'required|array', 'users.*.name' => 'required|string|min:2|max:255', 'users.*.email' => 'required|string|email|max:255', 'users.*.country' => ['required', 'string', Rule::in(['US', 'NL', 'DE', 'GB', 'FR'])]],
        ['users' => $users50],
    ],
    'fluent' => [
        ['users' => FluentRule::array()->required()->each(['name' => FluentRule::string()->required()->min(2)->max(255), 'email' => FluentRule::email()->required()->max(255), 'country' => FluentRule::string()->required()->in(['US', 'NL', 'DE', 'GB', 'FR'])])],
        ['users' => $users50],
    ],
];

// --- 6. Nested wildcards ---
$scenarios['100×5 nested wildcards'] = [
    'native' => [
        ['items' => 'required|array', 'items.*.name' => 'required|string|max:255', 'items.*.options' => 'required|array', 'items.*.options.*.label' => 'required|string|max:100', 'items.*.options.*.value' => 'required|numeric|min:0'],
        ['items' => $nested100x5],
    ],
    'fluent' => [
        ['items' => FluentRule::array()->required()->each(['name' => FluentRule::string()->required()->max(255), 'options' => FluentRule::array()->required()->each(['label' => FluentRule::string()->required()->max(100), 'value' => FluentRule::numeric()->required()->min(0)])])],
        ['items' => $nested100x5],
    ],
];

// --- 7. 7 fields registration form (realistic) ---
$scenarios['500 items × 7 registration'] = [
    'native' => [
        ['users' => 'required|array', 'users.*.name' => 'required|string|min:2|max:255', 'users.*.email' => 'required|string|email|max:255', 'users.*.username' => ['required', 'string', 'regex:/\A[a-zA-Z0-9_-]+\z/', 'max:40'], 'users.*.country' => ['required', 'string', Rule::in(['US', 'NL', 'DE', 'GB', 'FR'])], 'users.*.website' => 'nullable|string|url', 'users.*.agree_tos' => 'required|accepted'],
        ['users' => $users500],
    ],
    'fluent' => [
        ['users' => FluentRule::array()->required()->each(['name' => FluentRule::string()->required()->min(2)->max(255), 'email' => FluentRule::email()->required()->max(255), 'username' => FluentRule::string()->required()->regex('/\A[a-zA-Z0-9_-]+\z/')->max(40), 'country' => FluentRule::string()->required()->in(['US', 'NL', 'DE', 'GB', 'FR']), 'website' => FluentRule::string()->nullable()->url(), 'agree_tos' => FluentRule::boolean()->accepted()])],
        ['users' => $users500],
    ],
];

// =========================================================================
// Run benchmarks
// =========================================================================

fprintf(STDERR, "\n");
fprintf(STDERR, "  %-35s %10s %10s %10s %10s %10s\n", 'Scenario', 'Native', 'FormReq', 'RuleSet', 'FR speed', 'RS speed');
fprintf(STDERR, "  %s\n", str_repeat('─', 95));

foreach ($scenarios as $label => $config) {
    [$nativeRules, $nativeData] = $config['native'];
    [$fluentRules, $fluentData] = $config['fluent'];

    $native = benchNativeLaravel($nativeRules, $nativeData);
    $formReq = benchFormRequest($fluentRules, $fluentData);
    $ruleSet = benchRuleSet($fluentRules, $fluentData);

    $frSpeedup = $native / $formReq;
    $rsSpeedup = $native / $ruleSet;

    fprintf(STDERR, "  %-35s %9.1fms %9.1fms %9.1fms %9.0fx %9.0fx\n",
        $label, $native, $formReq, $ruleSet, $frSpeedup, $rsSpeedup);
}

fprintf(STDERR, "\n");
