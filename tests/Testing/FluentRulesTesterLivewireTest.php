<?php declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Livewire\Component;
use SanderMuller\FluentValidation\Testing\FluentRulesTester;
use SanderMuller\FluentValidation\Tests\Fixtures\AppealLivewireComponent;

if (! class_exists(Component::class)) {
    return;
}

// =========================================================================
// Livewire component target — set + call style (mirrors Livewire::test() shape)
// =========================================================================

it('passes against a Livewire component when state + action satisfy validation', function (): void {
    FluentRulesTester::for(AppealLivewireComponent::class)
        ->set('type', 'refund')
        ->set('reason', 'Order arrived damaged in transit.')
        ->call('submit')
        ->passes();
});

it('fails against a Livewire component when state violates rules', function (): void {
    FluentRulesTester::for(AppealLivewireComponent::class)
        ->set('type', 'refund')
        ->set('reason', 'short')
        ->call('submit')
        ->failsWith('reason', 'min');
});

it('Livewire set() accepts an array form (Livewire-parity)', function (): void {
    FluentRulesTester::for(AppealLivewireComponent::class)
        ->set(['type' => 'refund', 'reason' => 'Order arrived damaged in transit.'])
        ->call('submit')
        ->passes();
});

// =========================================================================
// Livewire component target — with() style (data-shape parity with FormRequest)
// =========================================================================

it('with() form for Livewire targets expands to per-property set() calls', function (): void {
    FluentRulesTester::for(AppealLivewireComponent::class)
        ->with(['type' => 'refund', 'reason' => 'Order arrived damaged in transit.'])
        ->call('submit')
        ->passes();
});

// =========================================================================
// Livewire component target — submit() guard branch (NOT a validate() failure)
// =========================================================================

it('captures errors added via addError() outside of validate()', function (): void {
    // The submit() guard adds an error and returns before $this->validate() runs.
    // The tester should still surface that error via failsWith().
    FluentRulesTester::for(AppealLivewireComponent::class)
        ->set('rateLimited', true)
        ->set('type', 'refund')
        ->set('reason', 'Order arrived damaged in transit.')
        ->call('submit')
        ->failsWith('reason');
});

// =========================================================================
// Livewire component target — mount() integration
// =========================================================================

it('mount() parameters are forwarded to Livewire::test()', function (): void {
    // AppealLivewireComponent has no mount() args, but mount([]) should be inert.
    // (A no-op smoke test confirming the chain method doesn't break the resolution path.)
    FluentRulesTester::for(AppealLivewireComponent::class)
        ->mount([])
        ->set('type', 'refund')
        ->set('reason', 'Order arrived damaged in transit.')
        ->call('submit')
        ->passes();
});

// =========================================================================
// Lazy-validation contract — call() is the trigger for Livewire targets
// =========================================================================

it('raises LogicException when an assertion runs before call() on Livewire target', function (): void {
    expect(static function (): void {
        FluentRulesTester::for(AppealLivewireComponent::class)
            ->set('type', 'refund')
            ->passes();
    })->toThrow(LogicException::class, 'call(...) must be invoked');
});

it('raises LogicException when failsWith() runs before call() on Livewire target', function (): void {
    expect(static function (): void {
        FluentRulesTester::for(AppealLivewireComponent::class)
            ->set('reason', '')
            ->failsWith('reason');
    })->toThrow(LogicException::class);
});

// =========================================================================
// Re-callable contract — set/call/with all reset cached Validated
// =========================================================================

it('reuses the same tester across multiple state + action cycles', function (): void {
    $tester = FluentRulesTester::for(AppealLivewireComponent::class);

    $tester
        ->set('type', 'refund')
        ->set('reason', 'Order arrived damaged in transit.')
        ->call('submit')
        ->passes();

    // Reset state via new set() calls; same tester, new outcome.
    $tester
        ->set('type', '')
        ->set('reason', 'short')
        ->call('submit')
        ->failsWith('type')
        ->failsWith('reason');
});

// =========================================================================
// Regression — pendingSets / data must NOT bleed across cycles.
// Codex flagged: prior cycle's set() calls were replayed AFTER the current
// cycle's with() data, silently overriding the new payload.
// =========================================================================

it('does not bleed prior set() state into a subsequent with() cycle', function (): void {
    $tester = FluentRulesTester::for(AppealLivewireComponent::class);

    // Cycle 1: set state, dispatch.
    $tester
        ->set('type', 'refund')
        ->set('reason', 'Order arrived damaged in transit.')
        ->call('submit')
        ->passes();

    // Cycle 2: with() supplies a complete new payload. The cycle 1 set() calls
    // must NOT replay (they would otherwise override `type` back to `refund`).
    $tester
        ->with(['type' => 'access', 'reason' => 'Need data export.'])
        ->call('submit')
        ->passes();

    // Cycle 3: a fresh `set()` after a fresh `with()` — pendingSets is empty,
    // not carrying cycle 1's appendages.
    $tester
        ->with(['type' => 'access', 'reason' => 'Need data export.'])
        ->set('reason', 'short')
        ->call('submit')
        ->failsWith('reason', 'min');
});

it('does not bleed prior set() state into a subsequent set()-only cycle', function (): void {
    $tester = FluentRulesTester::for(AppealLivewireComponent::class);

    // Cycle 1: set + dispatch + assert.
    $tester
        ->set('type', 'refund')
        ->set('reason', 'Order arrived damaged in transit.')
        ->call('submit')
        ->passes();

    // Cycle 2: ONLY a partial set() — `type` would default to '' on the fresh
    // Livewire instance. The reason='short' here must NOT compose with cycle
    // 1's pendingSets (which would have made `type` carry over and `reason`
    // potentially get overridden by the older 'Order arrived...' value).
    $tester
        ->set('reason', 'short')
        ->call('submit')
        ->failsWith('type', 'required')   // empty default; required fails
        ->failsWith('reason', 'min');     // 'short' fails min:10
});

it('with() called before set() in the same cycle composes set() last-wins', function (): void {
    // Within a single cycle, set() after with() should override matching keys.
    FluentRulesTester::for(AppealLivewireComponent::class)
        ->with(['type' => 'refund', 'reason' => 'First reason long enough.'])
        ->set('reason', 'Second reason long enough.')
        ->call('submit')
        ->passes();
});

// =========================================================================
// Multi-action queuing — andCall() composes against the same Testable.
// Hihaho-driven: ImportInteractionsModalTest::selectVideo→import shape.
// =========================================================================

it('chains call() + andCall() against the same component instance', function (): void {
    // openModal() mutates state (modalOpen = true). submit() is dispatched
    // against the SAME instance, so its validation context has the prior
    // mutation in scope.
    FluentRulesTester::for(AppealLivewireComponent::class)
        ->set('type', 'refund')
        ->set('reason', 'Order arrived damaged in transit.')
        ->call('openModal')
        ->andCall('submit')
        ->passes();
});

it('andCall() is functionally identical to call() — both append to the queue', function (): void {
    // Same chain twice with andCall() ↔ call() swapped — should produce
    // identical outcomes.
    FluentRulesTester::for(AppealLivewireComponent::class)
        ->set('type', 'refund')
        ->set('reason', 'Order arrived damaged in transit.')
        ->call('openModal')
        ->call('submit')
        ->passes();
});

it('multi-action chain dispatches actions in append order', function (): void {
    // If actions ran in any other order, openModal wouldn't run before submit
    // and the modalOpen mutation wouldn't be observable. Smoke check: just
    // confirm the dispatch sequence completes without error.
    FluentRulesTester::for(AppealLivewireComponent::class)
        ->set('type', 'refund')
        ->set('reason', 'Order arrived damaged in transit.')
        ->call('openModal')
        ->andCall('submit')
        ->passes();
});

// =========================================================================
// addError() error-bag capture — pre-validate AND post-validate paths.
// Hihaho's CreateTranslatedCopy (pre) + CreateApiToken (post) shapes.
// =========================================================================

it('captures pre-validate addError that returns before $this->validate()', function (): void {
    // Rate-limit guard never invokes validate() — error must still surface.
    FluentRulesTester::for(AppealLivewireComponent::class)
        ->set('rateLimited', true)
        ->set('type', 'refund')
        ->set('reason', 'Order arrived damaged in transit.')
        ->call('submit')
        ->failsWith('reason');
});

it('captures post-validate addError that runs after a successful validate()', function (): void {
    // Validation passes; quotaExceeded branch then addError's `type`.
    // The validator's bag is empty (validate didn't fail); the addError
    // updates the component's bag. Tester must surface BOTH paths.
    FluentRulesTester::for(AppealLivewireComponent::class)
        ->set('type', 'refund')
        ->set('reason', 'Order arrived damaged in transit.')
        ->set('quotaExceeded', true)
        ->call('submit')
        ->failsWith('type');
});

// =========================================================================
// actingAs() covers Livewire targets too (1.13.2 regression — collectiq found
// that runLivewire() ignored $this->actingAs; auth()->user() was null inside
// mount() / actions / policy gates).
// =========================================================================

it('actingAs() binds the auth user for Livewire targets', function (): void {
    $user = new GenericUser(['id' => 42]);

    FluentRulesTester::for(AppealLivewireComponent::class)
        ->actingAs($user)
        ->call('requireAuthenticatedUser')
        ->passes();
});

it('without actingAs() the Livewire target sees a null user', function (): void {
    // Without actingAs(), auth()->user() returns null inside the component.
    // The fixture's requireAuthenticatedUser() surfaces this as an 'auth' error.
    FluentRulesTester::for(AppealLivewireComponent::class)
        ->call('requireAuthenticatedUser')
        ->failsWith('auth');
});

// =========================================================================
// Surface assertions also work on Livewire targets
// =========================================================================

it('failsWithAny matches a dotted descendant on Livewire targets', function (): void {
    // AppealLivewireComponent uses flat keys, so failsWithAny('reason')
    // is functionally equivalent to failsWith('reason'). Confirms the
    // assertion library composes uniformly across all target shapes.
    FluentRulesTester::for(AppealLivewireComponent::class)
        ->set('type', 'refund')
        ->set('reason', 'short')
        ->call('submit')
        ->failsWithAny('reason');
});

it('failsOnly works on Livewire targets', function (): void {
    FluentRulesTester::for(AppealLivewireComponent::class)
        ->set('type', 'refund')
        ->set('reason', 'short')
        ->call('submit')
        ->failsOnly('reason', 'min');
});

it('doesNotFailOn passes when listed Livewire fields are clean', function (): void {
    FluentRulesTester::for(AppealLivewireComponent::class)
        ->set('type', 'refund')
        ->set('reason', 'short')
        ->call('submit')
        ->fails()
        ->doesNotFailOn('type');
});
