<?php declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as BaseValidator;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\RuleSet;

// =========================================================================
// RuleSet validates wildcard groups per item, so a field reference such as
// `items.*.other` must be rewritten to the item-relative `other`. Plain
// pipe-string rules must get the same rewrite as array rules and FluentRule
// objects; otherwise the reference resolves to nothing inside the item.
//
// The referenced field is named `other` on purpose: strtotime() accepts some
// bare names (`a`, `today`, ...) as dates, which is a separate issue.
// =========================================================================

it('resolves wildcard field references in pipe-string rules like native', function (string $rule, array $item): void {
    $data = ['items' => [$item]];
    $rules = ['items.*.other' => 'nullable', 'items.*.b' => $rule];

    $native = validator($data, $rules)->errors()->keys();
    $fluent = RuleSet::from($rules)->check($data)->errors()->keys();

    expect($fluent)->toBe($native);
})->with([
    'required_if active' => ['required_if:items.*.other,yes', ['other' => 'yes']],
    'required_with active' => ['required_with:items.*.other', ['other' => 'x']],
    'exclude_if active' => ['exclude_if:items.*.other,yes|required', ['other' => 'yes']],
    'prohibited_if active' => ['prohibited_if:items.*.other,yes', ['other' => 'yes', 'b' => 'x']],
    'accepted_if active' => ['accepted_if:items.*.other,yes', ['other' => 'yes', 'b' => 'no']],
    'different on equal values' => ['different:items.*.other', ['other' => 'x', 'b' => 'x']],
    'same on equal values' => ['same:items.*.other', ['other' => 'x', 'b' => 'x']],
    'after an earlier sibling date' => ['date|after:items.*.other', ['other' => '2025-06-01', 'b' => '2025-01-01']],
    'quoted reference' => ['same:"items.*.other"', ['other' => 'x', 'b' => 'x']],
    'space before a quoted reference' => ['same: "items.*.other"', ['other' => 'x', 'b' => 'x']],
    'space before a quoted conditional field' => ['required_if: "items.*.other",yes', ['other' => 'yes']],
    'before a later sibling date' => ['date|before:items.*.other', ['other' => '2025-01-01', 'b' => '2025-06-01']],
]);

it('keeps wildcard-like literal values in non-dependent rules like native', function (mixed $rule, mixed $nativeRule, string $value): void {
    $data = ['items' => [['b' => $value]]];

    $native = validator($data, ['items.*.b' => $nativeRule])->passes();
    $fluent = RuleSet::from(['items.*.b' => $rule])->check($data)->passes();

    expect($fluent)->toBe($native);
})->with([
    'string, literal value' => ['in:items.*.foo', 'in:items.*.foo', 'items.*.foo'],
    'string, stripped value' => ['in:items.*.foo', 'in:items.*.foo', 'foo'],
    'array, literal value' => [['in:items.*.foo'], ['in:items.*.foo'], 'items.*.foo'],
    'array, stripped value' => [['in:items.*.foo'], ['in:items.*.foo'], 'foo'],
    'FluentRule, literal value' => [FluentRule::string()->in(['items.*.foo']), ['string', 'in:"items.*.foo"'], 'items.*.foo'],
    'FluentRule, stripped value' => [FluentRule::string()->in(['items.*.foo']), ['string', 'in:"items.*.foo"'], 'foo'],
]);

it('rewrites every field parameter of a dependent tuple like native', function (): void {
    $data = ['items' => [['first' => 'x', 'second' => 'y']]];
    $rules = ['items.*.b' => [['required_with_all', 'items.*.first', 'items.*.second'], 'string']];

    expect(RuleSet::from($rules)->check($data)->errors()->keys())
        ->toBe(validator($data, $rules)->errors()->keys())
        ->toBe(['items.0.b']);
});

it('keeps the comparison values of a value-conditional tuple like native', function (): void {
    $data = ['items' => [['other' => 'foo']]];
    $rules = ['items.*.b' => [['required_if', 'items.*.other', 'items.*.foo'], 'string']];

    expect(RuleSet::from($rules)->check($data)->errors()->keys())
        ->toBe(validator($data, $rules)->errors()->keys())
        ->toBeEmpty();
});

it('rewrites references in custom dependent rules like native', function (): void {
    Validator::extendDependent(
        'matches_sibling',
        static fn (string $attribute, mixed $value, array $parameters, BaseValidator $validator): bool => $validator->validateSame($attribute, $value, array_values(array_filter($parameters, is_string(...)))),
    );

    $data = ['items' => [['other' => 'x', 'b' => 'x'], ['other' => 'x', 'b' => 'y']]];
    $rules = ['items.*.other' => 'string', 'items.*.b' => 'matches_sibling:items.*.other'];

    expect(RuleSet::from($rules)->check($data)->errors()->keys())
        ->toBe(validator($data, $rules)->errors()->keys())
        ->toBe(['items.1.b']);
});
