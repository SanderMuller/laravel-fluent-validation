<?php declare(strict_types=1);

use Illuminate\Support\Facades\Lang;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\Rules\FieldRule;
use SanderMuller\FluentValidation\RuleSet;

// =========================================================================
// Phase 1: required_without (single-param) pre-evaluation parity grid.
// Drives the wildcard-item code path in RuleSet::reduceRulesForItem so
// the new pre-eval logic is actually engaged.
// =========================================================================

/**
 * @param  array<int, array<string, mixed>>  $items
 * @param  array<string, string>             $messages
 * @return array<string, array<int, string>>
 */
function runPresenceItems(array $items, array $messages = []): array
{
    $ruleSet = RuleSet::from([
        'addresses.*.postcode' => FluentRule::field()->requiredWithout('birthdate')->rule('string'),
    ]);

    /** @var array<string, array<int, string>> */
    return $ruleSet->check(['addresses' => $items], $messages)->errors()->toArray();
}

// ---------- Parity grid (no custom messages) --------------------------------

it('required_without: target present + sibling present → pass', function (): void {
    $errors = runPresenceItems([['postcode' => '1234AB', 'birthdate' => '1990-01-01']]);
    expect($errors)->toBeEmpty();
});

it('required_without: target present + sibling absent → pass', function (): void {
    $errors = runPresenceItems([['postcode' => '1234AB']]);
    expect($errors)->toBeEmpty();
});

it('required_without: target absent + sibling present → pass (rule inactive, dropped)', function (): void {
    $errors = runPresenceItems([['birthdate' => '1990-01-01']]);
    expect($errors)->toBeEmpty();
});

it('required_without: target absent + sibling absent → fail (rule active, rewritten to required)', function (): void {
    $errors = runPresenceItems([[]]);
    expect($errors)->toHaveKey('addresses.0.postcode');
});

it('required_without: target null + sibling absent → fail', function (): void {
    $errors = runPresenceItems([['postcode' => null]]);
    expect($errors)->toHaveKey('addresses.0.postcode');
});

it('required_without: target empty-string + sibling absent → fail', function (): void {
    $errors = runPresenceItems([['postcode' => '']]);
    expect($errors)->toHaveKey('addresses.0.postcode');
});

it('required_without: target empty-array + sibling absent → fail', function (): void {
    $errors = runPresenceItems([['postcode' => []]]);
    expect($errors)->toHaveKey('addresses.0.postcode');
});

it('required_without: sibling null counts as absent (rule activates)', function (): void {
    $errors = runPresenceItems([['birthdate' => null]]);
    expect($errors)->toHaveKey('addresses.0.postcode');
});

it('required_without: sibling empty-string counts as absent (rule activates)', function (): void {
    $errors = runPresenceItems([['birthdate' => '']]);
    expect($errors)->toHaveKey('addresses.0.postcode');
});

// ---------- Parity against Laravel native (side-by-side) --------------------

it('required_without: verdicts match native Laravel for every shape', function (): void {
    $shapes = [
        ['postcode' => '1234AB', 'birthdate' => '1990-01-01'],
        ['postcode' => '1234AB'],
        ['birthdate' => '1990-01-01'],
        [],
        ['postcode' => null],
        ['postcode' => ''],
        ['postcode' => []],
        ['birthdate' => null],
        ['birthdate' => ''],
    ];

    foreach ($shapes as $shape) {
        $native = validator($shape, ['postcode' => 'required_without:birthdate|string'])->fails();
        $fluent = RuleSet::from(['addresses.*.postcode' => FluentRule::field()->requiredWithout('birthdate')->rule('string')])
            ->check(['addresses' => [$shape]])
            ->fails();

        expect($fluent)->toBe($native, 'shape: ' . json_encode($shape));
    }
});

// ---------- Drop vs rewrite vs keep semantics -------------------------------

it('required_without inactive → rule dropped (no error bag entry even with max:5)', function (): void {
    // max:5 should be a candidate but since required_without is inactive and
    // the field is absent, neither `required` nor `max` should fire.
    $errors = runPresenceItems([['birthdate' => '1990-01-01']]);
    expect($errors)->not->toHaveKey('addresses.0.postcode');
});

// ---------- Message-key preservation ($messages map) ------------------------

it('wildcard-path message key prevents rewrite (rule preserved so Laravel retains match opportunity)', function (): void {
    // We can't guarantee the per-item Validator delivers the user's message
    // text here — that requires wildcard-key rewriting which is a separate
    // per-item-validator concern. What we MUST guarantee: the rule is not
    // rewritten to plain `required`, so the original rule semantics survive
    // and the native `required_without` message shape is used (not the
    // generic `required` one).
    $errors = runPresenceItems([[]], ['addresses.*.postcode.required_without' => 'Vul postcode in']);
    expect($errors)->toHaveKey('addresses.0.postcode');
    $msg = $errors['addresses.0.postcode'][0];
    // Native Laravel `required_without` message mentions the sibling field.
    expect($msg)->toContain('birthdate');
});

it('custom message on bare-field key is used when rule stays active', function (): void {
    $errors = runPresenceItems([[]], ['postcode.required_without' => 'Vul postcode in']);
    expect($errors)->toHaveKey('addresses.0.postcode')
        ->and($errors['addresses.0.postcode'])->toContain('Vul postcode in');
});

it('without a custom message, rule rewrites to plain required (uses generic required message)', function (): void {
    $errors = runPresenceItems([[]]);
    expect($errors)->toHaveKey('addresses.0.postcode');
    // The generic 'required' message should fire — not a required_without-specific string.
    $msg = $errors['addresses.0.postcode'][0];
    expect($msg)->toContain('required')
        ->and($msg)->not->toContain('birthdate');
});

// ---------- Translator override detection -----------------------------------

it('translator validation.custom.{field}.required_without override wins over rewrite', function (): void {
    Lang::addLines([
        'validation.custom.postcode.required_without' => 'Postcode verplicht',
    ], 'en');

    $errors = runPresenceItems([[]]);
    expect($errors)->toHaveKey('addresses.0.postcode')
        ->and($errors['addresses.0.postcode'])->toContain('Postcode verplicht');
});

// ---------- Phase 2: required_with, required_with_all, required_without_all ----

function fieldWithPresenceRule(string $ruleName): FieldRule
{
    $field = FluentRule::field();

    return match ($ruleName) {
        'required_with' => $field->requiredWith('birthdate'),
        'required_without' => $field->requiredWithout('birthdate'),
        'required_with_all' => $field->requiredWithAll('birthdate'),
        'required_without_all' => $field->requiredWithoutAll('birthdate'),
        default => throw new LogicException('Unknown rule ' . $ruleName),
    };
}

/**
 * @param  array<int, array<string, mixed>>  $items
 * @param  array<string, string>             $messages
 * @return array<string, array<int, string>>
 */
function runPresenceItemsForRule(string $ruleName, array $items, array $messages = []): array
{
    $ruleSet = RuleSet::from([
        'addresses.*.postcode' => fieldWithPresenceRule($ruleName)->rule('string'),
    ]);

    /** @var array<string, array<int, string>> */
    return $ruleSet->check(['addresses' => $items], $messages)->errors()->toArray();
}

it('required_with: sibling present → rule active → target absent fails', function (): void {
    $errors = runPresenceItemsForRule('required_with', [['birthdate' => '1990-01-01']]);
    expect($errors)->toHaveKey('addresses.0.postcode');
});

it('required_with: sibling absent → rule inactive → target absent passes', function (): void {
    $errors = runPresenceItemsForRule('required_with', [[]]);
    expect($errors)->toBeEmpty();
});

it('required_with: both present → pass', function (): void {
    $errors = runPresenceItemsForRule('required_with', [['postcode' => '1234AB', 'birthdate' => '1990-01-01']]);
    expect($errors)->toBeEmpty();
});

it('required_with_all: sibling present → rule active → target absent fails (single-param degenerate case)', function (): void {
    $errors = runPresenceItemsForRule('required_with_all', [['birthdate' => '1990-01-01']]);
    expect($errors)->toHaveKey('addresses.0.postcode');
});

it('required_with_all: sibling absent → rule inactive → target absent passes', function (): void {
    $errors = runPresenceItemsForRule('required_with_all', [[]]);
    expect($errors)->toBeEmpty();
});

it('required_without_all: sibling present → rule inactive → target absent passes (single-param degenerate case)', function (): void {
    $errors = runPresenceItemsForRule('required_without_all', [['birthdate' => '1990-01-01']]);
    expect($errors)->toBeEmpty();
});

it('required_without_all: sibling absent → rule active → target absent fails', function (): void {
    $errors = runPresenceItemsForRule('required_without_all', [[]]);
    expect($errors)->toHaveKey('addresses.0.postcode');
});

it('phase 2: verdicts match native Laravel across all four rules × shape grid', function (): void {
    $shapes = [
        ['postcode' => '1234AB', 'birthdate' => '1990-01-01'],
        ['postcode' => '1234AB'],
        ['birthdate' => '1990-01-01'],
        [],
        ['postcode' => null],
        ['postcode' => ''],
        ['postcode' => []],
        ['birthdate' => null],
        ['birthdate' => '   '],
    ];

    $ruleNames = ['required_with', 'required_without', 'required_with_all', 'required_without_all'];

    foreach ($ruleNames as $rule) {
        foreach ($shapes as $shape) {
            $native = validator($shape, ['postcode' => $rule . ':birthdate|string'])->fails();

            $field = fieldWithPresenceRule($rule)->rule('string');
            $fluent = RuleSet::from(['addresses.*.postcode' => $field])
                ->check(['addresses' => [$shape]])
                ->fails();

            expect($fluent)->toBe($native, 'rule=' . $rule . ' shape=' . json_encode($shape));
        }
    }
});

it('phase 2: wildcard translator override preserves original rule for each of the four rules', function (): void {
    $ruleNames = ['required_with', 'required_without', 'required_with_all', 'required_without_all'];

    foreach ($ruleNames as $ruleName) {
        Lang::addLines([
            'validation.custom.addresses.*.postcode.' . $ruleName => 'Custom ' . $ruleName,
        ], 'en');

        // Build a shape that definitely activates each rule → target missing → error must mention the preserved rule name.
        $shape = match ($ruleName) {
            'required_with', 'required_with_all' => ['birthdate' => '1990-01-01'],
            'required_without', 'required_without_all' => [],
        };

        $field = fieldWithPresenceRule($ruleName)->rule('string');
        $ruleSet = RuleSet::from(['addresses.*.postcode' => $field]);
        $errors = $ruleSet->check(['addresses' => [$shape]])->errors()->toArray();

        expect($errors)->toHaveKey('addresses.0.postcode');
        $msg = $errors['addresses.0.postcode'][0];
        // Rule preserved → native message shape contains the rule name, not the generic `required`.
        expect($msg)->toContain($ruleName);
    }
});

// ---------- Phase 3: multi-param parity grid --------------------------------

/**
 * Build a FieldRule with a multi-param presence conditional.
 *
 * @param  list<string>  $params
 */
function fieldWithMultiParamPresenceRule(string $ruleName, array $params): FieldRule
{
    $field = FluentRule::field();

    return match ($ruleName) {
        'required_with' => $field->requiredWith(...$params),
        'required_without' => $field->requiredWithout(...$params),
        'required_with_all' => $field->requiredWithAll(...$params),
        'required_without_all' => $field->requiredWithoutAll(...$params),
        default => throw new LogicException('Unknown rule ' . $ruleName),
    };
}

it('phase 3: multi-param parity matches native Laravel across full rule × shape grid', function (): void {
    // Two dependent params: a, b. Nine shapes exercising present / absent /
    // empty-variants for each combination plus the target postcode presence.
    $shapes = [
        ['postcode' => 'X', 'a' => 'A', 'b' => 'B'],  // both sibling present, target present
        ['a' => 'A', 'b' => 'B'],                      // both sibling present, target absent
        ['a' => 'A'],                                   // one sibling present, target absent
        ['b' => 'B'],                                   // one sibling present, target absent
        [],                                             // none present
        ['postcode' => 'X'],                            // only target present
        ['a' => '', 'b' => 'B'],                        // one sibling empty-string
        ['a' => null, 'b' => 'B'],                      // one sibling null
        ['a' => '   ', 'b' => 'B'],                     // one sibling whitespace-only
    ];

    $ruleNames = ['required_with', 'required_without', 'required_with_all', 'required_without_all'];

    foreach ($ruleNames as $rule) {
        foreach ($shapes as $shape) {
            $native = validator($shape, ['postcode' => $rule . ':a,b|string'])->fails();

            $field = fieldWithMultiParamPresenceRule($rule, ['a', 'b'])->rule('string');
            $fluent = RuleSet::from(['items.*.postcode' => $field])
                ->check(['items' => [$shape]])
                ->fails();

            expect($fluent)->toBe($native, 'rule=' . $rule . ' shape=' . json_encode($shape));
        }
    }
});

it('phase 3: required_with_all with 3 params — all present activates rule', function (): void {
    $field = fieldWithMultiParamPresenceRule('required_with_all', ['a', 'b', 'c'])->rule('string');
    $ruleSet = RuleSet::from(['items.*.postcode' => $field]);

    // All three present → rule active → target absent fails.
    $errors = $ruleSet->check(['items' => [['a' => 1, 'b' => 2, 'c' => 3]]])->errors()->toArray();
    expect($errors)->toHaveKey('items.0.postcode');

    // Two of three present → rule inactive → target absent passes.
    $errors = $ruleSet->check(['items' => [['a' => 1, 'b' => 2]]])->errors()->toArray();
    expect($errors)->toBeEmpty();
});

it('phase 3: required_without_all with 3 params — all absent activates rule', function (): void {
    $field = fieldWithMultiParamPresenceRule('required_without_all', ['a', 'b', 'c'])->rule('string');
    $ruleSet = RuleSet::from(['items.*.postcode' => $field]);

    // All three absent → rule active → target absent fails.
    $errors = $ruleSet->check(['items' => [[]]])->errors()->toArray();
    expect($errors)->toHaveKey('items.0.postcode');

    // One of three present → rule inactive → target absent passes.
    $errors = $ruleSet->check(['items' => [['b' => 'something']]])->errors()->toArray();
    expect($errors)->toBeEmpty();
});

it('phase 3: multi-param with nested dotted sibling paths', function (): void {
    $field = fieldWithMultiParamPresenceRule('required_with', ['profile.first_name', 'profile.last_name'])->rule('string');
    $ruleSet = RuleSet::from(['items.*.postcode' => $field]);

    // Nested first_name present → ANY present → rule active → target absent fails.
    $errors = $ruleSet->check(['items' => [['profile' => ['first_name' => 'Jan']]]])->errors()->toArray();
    expect($errors)->toHaveKey('items.0.postcode');

    // Both nested absent → rule inactive → target absent passes.
    $errors = $ruleSet->check(['items' => [['profile' => []]]])->errors()->toArray();
    expect($errors)->toBeEmpty();
});

it('phase 3: multi-param translator override still preserves original rule', function (): void {
    Lang::addLines([
        'validation.custom.items.*.postcode.required_with' => 'Custom multi',
    ], 'en');

    $field = fieldWithMultiParamPresenceRule('required_with', ['a', 'b'])->rule('string');
    $errors = RuleSet::from(['items.*.postcode' => $field])
        ->check(['items' => [['a' => 'A']]])
        ->errors()
        ->toArray();

    expect($errors)->toHaveKey('items.0.postcode');
    $msg = $errors['items.0.postcode'][0];
    // Rule preserved (not rewritten to `required`); native message shape contains `required_with`.
    expect($msg)->toContain('required_with');
});

// ---------- Codex phase-2-4 review: str_getcsv parity -----------------------

/**
 * Drive a raw pipe-string rule through the wildcard item reducer and return
 * whether the reducer's verdict matches native Laravel for the same rule.
 * Uses the raw-rule form (not FluentRule) so exotic parameter shapes reach
 * the parser verbatim.
 *
 * @param  array<int, array<string, mixed>>  $items
 * @return array{native: array<int, bool>, fluent: array<int, bool>}
 */
function parityForRawPresenceRule(string $ruleString, array $items): array
{
    $nativeFails = array_map(
        static fn (array $item): bool => validator($item, ['postcode' => $ruleString])->fails(),
        $items,
    );

    $fluentFails = array_map(
        static function (array $item) use ($ruleString): bool {
            $ruleSet = RuleSet::from([
                'items.*.postcode' => $ruleString,
            ]);

            return $ruleSet->check(['items' => [$item]])->fails();
        },
        $items,
    );

    return ['native' => $nativeFails, 'fluent' => $fluentFails];
}

it('parity: empty-param presence rule (required_with_all:) matches Laravel on non-empty items', function (): void {
    // str_getcsv('') returns [null]; native Laravel's Arr::get resolves the
    // null key to the full item. On any non-empty item that counts as present,
    // so required_with_all: activates.
    $items = [
        ['postcode' => 'X'],                // non-empty item, target present → active but satisfied
        ['foo' => 'bar'],                   // non-empty item without target → active + target absent → fail
        [],                                  // empty item — the "field" resolves to [] which is absent → inactive
    ];

    $result = parityForRawPresenceRule('required_with_all:', $items);
    expect($result['fluent'])->toBe($result['native']);
});

it('parity: leading-comma param (required_with:,birthdate) matches Laravel', function (): void {
    $items = [
        ['postcode' => 'X', 'birthdate' => '1990-01-01'],   // birthdate present → rule active
        ['birthdate' => '1990-01-01'],                      // target absent, rule active → fail
        ['postcode' => 'X'],                                 // birthdate absent, but null slot matches full item → present
        [],                                                  // fully empty item
    ];

    $result = parityForRawPresenceRule('required_with:,birthdate', $items);
    expect($result['fluent'])->toBe($result['native']);
});

it('parity: trailing-comma param (required_without:birthdate,) matches Laravel', function (): void {
    $items = [
        ['postcode' => 'X', 'birthdate' => '1990-01-01'],
        ['birthdate' => '1990-01-01'],
        ['postcode' => 'X'],
        [],
    ];

    $result = parityForRawPresenceRule('required_without:birthdate,', $items);
    expect($result['fluent'])->toBe($result['native']);
});

it('parity: CSV-quoted params (required_with:"a,b",c) matches Laravel', function (): void {
    // str_getcsv treats "a,b" as a single literal field name containing a
    // comma — pathological, but Laravel's parser handles it that way, and
    // we must match rather than silently reinterpret.
    $items = [
        ['postcode' => 'X', 'a,b' => 1, 'c' => 1],
        ['a,b' => 1],
        ['c' => 1],
        [],
    ];

    $result = parityForRawPresenceRule('required_with:"a,b",c', $items);
    expect($result['fluent'])->toBe($result['native']);
});

// ---------- No wildcard: top-level path is unaffected (native Laravel) ------

// ---------- Codex review fixes ----------------------------------------------

it('required_without resolves nested sibling paths via data_get (profile.birthdate)', function (): void {
    $ruleSet = RuleSet::from([
        'addresses.*.postcode' => FluentRule::field()->requiredWithout('profile.birthdate')->rule('string'),
    ]);

    // Nested sibling PRESENT — rule should deactivate, postcode absence is fine.
    $errors = $ruleSet->check(['addresses' => [['profile' => ['birthdate' => '1990-01-01']]]])->errors()->toArray();
    expect($errors)->toBeEmpty();

    // Nested sibling ABSENT — rule active, postcode missing → fail.
    $errors = $ruleSet->check(['addresses' => [['profile' => []]]])->errors()->toArray();
    expect($errors)->toHaveKey('addresses.0.postcode');

    // Nested sibling NULL at leaf → absent per Laravel semantics, rule active.
    $errors = $ruleSet->check(['addresses' => [['profile' => ['birthdate' => null]]]])->errors()->toArray();
    expect($errors)->toHaveKey('addresses.0.postcode');
});

it('required_without treats whitespace-only sibling as absent (matches Laravel trim semantics)', function (): void {
    // Sibling = '   ' → Laravel sees absent → rule activates → postcode missing fails.
    $errors = runPresenceItems([['birthdate' => '   ']]);
    expect($errors)->toHaveKey('addresses.0.postcode');
});

it('required_without treats empty Countable sibling as absent', function (): void {
    // Sibling = empty Countable → rule activates.
    $emptyCountable = new class implements Countable {
        public function count(): int
        {
            return 0;
        }
    };

    $errors = runPresenceItems([['birthdate' => $emptyCountable]]);
    expect($errors)->toHaveKey('addresses.0.postcode');
});

it('required_without honors wildcard-keyed translator override (validation.custom.addresses.*.postcode.required_without)', function (): void {
    Lang::addLines([
        'validation.custom.addresses.*.postcode.required_without' => 'Postcode bij adres verplicht',
    ], 'en');

    $ruleSet = RuleSet::from([
        'addresses.*.postcode' => FluentRule::field()->requiredWithout('birthdate')->rule('string'),
    ]);
    $errors = $ruleSet->check(['addresses' => [[]]])->errors()->toArray();

    // Rule must be preserved (not rewritten to plain `required`). Proof:
    // the native Laravel validation message key for the unrewritten rule
    // contains `required_without`, while a rewrite would say `required`.
    expect($errors)->toHaveKey('addresses.0.postcode');
    $msg = $errors['addresses.0.postcode'][0];
    expect($msg)->toContain('required_without');
});

// ----------------------------------------------------------------------------

it('top-level required_without: delegated to Laravel (no pre-eval), behavior matches native', function (): void {
    $ruleSet = RuleSet::from([
        'postcode' => FluentRule::field()->requiredWithout('birthdate')->rule('string'),
    ]);

    $passingShape = ['postcode' => '1234AB'];
    $failingShape = [];

    expect($ruleSet->check($passingShape)->passes())->toBeTrue()
        ->and($ruleSet->check($failingShape)->passes())->toBeFalse();
});
