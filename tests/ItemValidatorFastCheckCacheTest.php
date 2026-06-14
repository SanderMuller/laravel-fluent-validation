<?php declare(strict_types=1);

use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\RuleSet;

// Regression coverage for the per-call buildFastChecks memo ($fastChecksByReduced)
// added to ItemValidator's sibling-conditional path. A value-conditional
// (required_if on a within-item sibling field) makes the rule set diverge per
// item, so dispatchField is null and every item flows through the memoized
// `elseif` branch — the path this cache optimizes.
//
// The memo keys compiled fast-checks by ruleCacheKey($effectiveRules). These
// tests guard that it (a) returns correct verdicts when items reduce to the
// SAME rule set (memo hit), (b) never reuses one reduction's fast-checks for an
// item that reduced DIFFERENTLY (cache-key-collision regression), and (c) stays
// correct at volume.

/**
 * @param  list<array<string, mixed>>  $items
 * @return array<string, array<int, string>>
 */
function runMemoConditionalItems(array $items): array
{
    /** @var array<string, array<int, string>> */
    return RuleSet::from([
        'addresses.*.postcode' => FluentRule::field()->requiredIf('flag', 'admin')->rule('string'),
    ])->check(['addresses' => $items])->errors()->toArray();
}

it('returns correct verdicts when most items reduce to the same rule set', function (): void {
    // 9 inactive items (flag=user → postcode optional) all reduce identically and
    // must hit the memo without leaking an error; 1 active item (flag=admin) is
    // missing its now-required postcode and must be the only failure.
    $items = array_fill(0, 9, ['flag' => 'user']);
    $items[] = ['flag' => 'admin'];

    expect(array_keys(runMemoConditionalItems($items)))->toBe(['addresses.9.postcode']);
});

it('does not cross-contaminate verdicts across divergent reductions', function (): void {
    // item 0 reduces to "postcode required" (missing → fail); item 1 reduces to
    // "postcode optional" (missing → pass). A field-name-only key would wrongly
    // reuse item 0's fast-checks for item 1.
    $errors = runMemoConditionalItems([
        ['flag' => 'admin'],
        ['flag' => 'user'],
    ]);

    expect($errors)->toHaveKey('addresses.0.postcode')
        ->and($errors)->not->toHaveKey('addresses.1.postcode');
});

it('matches native Laravel verdicts across a mixed batch', function (): void {
    $items = [
        ['flag' => 'admin', 'postcode' => '1234AB'], // active, present → pass
        ['flag' => 'admin'],                          // active, missing → fail
        ['flag' => 'user'],                           // inactive, missing → pass
        ['flag' => 'admin', 'postcode' => '5678CD'], // active, present → pass
    ];

    $fluent = RuleSet::from([
        'addresses.*.postcode' => FluentRule::field()->requiredIf('flag', 'admin')->rule('string'),
    ])->check(['addresses' => $items])->fails();

    $native = validator(['addresses' => $items], [
        'addresses.*.postcode' => 'required_if:addresses.*.flag,admin|string',
    ])->fails();

    expect($fluent)->toBeTrue()->and($fluent)->toBe($native);
});

it('validates a large run of identical reductions correctly', function (): void {
    $items = array_fill(0, 50, ['flag' => 'admin', 'postcode' => '1234AB']);

    $fails = RuleSet::from([
        'addresses.*.postcode' => FluentRule::field()->requiredIf('flag', 'admin')->rule('string'),
    ])->check(['addresses' => $items])->fails();

    expect($fails)->toBeFalse();
});
