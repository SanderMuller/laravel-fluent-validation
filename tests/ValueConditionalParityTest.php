<?php declare(strict_types=1);

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\Rules\RequiredIf;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\RuleSet;

// =========================================================================
// Phase 1 (spec: internal/specs/value-and-prohibited-conditionals.md)
//
// Pre-evaluation parity grid for the four value-conditional rules:
// required_if / required_unless / prohibited_if / prohibited_unless.
//
// Each grid row asserts the fluent verdict matches native Laravel's.
// The wildcard-item code path engages because rules are registered under
// `addresses.*.postcode`.
// =========================================================================

/**
 * @param  array<int, array<string, mixed>>  $items
 * @param  array<string, string>             $messages
 * @return array<string, array<int, string>>
 */
function runValueItems(Closure $ruleBuilder, array $items, array $messages = []): array
{
    /** @var array<string, array<int, string>> */
    return RuleSet::from([
        'addresses.*.postcode' => $ruleBuilder(),
    ])->check(['addresses' => $items], $messages)->errors()->toArray();
}

/**
 * Side-by-side parity assertion: native Laravel vs fluent wildcard-item
 * verdict must agree on pass/fail for every shape.
 *
 * @param  list<array<string, mixed>>  $shapes
 * @param  array<string, string>       $flatExtraRules
 */
function assertValueParity(string $flatRule, Closure $ruleBuilder, array $shapes, array $flatExtraRules = []): void
{
    $nativeRules = ['postcode' => $flatRule] + $flatExtraRules;

    foreach ($shapes as $shape) {
        $native = validator($shape, $nativeRules)->fails();
        $fluent = RuleSet::from(['addresses.*.postcode' => $ruleBuilder()])
            ->check(['addresses' => [$shape]])
            ->fails();

        expect($fluent)->toBe($native, 'shape: ' . json_encode($shape) . ' rule: ' . $flatRule);
    }
}

// =========================================================================
// required_if — activation when dep present AND value matches
// =========================================================================

it('required_if: active → postcode present passes', function (): void {
    $errors = runValueItems(
        static fn () => FluentRule::field()->requiredIf('flag', 'admin')->rule('string'),
        [['flag' => 'admin', 'postcode' => '1234AB']],
    );
    expect($errors)->toBeEmpty();
});

it('required_if: active → postcode missing fails', function (): void {
    $errors = runValueItems(
        static fn () => FluentRule::field()->requiredIf('flag', 'admin')->rule('string'),
        [['flag' => 'admin']],
    );
    expect($errors)->toHaveKey('addresses.0.postcode');
});

it('required_if: inactive (value mismatch) → postcode missing passes', function (): void {
    $errors = runValueItems(
        static fn () => FluentRule::field()->requiredIf('flag', 'admin')->rule('string'),
        [['flag' => 'user']],
    );
    expect($errors)->toBeEmpty();
});

it('required_if: dep missing → Arr::has short-circuit → inactive', function (): void {
    $errors = runValueItems(
        static fn () => FluentRule::field()->requiredIf('flag', 'admin')->rule('string'),
        [[]],
    );
    expect($errors)->toBeEmpty();
});

it('required_if: verdicts match native Laravel across grid', function (): void {
    assertValueParity(
        'required_if:flag,admin|string',
        static fn () => FluentRule::field()->requiredIf('flag', 'admin')->rule('string'),
        [
            ['flag' => 'admin', 'postcode' => '1234AB'],
            ['flag' => 'admin'],
            ['flag' => 'user'],
            ['flag' => 'user', 'postcode' => 'X'],
            [],
            ['postcode' => 'X'],
            ['flag' => null],
            ['flag' => null, 'postcode' => 'X'],
            ['flag' => 'admin', 'postcode' => ''],
            ['flag' => 'admin', 'postcode' => null],
        ],
    );
});

// =========================================================================
// required_unless — activation when dep is NOT in values
// =========================================================================

it('required_unless: dep mismatch → rule active → required', function (): void {
    $errors = runValueItems(
        static fn () => FluentRule::field()->requiredUnless('flag', 'admin')->rule('string'),
        [['flag' => 'user']],
    );
    expect($errors)->toHaveKey('addresses.0.postcode');
});

it('required_unless: dep match → rule inactive → postcode may be absent', function (): void {
    $errors = runValueItems(
        static fn () => FluentRule::field()->requiredUnless('flag', 'admin')->rule('string'),
        [['flag' => 'admin']],
    );
    expect($errors)->toBeEmpty();
});

it('required_unless: dep missing + "null" value → null-conversion makes rule inactive', function (): void {
    $errors = runValueItems(
        static fn () => FluentRule::field()->requiredUnless('flag', 'null')->rule('string'),
        [[]],
    );
    expect($errors)->toBeEmpty();
});

it('required_unless: verdicts match native Laravel across grid', function (): void {
    assertValueParity(
        'required_unless:flag,admin|string',
        static fn () => FluentRule::field()->requiredUnless('flag', 'admin')->rule('string'),
        [
            ['flag' => 'admin'],
            ['flag' => 'admin', 'postcode' => '1234AB'],
            ['flag' => 'user'],
            ['flag' => 'user', 'postcode' => 'X'],
            [],
            ['postcode' => 'X'],
            ['flag' => null],
            ['flag' => null, 'postcode' => 'X'],
        ],
    );
});

it('required_unless: "null" literal → null-conversion parity', function (): void {
    assertValueParity(
        'required_unless:flag,null|string',
        static fn () => FluentRule::field()->requiredUnless('flag', 'null')->rule('string'),
        [
            [],
            ['flag' => null],
            ['flag' => 'something'],
            ['flag' => 'something', 'postcode' => 'X'],
        ],
    );
});

// =========================================================================
// prohibited_if — activation when dep present AND value matches
// =========================================================================

it('prohibited_if: active → postcode present fails', function (): void {
    $errors = runValueItems(
        static fn () => FluentRule::field()->prohibitedIf('flag', 'admin'),
        [['flag' => 'admin', 'postcode' => '1234AB']],
    );
    expect($errors)->toHaveKey('addresses.0.postcode');
});

it('prohibited_if: active → postcode absent passes', function (): void {
    $errors = runValueItems(
        static fn () => FluentRule::field()->prohibitedIf('flag', 'admin'),
        [['flag' => 'admin']],
    );
    expect($errors)->toBeEmpty();
});

it('prohibited_if: inactive → postcode may be present', function (): void {
    $errors = runValueItems(
        static fn () => FluentRule::field()->prohibitedIf('flag', 'admin'),
        [['flag' => 'user', 'postcode' => '1234AB']],
    );
    expect($errors)->toBeEmpty();
});

it('prohibited_if: dep missing + "null" value → null-conversion makes rule active', function (): void {
    $errors = runValueItems(
        static fn () => FluentRule::field()->prohibitedIf('flag', 'null'),
        [['postcode' => '1234AB']],
    );
    expect($errors)->toHaveKey('addresses.0.postcode');
});

it('prohibited_if: verdicts match native Laravel across grid', function (): void {
    assertValueParity(
        'prohibited_if:flag,admin',
        static fn () => FluentRule::field()->prohibitedIf('flag', 'admin'),
        [
            ['flag' => 'admin', 'postcode' => '1234AB'],
            ['flag' => 'admin'],
            ['flag' => 'user', 'postcode' => '1234AB'],
            ['flag' => 'user'],
            [],
            ['postcode' => 'X'],
            ['flag' => null, 'postcode' => 'X'],
        ],
    );
});

// =========================================================================
// prohibited_unless — activation when dep is NOT in values
// =========================================================================

it('prohibited_unless: dep mismatch → prohibited active → postcode fails', function (): void {
    $errors = runValueItems(
        static fn () => FluentRule::field()->prohibitedUnless('flag', 'admin'),
        [['flag' => 'user', 'postcode' => '1234AB']],
    );
    expect($errors)->toHaveKey('addresses.0.postcode');
});

it('prohibited_unless: dep match → inactive → postcode allowed', function (): void {
    $errors = runValueItems(
        static fn () => FluentRule::field()->prohibitedUnless('flag', 'admin'),
        [['flag' => 'admin', 'postcode' => '1234AB']],
    );
    expect($errors)->toBeEmpty();
});

it('prohibited_unless: verdicts match native Laravel across grid', function (): void {
    assertValueParity(
        'prohibited_unless:flag,admin',
        static fn () => FluentRule::field()->prohibitedUnless('flag', 'admin'),
        [
            ['flag' => 'admin', 'postcode' => '1234AB'],
            ['flag' => 'admin'],
            ['flag' => 'user', 'postcode' => '1234AB'],
            ['flag' => 'user'],
            [],
            ['postcode' => 'X'],
            ['flag' => null, 'postcode' => 'X'],
        ],
    );
});

// =========================================================================
// Nested dependent path (data_get semantics)
// =========================================================================

it('required_if: nested dep path resolves via data_get', function (): void {
    $errors = runValueItems(
        static fn () => FluentRule::field()->requiredIf('profile.role', 'admin')->rule('string'),
        [['profile' => ['role' => 'admin']]],
    );
    expect($errors)->toHaveKey('addresses.0.postcode');
});

it('required_if: nested dep path — verdicts match native', function (): void {
    assertValueParity(
        'required_if:profile.role,admin|string',
        static fn () => FluentRule::field()->requiredIf('profile.role', 'admin')->rule('string'),
        [
            ['profile' => ['role' => 'admin']],
            ['profile' => ['role' => 'admin'], 'postcode' => 'X'],
            ['profile' => ['role' => 'user']],
            ['profile' => ['role' => 'user'], 'postcode' => 'X'],
            ['profile' => []],
            [],
        ],
    );
});

// =========================================================================
// Loose vs strict in_array — numeric-string match
// =========================================================================

it('required_if: numeric-string "1" matches int 1 via loose in_array', function (): void {
    assertValueParity(
        'required_if:flag,1|string',
        static fn () => FluentRule::field()->requiredIf('flag', '1')->rule('string'),
        [
            ['flag' => 1],
            ['flag' => 1, 'postcode' => 'X'],
            ['flag' => '1'],
            ['flag' => '1', 'postcode' => 'X'],
            ['flag' => 2, 'postcode' => 'X'],
            ['flag' => 2],
        ],
    );
});

// =========================================================================
// Boolean conversion — is_bool($other) triggers convertValuesToBoolean
// =========================================================================

it('required_if: "true" value + actual bool dep → bool conversion (strict match)', function (): void {
    assertValueParity(
        'required_if:flag,true|string',
        static fn () => FluentRule::field()->requiredIf('flag', 'true')->rule('string'),
        [
            ['flag' => true],
            ['flag' => true, 'postcode' => 'X'],
            ['flag' => false],
            ['flag' => false, 'postcode' => 'X'],
        ],
    );
});

it('required_if: shouldConvertToBoolean fires when dep has boolean rule', function (): void {
    // Dep has boolean rule — values "true"/"false" convert to bools so loose
    // match against string "1" / "0" / "" behaves as Laravel expects.
    $shapes = [
        ['flag' => '1', 'postcode' => 'X'],
        ['flag' => '0', 'postcode' => 'X'],
        ['flag' => '1'],
        ['flag' => '0'],
    ];

    foreach ($shapes as $shape) {
        $native = validator($shape, [
            'flag' => 'boolean',
            'postcode' => 'required_if:flag,true|string',
        ])->fails();

        $fluent = RuleSet::from([
            'addresses.*.flag' => FluentRule::boolean(),
            'addresses.*.postcode' => FluentRule::field()->requiredIf('flag', 'true')->rule('string'),
        ])->check(['addresses' => [$shape]])->fails();

        expect($fluent)->toBe($native, 'shape: ' . json_encode($shape));
    }
});

// =========================================================================
// prohibited_unless: "null" literal — null-conversion parity
// =========================================================================

// =========================================================================
// Per-item validator cache must NOT reuse a validator across items whose
// reduced slow rules differ. Regression: when `required_if|<slow rule>`
// reduces to `required|<slow>` for one item and just `<slow>` for another,
// both items share the same field set — the cache must distinguish them
// on content, not just field names.
// =========================================================================

it('validator cache keys on rule content, not just field names, for value conditionals with slow rules', function (): void {
    // Custom rule object that just fails — stands in for `exists`/`unique`
    // (real DB-bound slow rules that can't be fast-checked).
    $alwaysFail = new class implements ValidationRule {
        public function validate(string $attribute, mixed $value, Closure $fail): void
        {
            $fail(':attribute failed');
        }
    };

    // `required_if:role,admin` + the custom rule in the SAME chain.
    // Admin item: reducer rewrites to `required|<rule>` — postcode missing
    //             should fail on required, which (hypothetically) caches
    //             the validator with a required rule.
    // User item:  reducer drops required_if → `<rule>` only — postcode still
    //             not provided; without required, rule should not trigger
    //             (postcode is missing, not present-and-failing).
    // Cache-key bug would cause the user item to reuse admin's validator
    // and incorrectly fire `required`.
    $ruleSet = RuleSet::from([
        'users.*.postcode' => FluentRule::field()
            ->requiredIf('role', 'admin')
            ->rule($alwaysFail),
    ]);

    $data = ['users' => [
        ['role' => 'admin'],                     // postcode missing → should fail on required
        ['role' => 'user'],                      // postcode missing → should pass (rule dropped)
    ]];

    $errors = $ruleSet->check($data)->errors()->toArray();

    expect($errors)->toHaveKey('users.0.postcode')->not->toHaveKey('users.1.postcode');
});

it('prohibited_unless: "null" literal → null-conversion parity', function (): void {
    assertValueParity(
        'prohibited_unless:flag,null',
        static fn () => FluentRule::field()->prohibitedUnless('flag', 'null'),
        [
            [],
            ['flag' => null],
            ['flag' => null, 'postcode' => 'X'],
            ['flag' => 'admin', 'postcode' => 'X'],
            ['postcode' => 'X'],
        ],
    );
});

// =========================================================================
// Message preservation — active+override path keeps original rule name
// =========================================================================

it('required_if: inline $messages[{field}.required_if] keeps rule intact', function (): void {
    $errors = runValueItems(
        static fn () => FluentRule::field()->requiredIf('flag', 'admin')->rule('string'),
        [['flag' => 'admin']],
        ['postcode.required_if' => 'Vul postcode in'],
    );
    expect($errors)->toHaveKey('addresses.0.postcode')
        ->and($errors['addresses.0.postcode'])->toContain('Vul postcode in');
});

it('required_if: translator validation.custom.{field}.required_if override keeps rule intact', function (): void {
    Lang::addLines([
        'validation.custom.postcode.required_if' => 'Postcode verplicht wanneer admin',
    ], 'en');

    $errors = runValueItems(
        static fn () => FluentRule::field()->requiredIf('flag', 'admin')->rule('string'),
        [['flag' => 'admin']],
    );
    expect($errors)->toHaveKey('addresses.0.postcode')
        ->and($errors['addresses.0.postcode'])->toContain('Postcode verplicht wanneer admin');
});

it('required_if: translator wildcard-keyed override keeps rule intact', function (): void {
    // Translator wildcard keys resolve via Validator::getCustomMessageFromTranslator
    // with Str::is() matching — the reducer's hasCustomMessage must detect
    // these so the rule name is preserved for the translator to fire.
    Lang::addLines([
        'validation.custom.addresses.*.postcode.required_if' => 'Postcode per adres verplicht',
    ], 'en');

    $ruleSet = RuleSet::from([
        'addresses.*.postcode' => FluentRule::field()->requiredIf('flag', 'admin')->rule('string'),
    ]);
    $errors = $ruleSet->check(['addresses' => [['flag' => 'admin']]])->errors()->toArray();

    expect($errors)->toHaveKey('addresses.0.postcode');
    $msg = $errors['addresses.0.postcode'][0];
    // Rule preserved (not rewritten to bare `required`) so translator fires.
    expect($msg)->toContain('required_if');
});

it('prohibited_if: inline override keeps prohibited_if rule intact', function (): void {
    $errors = runValueItems(
        static fn () => FluentRule::field()->prohibitedIf('flag', 'admin'),
        [['flag' => 'admin', 'postcode' => 'X']],
        ['postcode.prohibited_if' => 'Niet toegestaan voor admin'],
    );
    expect($errors)->toHaveKey('addresses.0.postcode')
        ->and($errors['addresses.0.postcode'])->toContain('Niet toegestaan voor admin');
});

it('rewrite path uses generic required/prohibited message (rule name not leaked)', function (): void {
    $requiredErrors = runValueItems(
        static fn () => FluentRule::field()->requiredIf('flag', 'admin')->rule('string'),
        [['flag' => 'admin']],
    );
    expect($requiredErrors['addresses.0.postcode'][0])
        ->toContain('required')
        ->not->toContain('required_if');

    $prohibitedErrors = runValueItems(
        static fn () => FluentRule::field()->prohibitedIf('flag', 'admin'),
        [['flag' => 'admin', 'postcode' => 'X']],
    );
    expect($prohibitedErrors['addresses.0.postcode'][0])
        ->toContain('prohibited')
        ->not->toContain('prohibited_if');
});

// =========================================================================
// Closure / bool form → RequiredIf / ProhibitedIf objects flow unmodified
// =========================================================================

it('closure-form requiredIf (RequiredIf object) flows through reducer unmodified', function (): void {
    // Closure form returns a `RequiredIf` object, not a pipe-string. The reducer
    // must not attempt to parse/rewrite it; it should reach Laravel intact.
    $ruleSet = RuleSet::from([
        'addresses.*.postcode' => FluentRule::field()
            ->requiredIf(static fn (): bool => true)
            ->rule('string'),
    ]);

    $errors = $ruleSet->check(['addresses' => [[]]])->errors()->toArray();
    expect($errors)->toHaveKey('addresses.0.postcode');

    $passErrors = RuleSet::from([
        'addresses.*.postcode' => FluentRule::field()
            ->requiredIf(static fn (): bool => false)
            ->rule('string'),
    ])->check(['addresses' => [[]]])->errors()->toArray();
    expect($passErrors)->toBeEmpty();
});

it('bool-form requiredIf passes RequiredIf object through reducer', function (): void {
    $ruleSet = RuleSet::from([
        'addresses.*.postcode' => FluentRule::field()->requiredIf(true)->rule('string'),
    ]);

    $errors = $ruleSet->check(['addresses' => [[]]])->errors()->toArray();
    expect($errors)->toHaveKey('addresses.0.postcode');
});

it('raw RequiredIf object mixed with rule list flows through reducer', function (): void {
    // Belt-and-braces: the reducer's list-shape branch must not strip non-string
    // entries. Pass a RequiredIf explicitly alongside a plain string rule.
    $ruleSet = RuleSet::from([
        'addresses.*.postcode' => FluentRule::field()
            ->rule(new RequiredIf(static fn (): bool => true))
            ->rule('string'),
    ]);

    $errors = $ruleSet->check(['addresses' => [[]]])->errors()->toArray();
    expect($errors)->toHaveKey('addresses.0.postcode');
});
