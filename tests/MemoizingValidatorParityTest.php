<?php declare(strict_types=1);

use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as BaseValidator;
use SanderMuller\FluentValidation\MemoizingValidator;
use SanderMuller\FluentValidation\OptimizedValidator;

/**
 * Parity suite for {@see MemoizingValidator}. The class memoizes string-rule
 * parsing inside `getRule()`; its ONLY contract is that output stays
 * byte-identical to the vanilla {@see BaseValidator}. A drift here means the
 * memo changed a verdict, a message, or the `validated()` payload — the same
 * class of silent bug the fast-check parity suite guards against.
 *
 * Each scenario is validated by both a vanilla and a memoizing validator built
 * from the SAME translator, data, and rules; we assert pass/fail, the full
 * message bag, failed-rule map, and (when passing) the validated payload all
 * match exactly. The grid is deliberately heavy on multi-rule attributes,
 * dependent fields, and wildcards — the sites where `getRule()` is probed
 * repeatedly and the memo does its work.
 */
beforeEach(function (): void {
    // Process-wide static; isolate each test so cap-count assertions start clean.
    MemoizingValidator::resetParseCache();
});

/**
 * @param  array<array-key, mixed>  $data
 * @param  array<array-key, mixed>  $rules
 * @return array{0: BaseValidator, 1: MemoizingValidator}
 */
function memoParityPair(array $data, array $rules): array
{
    // A shared translator with no messages loaded: both validators emit the
    // same raw `validation.*` keys, so message parity is about structure and
    // ordering, not human strings.
    $translator = new Translator(new ArrayLoader(), 'en');

    return [
        new BaseValidator($translator, $data, $rules),
        new MemoizingValidator($translator, $data, $rules),
    ];
}

function memoParseCacheCount(): int
{
    $property = new ReflectionProperty(MemoizingValidator::class, 'parsedRuleCache');
    /** @var array<string, mixed> $value */
    $value = $property->getValue();

    return count($value);
}

/** @return iterable<string, array{array<string, mixed>, array<string, mixed>}> */
function memoParityScenarios(): iterable
{
    // Many string rules per attribute → getRule() is probed for each of
    // required/string/min/max/regex on every validation pass.
    yield 'multi-rule string attribute — passing' => [
        ['name' => 'alice'],
        ['name' => 'required|string|min:2|max:10|regex:/^[a-z]+$/'],
    ];
    yield 'multi-rule string attribute — failing several' => [
        ['name' => 'A1'],
        ['name' => 'required|string|min:5|max:10|regex:/^[a-z]+$/'],
    ];

    yield 'sometimes/nullable/bail probes' => [
        ['a' => null, 'b' => 'x'],
        ['a' => 'nullable|string|max:3', 'b' => 'sometimes|bail|string|min:1'],
    ];

    // Dependent rules exercise getRule() from the dependent-field checks.
    yield 'required_if active — failing' => [
        ['type' => 'company', 'vat' => ''],
        ['type' => 'required|in:person,company', 'vat' => 'required_if:type,company|string'],
    ];
    yield 'required_if inactive — passing' => [
        ['type' => 'person', 'vat' => ''],
        ['type' => 'required|in:person,company', 'vat' => 'required_if:type,company|string'],
    ];
    yield 'required_with + same — mismatch' => [
        ['password' => 'secret', 'password_confirmation' => 'other'],
        ['password' => 'required|string', 'password_confirmation' => 'required_with:password|same:password'],
    ];
    yield 'confirmed — passing' => [
        ['password' => 'secret', 'password_confirmation' => 'secret'],
        ['password' => 'required|string|confirmed'],
    ];
    yield 'gt/lte field refs — passing' => [
        ['min' => 3, 'max' => 9, 'val' => 5],
        ['min' => 'required|integer', 'max' => 'required|integer|gt:min', 'val' => 'required|integer|gt:min|lte:max'],
    ];
    yield 'exclude_if drops from validated' => [
        ['ship' => 'no', 'address' => 12345],
        ['ship' => 'required|in:yes,no', 'address' => 'exclude_if:ship,no|required|string'],
    ];

    // Wildcards: getRule() is probed per expanded index.
    yield 'wildcard array — passing' => [
        ['items' => [['name' => 'ab', 'qty' => 2], ['name' => 'cd', 'qty' => 5]]],
        ['items' => 'required|array|min:1', 'items.*.name' => 'required|string|max:5', 'items.*.qty' => 'required|integer|min:1'],
    ];
    yield 'wildcard array — one item failing' => [
        ['items' => [['name' => 'ab', 'qty' => 2], ['name' => 'toolong', 'qty' => 0]]],
        ['items' => 'required|array|min:1', 'items.*.name' => 'required|string|max:5', 'items.*.qty' => 'required|integer|min:1'],
    ];

    // Non-string rules bypass the memo and must parse live, identically.
    yield 'Rule object bypass — failing' => [
        ['role' => 'guest'],
        ['role' => ['required', Rule::in(['admin', 'editor'])]],
    ];
    yield 'closure rule bypass — failing' => [
        ['token' => 'nope'],
        ['token' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
            if ($value !== 'ok') {
                $fail('The :attribute is not ok.');
            }
        }]],
    ];
    yield 'mixed string + Rule object + closure — passing' => [
        ['role' => 'admin', 'flag' => 'ok'],
        [
            'role' => ['required', 'string', Rule::in(['admin', 'editor'])],
            'flag' => ['required', function (string $attribute, mixed $value, Closure $fail): void {
                if ($value !== 'ok') {
                    $fail('bad');
                }
            }],
        ],
    ];
}

it('produces byte-identical output to the vanilla validator', function (array $data, array $rules): void {
    [$vanilla, $memoizing] = memoParityPair($data, $rules);

    $vanillaPasses = $vanilla->passes();
    $memoizingPasses = $memoizing->passes();

    expect($memoizingPasses)->toBe($vanillaPasses, 'pass/fail verdict drifted')
        ->and($memoizing->messages()
            ->toArray())
        ->toBe($vanilla->messages()
            ->toArray(), 'message bag drifted')
        ->and($memoizing->failed())
        ->toBe($vanilla->failed(), 'failed-rule map drifted');

    if ($vanillaPasses) {
        expect($memoizing->validated())->toBe($vanilla->validated(), 'validated() payload drifted');
    }
})->with(memoParityScenarios());

it('populates the parse cache for string rules and reuses it across validators', function (): void {
    expect(memoParseCacheCount())->toBe(0);

    [, $first] = memoParityPair(['name' => 'ab'], ['name' => 'required|string|max:5']);
    $first->passes();

    $countAfterFirst = memoParseCacheCount();
    expect($countAfterFirst)->toBeGreaterThan(0);

    // A second, independent validator over the same rule strings must not grow
    // the cache — the parses are already memoized worker-wide.
    [, $second] = memoParityPair(['name' => 'cd'], ['name' => 'required|string|max:5']);
    $second->passes();

    expect(memoParseCacheCount())->toBe($countAfterFirst);
});

it('shares one static parse cache with OptimizedValidator (not redeclared)', function (): void {
    // The memo must live on the base class so OptimizedValidator inherits the
    // same storage — a redeclared static would split the cache in two.
    $declaring = (new ReflectionProperty(OptimizedValidator::class, 'parsedRuleCache'))->getDeclaringClass();

    expect($declaring->getName())->toBe(MemoizingValidator::class);
});

/**
 * Pins the cap-reset at PARSE_CACHE_MAX = 1024, mirroring the FastCheckCompiler
 * cache contract. High rule-string variance (per-tenant `in:` lists, generated
 * regexes) relies on the drop to avoid unbounded growth on long-lived workers.
 */
it('caches up to the cap before resetting', function (): void {
    for ($i = 1; $i <= 1024; ++$i) {
        [, $validator] = memoParityPair(['f' => 'x'], ['f' => 'max:' . $i]);
        $validator->passes();
    }

    // count >= MAX is checked BEFORE insert, so the 1024th distinct parse fills
    // exactly to the cap without tripping the reset.
    expect(memoParseCacheCount())->toBe(1024);
});

it('drops the cache after the cap is exceeded', function (): void {
    for ($i = 1; $i <= 1024; ++$i) {
        [, $validator] = memoParityPair(['f' => 'x'], ['f' => 'max:' . $i]);
        $validator->passes();
    }

    // The 1025th distinct parse trips the pre-insert reset, then inserts one.
    [, $validator] = memoParityPair(['f' => 'x'], ['f' => 'max:1025']);
    $validator->passes();

    expect(memoParseCacheCount())->toBe(1);
});
