<?php declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;

// =========================================================================
// tests/TestCase::defineEnvironment() — app.key fallback-only contract.
// Codex flagged: the docblock claimed "idempotent for local runs that
// already have an app key configured" but the original code unconditionally
// overwrote. Now guard-checked; assert preservation below.
// =========================================================================

it('defineEnvironment sets a fallback app.key when none is configured', function (): void {
    // Testbench wipes config on each test — within a normal test the
    // fallback key is present.
    expect(config('app.key'))->not->toBeNull()
        ->and(config('app.key'))->not->toBeEmpty();
});

it('preserves a preconfigured app.key rather than overwriting', function (): void {
    // Pre-set a deliberate app.key, then re-invoke defineEnvironment
    // directly — reflection avoids the PHPUnit TestCase(name) constructor
    // contract that anon-subclass instantiation trips.
    $repo = app()->make(Repository::class);
    $repo->set('app.key', 'base64:preconfigured-key-abcdefghij012345678901');

    $method = new ReflectionMethod($this, 'defineEnvironment');
    $method->invoke($this, app());

    expect($repo->get('app.key'))->toBe('base64:preconfigured-key-abcdefghij012345678901');
});
