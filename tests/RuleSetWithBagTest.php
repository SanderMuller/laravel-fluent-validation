<?php declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\RuleSet;

// =========================================================================
// RuleSet::withBag() — routes ValidationException into a named error bag.
// Mirrors Laravel's Validator::validateWithBag for multi-form pages
// (Fortify update-password etc.).
// =========================================================================

it('validate() without withBag() throws with default empty errorBag', function (): void {
    $ruleSet = RuleSet::from(['name' => FluentRule::string()->required()]);

    try {
        $ruleSet->validate([]);
        throw new RuntimeException('should have thrown');
    } catch (ValidationException $validationException) {
        expect($validationException->errorBag)->toBe('default');
    }
});

it('validate() with withBag() sets errorBag on the thrown exception', function (): void {
    $ruleSet = RuleSet::from(['name' => FluentRule::string()->required()])
        ->withBag('updatePassword');

    try {
        $ruleSet->validate([]);
        throw new RuntimeException('should have thrown');
    } catch (ValidationException $validationException) {
        expect($validationException->errorBag)->toBe('updatePassword');
    }
});

it('withBag() is chainable with other toggles', function (): void {
    $ruleSet = RuleSet::from(['name' => FluentRule::string()->required()])
        ->withBag('profile')
        ->stopOnFirstFailure();

    try {
        $ruleSet->validate([]);
        throw new RuntimeException('should have thrown');
    } catch (ValidationException $validationException) {
        expect($validationException->errorBag)->toBe('profile');
    }
});

it('withBag() also stamps the wildcard-path exception', function (): void {
    $ruleSet = RuleSet::from([
        'items.*.name' => FluentRule::string()->required(),
    ])->withBag('import');

    try {
        $ruleSet->validate(['items' => [['name' => ''], ['name' => 'ok']]]);
        throw new RuntimeException('should have thrown');
    } catch (ValidationException $validationException) {
        expect($validationException->errorBag)->toBe('import');
    }
});

it('withBag() does not affect check() — check never throws', function (): void {
    $ruleSet = RuleSet::from(['name' => FluentRule::string()->required()])
        ->withBag('updatePassword');

    $result = $ruleSet->check([]);

    expect($result->passes())->toBeFalse()
        ->and($result->errors()->has('name'))->toBeTrue();
});

it('withBag() passes through when validation succeeds', function (): void {
    $ruleSet = RuleSet::from(['name' => FluentRule::string()->required()])
        ->withBag('updatePassword');

    $validated = $ruleSet->validate(['name' => 'Alice']);

    expect($validated)->toBe(['name' => 'Alice']);
});

it('withBag() covers the Fortify use case end-to-end', function (): void {
    // Mirrors mijntp's UpdateUserPassword action: multi-form page where
    // this flow's errors must go into its own bag so they don't collide
    // with the other forms' error display.
    $ruleSet = RuleSet::from([
        'current_password' => FluentRule::string()->required()->min(8),
        'new_password' => FluentRule::string()->required()->min(12),
    ])->withBag('updatePassword');

    try {
        $ruleSet->validate(['current_password' => 'short', 'new_password' => 'alsoshort']);
        throw new RuntimeException('should have thrown');
    } catch (ValidationException $validationException) {
        expect($validationException->errorBag)->toBe('updatePassword')
            ->and($validationException->validator->errors()->has('current_password'))->toBeTrue()
            ->and($validationException->validator->errors()->has('new_password'))->toBeTrue();
    }
});
