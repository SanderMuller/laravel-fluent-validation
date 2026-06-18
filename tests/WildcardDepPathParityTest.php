<?php declare(strict_types=1);

use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\RuleSet;

// =========================================================================
// Regression: conditional rules whose dependent path is the FULL wildcard
// sibling path (e.g. requiredUnless('items.*.type', …)) rather than a
// relative one ('type'). RuleSet's per-item reducer must strip the
// `items.*.` prefix so it can resolve the dep against the relative item —
// and it must do so for the object (FluentRule) form, not only the array
// form. Object-form rules used to pass through rewriteRulesForPerItem
// untouched, leaving `items.*.type` in the compiled rule and making the
// reducer's data_get miss, so the conditional was mis-evaluated.
//
// Each test asserts RuleSet::check (optimized per-item path) agrees with
// native Laravel for both the object form and the array form.
// =========================================================================

/**
 * Assert RuleSet's optimized per-item verdict matches native Laravel for a
 * wildcard ruleset whose conditional dep references the full wildcard path.
 *
 * @param  array<string, mixed>            $nativeRules  keyed by full wildcard path
 * @param  array<string, Closure(): mixed> $fluentRules  keyed by full wildcard path
 * @param  list<array<string, mixed>>      $items
 */
function assertWildcardDepParity(array $nativeRules, array $fluentRules, array $items): void
{
    foreach ($items as $item) {
        $data = ['items' => [$item]];

        $native = validator($data, $nativeRules)->errors()->toArray();

        $builtRules = [];
        foreach ($fluentRules as $key => $builder) {
            $builtRules[$key] = $builder();
        }

        $fluent = RuleSet::from($builtRules)->check($data)->errors()->toArray();

        expect(array_keys($fluent))
            ->toBe(array_keys($native), 'item: ' . json_encode($item));
    }
}

it('required_unless object-form, wildcard dep, multi-value: parity with native', function (): void {
    assertWildcardDepParity(
        [
            'items.*.type' => ['required', 'string'],
            'items.*.end_time' => ['required_unless:items.*.type,pause,stop', 'numeric'],
        ],
        [
            'items.*.type' => static fn () => FluentRule::field()->required()->rule('string'),
            'items.*.end_time' => static fn () => FluentRule::field()
                ->requiredUnless('items.*.type', 'pause', 'stop')
                ->rule('numeric'),
        ],
        [
            ['type' => 'pause', 'start_time' => 1.0],            // dep matches → not required → no error
            ['type' => 'stop'],                                  // dep matches → not required
            ['type' => 'play'],                                  // dep mismatch → required, missing → error
            ['type' => 'play', 'end_time' => 2.0],               // dep mismatch → required, present
        ],
    );
});

it('required_unless array-form, wildcard dep, multi-value: parity with native', function (): void {
    assertWildcardDepParity(
        [
            'items.*.type' => ['required', 'string'],
            'items.*.end_time' => ['required_unless:items.*.type,pause,stop', 'numeric'],
        ],
        [
            'items.*.type' => static fn () => ['required', 'string'],
            'items.*.end_time' => static fn () => [['required_unless', 'items.*.type', 'pause', 'stop'], 'numeric'],
        ],
        [
            ['type' => 'pause'],
            ['type' => 'play'],
        ],
    );
});

it('object-form conditional preserves its label through per-item flattening', function (): void {
    // The metadata-preservation half of the fix: rewriteRulesForPerItem flattens
    // the FluentRule object to reach its wildcard dep, but the label must still
    // surface in the message. Without extracting metadata before the rewrite, the
    // message would fall back to the raw attribute path ('items.0.end_time').
    $errors = RuleSet::from([
        'items.*.type' => ['required', 'string'],
        'items.*.end_time' => FluentRule::field('Finish Time')
            ->requiredUnless('items.*.type', 'pause')
            ->rule('numeric'),
    ])->check(['items' => [['type' => 'play']]])->errors();

    expect($errors->first('items.0.end_time'))
        ->toContain('Finish Time')
        ->not->toContain('end_time');
});

it('required_if object-form, wildcard dep, multi-value: parity with native', function (): void {
    assertWildcardDepParity(
        [
            'items.*.type' => ['required', 'string'],
            'items.*.image_url' => ['required_if:items.*.type,image,hotspot', 'nullable', 'string'],
        ],
        [
            'items.*.type' => static fn () => FluentRule::field()->required()->rule('string'),
            'items.*.image_url' => static fn () => FluentRule::field()
                ->requiredIf('items.*.type', 'image', 'hotspot')
                ->nullable()
                ->rule('string'),
        ],
        [
            ['type' => 'image'],                                 // dep matches → required, missing → error
            ['type' => 'image', 'image_url' => 'x.png'],         // present
            ['type' => 'hotspot'],                               // dep matches → required, missing → error
            ['type' => 'text'],                                  // dep mismatch → not required
        ],
    );
});

it('exclude_unless object-form, wildcard dep, multi-value: parity with native', function (): void {
    assertWildcardDepParity(
        [
            'items.*.type' => ['required', 'string'],
            'items.*.position' => ['exclude_unless:items.*.type,chapter,menu', 'required', 'string'],
        ],
        [
            'items.*.type' => static fn () => FluentRule::field()->required()->rule('string'),
            'items.*.position' => static fn () => FluentRule::field()
                ->excludeUnless('items.*.type', 'chapter', 'menu')
                ->required()
                ->rule('string'),
        ],
        [
            ['type' => 'chapter'],                               // included → required, missing → error
            ['type' => 'chapter', 'position' => 'top'],          // included → present
            ['type' => 'button'],                                // excluded → no error even though missing
            ['type' => 'menu'],                                  // included → required, missing → error
        ],
    );
});

it('prohibited_unless object-form, wildcard dep, multi-value: parity with native', function (): void {
    assertWildcardDepParity(
        [
            'items.*.type' => ['required', 'string'],
            'items.*.legacy' => ['prohibited_unless:items.*.type,old,ancient', 'string'],
        ],
        [
            'items.*.type' => static fn () => FluentRule::field()->required()->rule('string'),
            'items.*.legacy' => static fn () => FluentRule::field()
                ->prohibitedUnless('items.*.type', 'old', 'ancient')
                ->rule('string'),
        ],
        [
            ['type' => 'old', 'legacy' => 'x'],                  // dep matches → not prohibited → present ok
            ['type' => 'new', 'legacy' => 'x'],                  // dep mismatch → prohibited, present → error
            ['type' => 'new'],                                   // prohibited, absent → ok
        ],
    );
});
