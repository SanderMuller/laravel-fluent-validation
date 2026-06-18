<?php declare(strict_types=1);

use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\FluentValidator;

// =========================================================================
// The fluent `->excludeUnless()` API (and any string-form exclude_* rule)
// compiles to the STRING form `exclude_unless:field,vals`, not the array
// tuple. The three exclude pre-evaluation sites used to recognise only the
// tuple form, so fluent-API rulesets were never pruned — the validator stayed
// fully expanded and quadratic. These tests pin that the string form (flat,
// nested children, and enum-valued) now prunes like the tuple form.
//
// getRules() reflects the rule set after preExcludeRules has run in the
// FluentValidator constructor, so a reduced count proves pruning engaged.
// =========================================================================

enum PruneType: string
{
    case CHAPTER = 'chapter';
    case MENU = 'menu';
}

/**
 * @param  array<string, mixed>  $rules
 * @param  array<string, mixed>  $data
 */
function makePruneValidator(array $rules, array $data): FluentValidator
{
    return new class ($data, $rules) extends FluentValidator {};
}

/** @return array<string, mixed> */
function pauseItems(int $n): array
{
    return ['items' => array_map(static fn (int $i): array => ['type' => 'pause'], range(1, $n))];
}

it('prunes flat fluent excludeUnless like the array-tuple form', function (): void {
    $data = pauseItems(10);

    $tuple = makePruneValidator([
        'items.*.type' => ['required', 'string'],
        'items.*.a' => [['exclude_unless', 'items.*.type', 'chapter'], 'required', 'string'],
        'items.*.b' => [['exclude_unless', 'items.*.type', 'chapter'], 'required', 'string'],
    ], $data);

    $fluent = makePruneValidator([
        'items.*.type' => ['required', 'string'],
        'items.*.a' => FluentRule::field()->excludeUnless('items.*.type', 'chapter')->required()->rule('string'),
        'items.*.b' => FluentRule::field()->excludeUnless('items.*.type', 'chapter')->required()->rule('string'),
    ], $data);

    // All 'pause' items exclude a + b → only the 10 `type` rules remain.
    expect($tuple->getRules())->toHaveCount(10)
        ->and($fluent->getRules())->toHaveCount(10);
});

it('prunes fluent excludeUnless on a nested child keyed off the parent wildcard type', function (): void {
    $data = pauseItems(10);

    $validator = makePruneValidator([
        'items.*.type' => ['required', 'string'],
        'items.*.style' => FluentRule::array()->children([
            'top' => FluentRule::string()->excludeUnless('items.*.type', 'chapter')->required(),
            'left' => FluentRule::string()->excludeUnless('items.*.type', 'chapter')->required(),
        ]),
    ], $data);

    // For pause items the nested style.top / style.left are excluded; no leaf
    // child rules should remain in the rule set.
    $leafKeys = array_filter(
        array_keys($validator->getRules()),
        static fn (string $k): bool => str_contains($k, '.style.'),
    );

    expect($leafKeys)
        ->toBeEmpty();
});

it('prunes fluent excludeUnless with BackedEnum value args', function (): void {
    $data = pauseItems(10);

    $validator = makePruneValidator([
        'items.*.type' => ['required', 'string'],
        'items.*.a' => FluentRule::field()->excludeUnless('items.*.type', PruneType::CHAPTER, PruneType::MENU)->required()->rule('string'),
    ], $data);

    // enum cases serialize to their ->value ('chapter','menu'); 'pause' is in
    // neither → excluded → only the 10 `type` rules remain.
    expect($validator->getRules())->toHaveCount(10);
});

it('benchmarks fluent excludeUnless: linear after pruning', function (): void {
    $build = static function (int $n): float {
        $data = ['items' => array_map(static fn (int $i): array => ['type' => 'pause', 'start_time' => 1.0], range(1, $n))];
        $rules = ['items.*.type' => ['required', 'string']];
        // 20 fluent excludeUnless fields per item, all excluded for 'pause'.
        for ($f = 0; $f < 20; ++$f) {
            $rules["items.*.f{$f}"] = FluentRule::field()->excludeUnless('items.*.type', 'chapter')->required()->rule('string');
        }

        $start = hrtime(true);
        makePruneValidator($rules, $data)->validate();

        return (hrtime(true) - $start) / 1e6;
    };

    $build(10); // warmup
    $t50 = $build(50);
    $t100 = $build(100);

    fprintf(STDERR, "\n  fluent excludeUnless: N=50 %.0fms | N=100 %.0fms\n", $t50, $t100);

    // Linear, not quadratic: doubling N must stay well under a 3x time bump.
    expect($t100)->toBeLessThan($t50 * 3);
})->group('benchmark');
