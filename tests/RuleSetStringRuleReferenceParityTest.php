<?php declare(strict_types=1);

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
    'before a later sibling date' => ['date|before:items.*.other', ['other' => '2025-01-01', 'b' => '2025-06-01']],
]);
