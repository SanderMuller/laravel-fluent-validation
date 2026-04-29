<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests\FastCheck;

use Closure;
use SanderMuller\FluentValidation\FastCheck\CoreValueCompiler;
use SanderMuller\FluentValidation\FastCheck\ItemContextCompiler;
use SanderMuller\FluentValidation\FastCheck\PresenceConditionalCompiler;
use SanderMuller\FluentValidation\FastCheck\ProhibitedCompiler;

it('CoreValueCompiler returns null for rule strings it does not handle', function (): void {
    expect(CoreValueCompiler::compile('prohibited'))->toBeNull()
        ->and(CoreValueCompiler::compile('required_with:other'))->toBeNull()
        ->and(CoreValueCompiler::compile('same:other'))->toBeNull()
        ->and(CoreValueCompiler::compile('unknown_rule'))->toBeNull()
        ->and(CoreValueCompiler::compile('min:5'))->toBeNull() // no type flag
        ->and(CoreValueCompiler::compile('string|required'))->toBeInstanceOf(Closure::class);
});

it('ProhibitedCompiler returns null when rule is not bare prohibited (+nullable/sometimes/bail only)', function (): void {
    expect(ProhibitedCompiler::compile('string|max:10'))->toBeNull()
        ->and(ProhibitedCompiler::compile('required'))->toBeNull()
        ->and(ProhibitedCompiler::compile('prohibited|string'))->toBeNull()
        ->and(ProhibitedCompiler::compile('prohibited|required'))->toBeNull()
        ->and(ProhibitedCompiler::compile('nullable|sometimes'))->toBeNull() // no prohibited
        ->and(ProhibitedCompiler::compile('prohibited'))->toBeInstanceOf(Closure::class)
        ->and(ProhibitedCompiler::compile('prohibited|nullable'))->toBeInstanceOf(Closure::class)
        ->and(ProhibitedCompiler::compile('bail|prohibited|sometimes'))->toBeInstanceOf(Closure::class);
});

it('ItemContextCompiler returns null when rule has no item-aware tokens', function (): void {
    expect(ItemContextCompiler::compile('string|max:10'))->toBeNull()
        ->and(ItemContextCompiler::compile('required|email'))->toBeNull()
        ->and(ItemContextCompiler::compile('prohibited'))->toBeNull()
        ->and(ItemContextCompiler::compile('required|same:other'))->toBeInstanceOf(Closure::class);
});

it('PresenceConditionalCompiler returns null when rule contains no presence conditional', function (): void {
    expect(PresenceConditionalCompiler::compile('required|string'))->toBeNull()
        ->and(PresenceConditionalCompiler::compile('same:other'))->toBeNull()
        ->and(PresenceConditionalCompiler::compile('prohibited'))->toBeNull()
        ->and(PresenceConditionalCompiler::compile('required_with:foo|string'))->toBeInstanceOf(Closure::class);
});

it('CoreValueCompiler integer:strict closure rejects numeric strings, integer accepts', function (): void {
    // Behavior is identical across all supported Laravel versions because we're
    // testing the closure directly, not Laravel's outer validator. Strict mode
    // rejects numeric strings (`is_int` only); non-strict accepts them
    // (`filter_var`-style). Returning false from a fast-check closure does NOT
    // mark the value as failing — it defers the rule to Laravel's validator.
    // Laravel's outcome on numeric-string inputs under strict differs by
    // version (12.23+ rejects, older lenient-passes); see the README rule
    // reference note about Laravel 12.23+ for runtime semantics.
    $strict = CoreValueCompiler::compile('numeric|integer:strict');
    expect($strict)->toBeInstanceOf(Closure::class);
    assert($strict instanceof Closure);

    expect($strict(42))->toBeTrue()    // int — passes fast-check
        ->and($strict('42'))->toBeFalse() // string — defers to Laravel
        ->and($strict(4.2))->toBeFalse(); // float — defers

    $nonStrict = CoreValueCompiler::compile('numeric|integer');
    expect($nonStrict)->toBeInstanceOf(Closure::class);
    assert($nonStrict instanceof Closure);

    expect($nonStrict(42))->toBeTrue()
        ->and($nonStrict('42'))->toBeTrue() // numeric string accepted
        ->and($nonStrict(4.2))->toBeFalse();
});
