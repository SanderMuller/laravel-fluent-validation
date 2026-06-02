<?php declare(strict_types=1);

use Illuminate\Validation\Rule;
use SanderMuller\FluentValidation\Contracts\FluentRuleContract;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\RuleSet;

// =========================================================================
// Regression matrix: a conditional-required modifier combined with nullable()
// must enforce the requirement when its condition is active, on EVERY path,
// exactly like native Laravel.
//
// The bug (SelfValidates::isNullable): a null/missing value short-circuited
// validation whenever `nullable` was present and the literal string
// 'required' was absent from the constraints. Conditional-required modifiers
// never produce that literal string — they live as `required_*` constraint
// strings (field-value form) or as RequiredIf/RequiredUnless objects
// (bool/closure form) — so the requirement was silently dropped.
//
// Coverage is built as a parity harness rather than hand-written expectations:
// for every case we derive the ground truth from native Laravel string rules
// and assert that BOTH FluentRule paths agree with it:
//
//   * self-validation  — FluentRule used directly as a ValidationRule
//                        (e.g. inline `$request->validate([...])`). The bug site.
//   * compiled         — RuleSet::from(...)->check(...), the full HasFluentRules
//                        pipeline (fast-check + native fallback).
//
// Type is fixed to string() so the matrix isolates presence semantics; a
// separate section below confirms type rules still run alongside the
// conditional. Value states cover absent / null / empty-string / valid.
// =========================================================================

const FIELD_ABSENT = '__field_absent__';

/**
 * Every conditional-required family, paired with its native Laravel
 * equivalent and the contexts that make the condition active / inactive.
 *
 * Each family entry:
 *   [0] label
 *   [1] Closure(FluentRuleContract): FluentRuleContract — applies the modifier
 *   [2] string|object — native Laravel equivalent rule (field-value string or
 *       a RequiredIf object for the bool/closure forms)
 *   [3] list<array{0:string,1:array<string,mixed>}> — [contextLabel, contextData]
 *       pairs; the field requirement is active for every listed context whose
 *       label starts with "active"
 *
 * @return list<array{0:string,1:Closure,2:string|object,3:list<array{0:string,1:array<string,mixed>}>}>
 */
function conditionalRequiredFamilies(): array
{
    return [
        // ---- object form: bool ------------------------------------------
        ['requiredIf(true)', static fn (FluentRuleContract $r): FluentRuleContract => $r->requiredIf(true),
            Rule::requiredIf(true), [['active', []]]],
        ['requiredIf(false)', static fn (FluentRuleContract $r): FluentRuleContract => $r->requiredIf(false),
            Rule::requiredIf(false), [['inactive', []]]],

        // ---- object form: closure (lazily evaluated) --------------------
        ['requiredIf(fn:true)', static fn (FluentRuleContract $r): FluentRuleContract => $r->requiredIf(fn (): bool => true),
            Rule::requiredIf(fn (): bool => true), [['active', []]]],
        ['requiredIf(fn:false)', static fn (FluentRuleContract $r): FluentRuleContract => $r->requiredIf(fn (): bool => false),
            Rule::requiredIf(fn (): bool => false), [['inactive', []]]],

        // ---- requiredUnless object form (package inverts to RequiredIf) -
        ['requiredUnless(true)', static fn (FluentRuleContract $r): FluentRuleContract => $r->requiredUnless(true),
            Rule::requiredIf(false), [['inactive', []]]],
        ['requiredUnless(false)', static fn (FluentRuleContract $r): FluentRuleContract => $r->requiredUnless(false),
            Rule::requiredIf(true), [['active', []]]],

        // ---- field-value string forms -----------------------------------
        ['requiredIf(role,admin)', static fn (FluentRuleContract $r): FluentRuleContract => $r->requiredIf('role', 'admin'),
            'required_if:role,admin', [['active', ['role' => 'admin']], ['inactive', ['role' => 'guest']]]],
        ['requiredUnless(role,admin)', static fn (FluentRuleContract $r): FluentRuleContract => $r->requiredUnless('role', 'admin'),
            'required_unless:role,admin', [['active', ['role' => 'guest']], ['inactive', ['role' => 'admin']]]],
        ['requiredWith(other)', static fn (FluentRuleContract $r): FluentRuleContract => $r->requiredWith('other'),
            'required_with:other', [['active', ['other' => 'y']], ['inactive', []]]],
        ['requiredWithAll(a,b)', static fn (FluentRuleContract $r): FluentRuleContract => $r->requiredWithAll('a', 'b'),
            'required_with_all:a,b', [['active', ['a' => 1, 'b' => 2]], ['inactive', ['a' => 1]]]],
        ['requiredWithout(other)', static fn (FluentRuleContract $r): FluentRuleContract => $r->requiredWithout('other'),
            'required_without:other', [['active', []], ['inactive', ['other' => 'y']]]],
        ['requiredWithoutAll(a,b)', static fn (FluentRuleContract $r): FluentRuleContract => $r->requiredWithoutAll('a', 'b'),
            'required_without_all:a,b', [['active', []], ['inactive', ['a' => 1]]]],
        ['requiredIfAccepted(terms)', static fn (FluentRuleContract $r): FluentRuleContract => $r->requiredIfAccepted('terms'),
            'required_if_accepted:terms', [['active', ['terms' => 'yes']], ['inactive', ['terms' => 'no']]]],
        ['requiredIfDeclined(terms)', static fn (FluentRuleContract $r): FluentRuleContract => $r->requiredIfDeclined('terms'),
            'required_if_declined:terms', [['active', ['terms' => 'no']], ['inactive', ['terms' => 'yes']]]],
    ];
}

/**
 * Field value states exercised against every family/context.
 *
 * @return array<string, mixed>
 */
function conditionalFieldValueStates(): array
{
    return [
        'absent' => FIELD_ABSENT,
        'null' => null,
        'empty-string' => '',
        'valid' => 'x',
    ];
}

/**
 * Every non-required presence family that ALSO forces evaluation of a
 * null/absent value (so nullable must not short-circuit it), plus the
 * prohibited family which is deliberately NOT forced — parity-checked to prove
 * the short-circuit still yields native behaviour there. Same tuple shape as
 * conditionalRequiredFamilies().
 *
 * @return list<array{0:string,1:Closure,2:string|object,3:list<array{0:string,1:array<string,mixed>}>}>
 */
function presenceForcingFamilies(): array
{
    return [
        ['filled', static fn (FluentRuleContract $r): FluentRuleContract => $r->filled(),
            'filled', [['always', []]]],
        ['present', static fn (FluentRuleContract $r): FluentRuleContract => $r->present(),
            'present', [['always', []]]],
        ['presentIf(role,admin)', static fn (FluentRuleContract $r): FluentRuleContract => $r->presentIf('role', 'admin'),
            'present_if:role,admin', [['trigger', ['role' => 'admin']], ['no-trigger', ['role' => 'guest']]]],
        ['presentUnless(role,admin)', static fn (FluentRuleContract $r): FluentRuleContract => $r->presentUnless('role', 'admin'),
            'present_unless:role,admin', [['trigger', ['role' => 'guest']], ['no-trigger', ['role' => 'admin']]]],
        ['presentWith(other)', static fn (FluentRuleContract $r): FluentRuleContract => $r->presentWith('other'),
            'present_with:other', [['trigger', ['other' => 'y']], ['no-trigger', []]]],
        ['presentWithAll(a,b)', static fn (FluentRuleContract $r): FluentRuleContract => $r->presentWithAll('a', 'b'),
            'present_with_all:a,b', [['trigger', ['a' => 1, 'b' => 2]], ['no-trigger', ['a' => 1]]]],
        ['missing', static fn (FluentRuleContract $r): FluentRuleContract => $r->missing(),
            'missing', [['always', []]]],
        ['missingIf(role,admin)', static fn (FluentRuleContract $r): FluentRuleContract => $r->missingIf('role', 'admin'),
            'missing_if:role,admin', [['trigger', ['role' => 'admin']], ['no-trigger', ['role' => 'guest']]]],
        ['missingUnless(role,admin)', static fn (FluentRuleContract $r): FluentRuleContract => $r->missingUnless('role', 'admin'),
            'missing_unless:role,admin', [['trigger', ['role' => 'guest']], ['no-trigger', ['role' => 'admin']]]],
        ['missingWith(other)', static fn (FluentRuleContract $r): FluentRuleContract => $r->missingWith('other'),
            'missing_with:other', [['trigger', ['other' => 'y']], ['no-trigger', []]]],
        ['missingWithAll(a,b)', static fn (FluentRuleContract $r): FluentRuleContract => $r->missingWithAll('a', 'b'),
            'missing_with_all:a,b', [['trigger', ['a' => 1, 'b' => 2]], ['no-trigger', ['a' => 1]]]],

        // Prohibited family — excluded from the presence-forcing set on purpose:
        // it is satisfied by an empty/null value, so the nullable short-circuit
        // already matches native. These cases prove that exclusion is correct.
        ['prohibited', static fn (FluentRuleContract $r): FluentRuleContract => $r->prohibited(),
            'prohibited', [['always', []]]],
        ['prohibitedIf(true)', static fn (FluentRuleContract $r): FluentRuleContract => $r->prohibitedIf(true),
            Rule::prohibitedIf(true), [['active', []]]],
        ['prohibitedIf(false)', static fn (FluentRuleContract $r): FluentRuleContract => $r->prohibitedIf(false),
            Rule::prohibitedIf(false), [['inactive', []]]],
    ];
}

/**
 * Expand a family list into a flat, labelled case map.
 *
 * @param list<array{0:string,1:Closure,2:string|object,3:list<array{0:string,1:array<string,mixed>}>}> $families
 * @return array<string, array{native: string|object, nullable: bool, data: array<string, mixed>, modifier: Closure}>
 */
function expandNullableCases(array $families): array
{
    $cases = [];

    foreach ($families as [$familyLabel, $modifier, $native, $contexts]) {
        foreach ([true, false] as $withNullable) {
            $nullableLabel = $withNullable ? 'nullable' : 'no-nullable';

            foreach ($contexts as [$contextLabel, $contextData]) {
                foreach (conditionalFieldValueStates() as $valueLabel => $value) {
                    $data = $contextData;

                    if ($value !== FIELD_ABSENT) {
                        $data['field'] = $value;
                    }

                    $label = "{$familyLabel} | {$nullableLabel} | {$contextLabel} | {$valueLabel}";

                    $cases[$label] = [
                        'native' => $native,
                        'nullable' => $withNullable,
                        'data' => $data,
                        'modifier' => $modifier,
                    ];
                }
            }
        }
    }

    return $cases;
}

/**
 * Memoized so the matrix is built once rather than on every dataset iteration.
 * Safe to share: modifier closures and native rule objects are stateless and
 * re-applied/read per case.
 *
 * @return array<string, array{native: string|object, nullable: bool, data: array<string, mixed>, modifier: Closure}>
 */
function conditionalNullableCases(): array
{
    /** @var array<string, array{native: string|object, nullable: bool, data: array<string, mixed>, modifier: Closure}>|null $cache */
    static $cache = null;

    return $cache ??= expandNullableCases(conditionalRequiredFamilies());
}

/**
 * @return array<string, array{native: string|object, nullable: bool, data: array<string, mixed>, modifier: Closure}>
 */
function presenceNullableCases(): array
{
    /** @var array<string, array{native: string|object, nullable: bool, data: array<string, mixed>, modifier: Closure}>|null $cache */
    static $cache = null;

    return $cache ??= expandNullableCases(presenceForcingFamilies());
}

/** @param array<string, mixed> $data */
function nativeConditionalFails(string|object $native, bool $nullable, array $data): bool
{
    $rules = $nullable ? ['nullable', $native, 'string'] : [$native, 'string'];

    return makeValidator($data, ['field' => $rules])->fails();
}

/** @param Closure(FluentRuleContract): FluentRuleContract $modifier */
function buildConditionalRule(Closure $modifier, bool $nullable): FluentRuleContract
{
    $base = $nullable ? FluentRule::string()->nullable() : FluentRule::string();

    return $modifier($base);
}

/**
 * Assert a case behaves identically on both consumer paths — standalone
 * self-validation (the bug site) and the compiled HasFluentRules pipeline —
 * and that both match native Laravel. Each path gets a fresh rule instance.
 *
 * @param array{native: string|object, nullable: bool, data: array<string, mixed>, modifier: Closure} $case
 */
function assertNullableParityAcrossPaths(array $case): void
{
    $expectedFails = nativeConditionalFails($case['native'], $case['nullable'], $case['data']);

    expect(makeValidator($case['data'], ['field' => buildConditionalRule($case['modifier'], $case['nullable'])])->fails())
        ->toBe($expectedFails)
        ->and(RuleSet::from(['field' => buildConditionalRule($case['modifier'], $case['nullable'])])->check($case['data'])->fails())
        ->toBe($expectedFails);
}

dataset('conditional-nullable', fn (): array => array_keys(conditionalNullableCases()));

it('conditional-required + nullable matches native Laravel on self-validation and compiled paths', function (string $label): void {
    assertNullableParityAcrossPaths(conditionalNullableCases()[$label]);
})->with('conditional-nullable');

// Presence families (filled / present* / missing*) + the prohibited control.
// These share the isNullable() short-circuit site; nullable must not drop them.
dataset('presence-nullable', fn (): array => array_keys(presenceNullableCases()));

it('presence modifiers + nullable match native Laravel on self-validation and compiled paths', function (string $label): void {
    assertNullableParityAcrossPaths(presenceNullableCases()[$label]);
})->with('presence-nullable');

// =========================================================================
// Explicit headline expectations — documents the intended contract and guards
// against native Laravel itself drifting. Mirrors the original bug report.
// =========================================================================

it('requiredIf(true)->nullable() enforces the requirement', function (mixed $value, bool $present, bool $shouldFail): void {
    $data = $present ? ['email' => $value] : [];

    $validator = makeValidator($data, ['email' => FluentRule::email()->bail()->requiredIf(true)->nullable()]);

    expect($validator->fails())->toBe($shouldFail);
})->with([
    'missing' => [null, false, true],
    'null' => [null, true, true],
    'empty string' => ['', true, true],
    'invalid email' => ['nope', true, true],
    'valid email' => ['a@b.com', true, false],
]);

it('requiredIf(false)->nullable() stays optional', function (mixed $value, bool $present, bool $shouldFail): void {
    $data = $present ? ['email' => $value] : [];

    $validator = makeValidator($data, ['email' => FluentRule::email()->bail()->requiredIf(false)->nullable()]);

    expect($validator->fails())->toBe($shouldFail);
})->with([
    'missing' => [null, false, false],
    'null' => [null, true, false],
    'empty string' => ['', true, false],
    'invalid email still fails type' => ['nope', true, true],
    'valid email' => ['a@b.com', true, false],
]);

it('string-form requiredWith->nullable() enforces when the trigger is present', function (array $data, bool $shouldFail): void {
    /** @var array<string, mixed> $data */
    $validator = makeValidator($data, ['field' => FluentRule::string()->requiredWith('other')->nullable()]);

    expect($validator->fails())->toBe($shouldFail);
})->with([
    'trigger present, field missing' => [['other' => 'y'], true],
    'trigger present, field null' => [['other' => 'y', 'field' => null], true],
    'trigger present, field empty' => [['other' => 'y', 'field' => ''], true],
    'trigger present, field set' => [['other' => 'y', 'field' => 'x'], false],
    'trigger absent, field missing' => [[], false],
    'trigger absent, field null' => [['field' => null], false],
]);

it('string-form requiredIfAccepted->nullable() enforces when accepted', function (array $data, bool $shouldFail): void {
    /** @var array<string, mixed> $data */
    $validator = makeValidator($data, ['sig' => FluentRule::string()->requiredIfAccepted('terms')->nullable()]);

    expect($validator->fails())->toBe($shouldFail);
})->with([
    'accepted, sig missing' => [['terms' => 'yes'], true],
    'accepted, sig null' => [['terms' => 'yes', 'sig' => null], true],
    'accepted, sig set' => [['terms' => 'yes', 'sig' => 'signed'], false],
    'not accepted, sig missing' => [['terms' => 'no'], false],
    'not accepted, sig null' => [['terms' => 'no', 'sig' => null], false],
]);

// -------------------------------------------------------------------------
// Type enforcement still runs alongside an active conditional + nullable
// -------------------------------------------------------------------------

it('keeps type validation when an active conditional-required field is present', function (): void {
    $validator = makeValidator(
        ['role' => 'admin', 'field' => 'not-an-int'],
        ['field' => FluentRule::integer()->requiredIf('role', 'admin')->nullable()],
    );

    expect($validator->fails())->toBeTrue();
});

it('skips type validation on null when the conditional is inactive', function (): void {
    $validator = makeValidator(
        ['role' => 'guest', 'field' => null],
        ['field' => FluentRule::integer()->requiredIf('role', 'admin')->nullable()],
    );

    expect($validator->passes())->toBeTrue();
});

// =========================================================================
// Headline expectations for the non-required presence families. These are the
// combinations the required-family-only fix used to leave broken.
// =========================================================================

it('present()->nullable() requires the key but allows a null value', function (array $data, bool $shouldFail): void {
    /** @var array<string, mixed> $data */
    $validator = makeValidator($data, ['field' => FluentRule::string()->present()->nullable()]);

    expect($validator->fails())->toBe($shouldFail);
})->with([
    'key absent' => [[], true],
    'key present, null' => [['field' => null], false],
    'key present, value' => [['field' => 'x'], false],
]);

it('filled()->nullable() rejects a present-null value', function (array $data, bool $shouldFail): void {
    /** @var array<string, mixed> $data */
    $validator = makeValidator($data, ['field' => FluentRule::string()->filled()->nullable()]);

    expect($validator->fails())->toBe($shouldFail);
})->with([
    'key absent' => [[], false],
    'key present, null' => [['field' => null], true],
    'key present, empty' => [['field' => ''], true],
    'key present, value' => [['field' => 'x'], false],
]);

it('missing()->nullable() requires the key to be absent', function (array $data, bool $shouldFail): void {
    /** @var array<string, mixed> $data */
    $validator = makeValidator($data, ['field' => FluentRule::string()->missing()->nullable()]);

    expect($validator->fails())->toBe($shouldFail);
})->with([
    'key absent' => [[], false],
    'key present, null' => [['field' => null], true],
    'key present, value' => [['field' => 'x'], true],
]);

// Regression guard for the over-broad `required_` prefix: required_array_keys
// is NOT a presence requirement, so nullable must still short-circuit on null.
it('requiredArrayKeys()->nullable() is not treated as presence-forcing', function (array $data, bool $shouldFail): void {
    /** @var array<string, mixed> $data */
    $rule = FluentRule::array()->nullable()->requiredArrayKeys('name');

    $nativeFails = makeValidator($data, ['field' => ['nullable', 'array', 'required_array_keys:name']])->fails();

    expect($nativeFails)->toBe($shouldFail)
        ->and(makeValidator($data, ['field' => $rule])->fails())->toBe($shouldFail)
        ->and(RuleSet::from(['field' => $rule])->check($data)->fails())->toBe($shouldFail);
})->with([
    'null value' => [['field' => null], false],
    'missing field' => [[], false],
    'valid array' => [['field' => ['name' => 'x']], false],
    'array missing required key' => [['field' => ['other' => 'x']], true],
]);

// =========================================================================
// Nested shapes (each() / children()) under a null/absent nullable parent.
// The nullable short-circuit must NOT skip nested rules: standalone
// self-validation has to agree with both native Laravel (flat rules) and the
// compiled HasFluentRules path. Wildcard each() still passes on null (expands
// to nothing); fixed children() enforce their sub-rules.
// =========================================================================

/**
 * @return array<string, array{0: Closure(): FluentRuleContract, 1: array<string, mixed>, 2: array<string, mixed>}>
 */
function nestedNullableCases(): array
{
    $childrenRequired = static fn (): FluentRuleContract => FluentRule::array()->nullable()->children(['id' => FluentRule::integer()->required()]);
    $childrenRequiredNative = ['obj' => ['nullable', 'array'], 'obj.id' => ['integer', 'required']];

    $eachRequired = static fn (): FluentRuleContract => FluentRule::array()->nullable()->each(['id' => FluentRule::integer()->required()]);
    $eachRequiredNative = ['obj' => ['nullable', 'array'], 'obj.*.id' => ['integer', 'required']];

    return [
        'children required, parent null' => [$childrenRequired, $childrenRequiredNative, ['obj' => null]],
        'children required, parent absent' => [$childrenRequired, $childrenRequiredNative, []],
        'children required, parent valid' => [$childrenRequired, $childrenRequiredNative, ['obj' => ['id' => 1]]],
        'children required, parent missing key' => [$childrenRequired, $childrenRequiredNative, ['obj' => ['other' => 1]]],
        'children nullable child, parent null' => [
            static fn (): FluentRuleContract => FluentRule::array()->nullable()->children(['id' => FluentRule::integer()->nullable()]),
            ['obj' => ['nullable', 'array'], 'obj.id' => ['integer', 'nullable']],
            ['obj' => null],
        ],
        'present children required, parent null' => [
            static fn (): FluentRuleContract => FluentRule::array()->present()->nullable()->children(['id' => FluentRule::integer()->required()]),
            ['obj' => ['present', 'nullable', 'array'], 'obj.id' => ['integer', 'required']],
            ['obj' => null],
        ],
        'each required, parent null' => [$eachRequired, $eachRequiredNative, ['obj' => null]],
        'each required, parent empty array' => [$eachRequired, $eachRequiredNative, ['obj' => []]],
        'each required, parent valid' => [$eachRequired, $eachRequiredNative, ['obj' => [['id' => 1]]]],
        'each required, element missing key' => [$eachRequired, $eachRequiredNative, ['obj' => [['other' => 1]]]],
    ];
}

dataset('nested-nullable', fn (): array => array_keys(nestedNullableCases()));

it('nested children/each + nullable parent matches native Laravel and the compiled path', function (string $label): void {
    [$factory, $native, $data] = nestedNullableCases()[$label];

    $expectedFails = makeValidator($data, $native)->fails();

    expect(makeValidator($data, ['obj' => $factory()])->fails())->toBe($expectedFails)
        ->and(RuleSet::from(['obj' => $factory()])->check($data)->fails())->toBe($expectedFails);
})->with('nested-nullable');
