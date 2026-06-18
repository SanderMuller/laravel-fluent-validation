<?php declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule as LaravelRule;
use SanderMuller\FluentValidation\FluentValidator;
use SanderMuller\FluentValidation\RuleSet;

/**
 * Concrete FluentValidator for the benchmark — the import-style conditional
 * ruleset the peer reported O(n²) on.
 */
final class BenchImportValidator extends FluentValidator
{
    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        $types = ['button', 'hotspot', 'image', 'text', 'chapter', 'menu', 'pause'];

        parent::__construct($data, [
            'interactions' => 'required|array|min:1',
            'interactions.*.type' => ['required', 'string', LaravelRule::in($types)],
            'interactions.*.title' => ['nullable', 'string'],
            'interactions.*.start_time' => ['required', 'numeric', 'min:0'],
            'interactions.*.end_time' => [['required_unless', 'interactions.*.type', 'pause'], 'numeric', 'gte:interactions.*.start_time'],
            'interactions.*.position' => [['exclude_unless', 'interactions.*.type', 'chapter', 'menu'], 'string'],
            'interactions.*.text' => [['exclude_unless', 'interactions.*.type', 'button', 'hotspot', 'text'], 'nullable', 'string'],
            'interactions.*.image_url' => [['exclude_unless', 'interactions.*.type', 'image', 'hotspot'], ['required_if', 'interactions.*.type', 'image'], 'nullable', 'string'],
            'interactions.*.should_fade_in' => [['exclude_unless', 'interactions.*.type', 'button', 'hotspot', 'image', 'text'], 'boolean'],
            'interactions.*.style.top' => [['exclude_unless', 'interactions.*.type', 'button', 'hotspot', 'image', 'text'], 'string'],
            'interactions.*.style.left' => [['exclude_unless', 'interactions.*.type', 'button', 'hotspot', 'image', 'text'], 'string'],
        ]);
    }
}

/** @return array<string, mixed> */
function benchItems(int $n): array
{
    $types = ['button', 'hotspot', 'image', 'text', 'chapter', 'menu', 'pause'];

    return ['interactions' => array_map(static function (int $i) use ($types): array {
        $type = $types[$i % count($types)];

        return [
            'type' => $type,
            'title' => 'Item ' . $i,
            'start_time' => 1.0,
            'end_time' => 2.0,
            'position' => 'top',
            'text' => 'hello',
            'image_url' => $type === 'image' ? 'https://example.com/x.png' : null,
            'should_fade_in' => true,
            'style' => ['top' => '0px', 'left' => '0px'],
        ];
    }, range(0, $n - 1))];
}

/** @return array<string, mixed> */
function benchNativeRules(): array
{
    $types = ['button', 'hotspot', 'image', 'text', 'chapter', 'menu', 'pause'];

    return [
        'interactions' => 'required|array|min:1',
        'interactions.*.type' => ['required', 'string', LaravelRule::in($types)],
        'interactions.*.title' => ['nullable', 'string'],
        'interactions.*.start_time' => ['required', 'numeric', 'min:0'],
        'interactions.*.end_time' => ['required_unless:interactions.*.type,pause', 'numeric', 'gte:interactions.*.start_time'],
        'interactions.*.position' => ['exclude_unless:interactions.*.type,chapter,menu', 'string'],
        'interactions.*.text' => ['exclude_unless:interactions.*.type,button,hotspot,text', 'nullable', 'string'],
        'interactions.*.image_url' => ['exclude_unless:interactions.*.type,image,hotspot', 'required_if:interactions.*.type,image', 'nullable', 'string'],
        'interactions.*.should_fade_in' => ['exclude_unless:interactions.*.type,button,hotspot,image,text', 'boolean'],
        'interactions.*.style.top' => ['exclude_unless:interactions.*.type,button,hotspot,image,text', 'string'],
        'interactions.*.style.left' => ['exclude_unless:interactions.*.type,button,hotspot,image,text', 'string'],
    ];
}

it('benchmarks FluentValidator vs native vs RuleSet::validate (conditional import)', function (): void {
    fprintf(STDERR, "\n  FluentValidator conditional-import benchmark (median of 3)\n");
    fprintf(STDERR, "  %-6s %12s %16s %16s\n", 'N', 'Native', 'FluentValidator', 'RuleSet::validate');
    fprintf(STDERR, "  %s\n", str_repeat('─', 56));

    foreach ([25, 50, 100] as $n) {
        $data = benchItems($n);
        $nativeRules = benchNativeRules();

        // Warmup
        Validator::make($data, $nativeRules)->validate();
        (new BenchImportValidator($data))->validate();
        RuleSet::from($nativeRules)->validate($data);

        $native = benchmarkMedian(fn () => Validator::make($data, $nativeRules)->validate(), 3);
        $fluent = benchmarkMedian(fn () => (new BenchImportValidator($data))->validate(), 3);
        $ruleSet = benchmarkMedian(fn () => RuleSet::from($nativeRules)->validate($data), 3);

        fprintf(STDERR, "  %-6d %10.0fms %12.0fms (%4.1fx) %10.0fms (%4.1fx)\n",
            $n, $native, $fluent, $native / $fluent, $ruleSet, $native / $ruleSet);
    }

    fprintf(STDERR, "\n");

    $data = benchItems(100);
    $nativeRules = benchNativeRules();
    Validator::make($data, $nativeRules)->validate();
    (new BenchImportValidator($data))->validate();
    $native = benchmarkMedian(fn () => Validator::make($data, $nativeRules)->validate(), 3);
    $fluent = benchmarkMedian(fn () => (new BenchImportValidator($data))->validate(), 3);

    expect($fluent)->toBeLessThan($native);
})->group('benchmark');
