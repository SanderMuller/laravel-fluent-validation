<?php declare(strict_types=1);

use SanderMuller\FluentValidation\FastCheckCompiler;

/**
 * Pins the {@see FastCheckCompiler::compile()} cache contract.
 *
 * The cache is a per-process static map, so any rule whose compiled closure
 * captures a time-sensitive value (date literals resolved via `strtotime()`)
 * cannot be cached across Octane requests without freezing the timestamp.
 * This file documents and enforces that property at the closure-identity
 * level — a regression in the cache logic flips one of these tests.
 */
beforeEach(function (): void {
    // Cache is process-wide static; isolate each test to avoid order-coupling
    // (the cap-reset assertions depend on a known starting count).
    FastCheckCompiler::resetCompileCache();
});

it('reuses the same closure instance for stable rule strings', function (): void {
    $first = FastCheckCompiler::compile('required|string|max:255');
    $second = FastCheckCompiler::compile('required|string|max:255');

    expect($first)->toBeInstanceOf(Closure::class)
        ->and($second)->toBe($first);
});

it('skips cache for date-comparison rules to keep relative timestamps fresh', function (string $rule): void {
    // Rules in this set bake a strtotime() result into the closure at
    // compile time. Caching the closure across requests would freeze
    // relative tokens like `today` / `now` for the lifetime of the
    // Octane worker — so compile() must return a fresh closure each time.
    $first = FastCheckCompiler::compile($rule);
    $second = FastCheckCompiler::compile($rule);

    expect($first)->toBeInstanceOf(Closure::class)
        ->and($second)->toBeInstanceOf(Closure::class)
        ->and($second)->not->toBe($first);
})->with([
    'after:today' => ['required|date|after:today'],
    'before:now' => ['required|date|before:now'],
    'after_or_equal:tomorrow' => ['required|date|after_or_equal:tomorrow'],
    'before_or_equal:+1 week' => ['required|date|before_or_equal:+1 week'],
    'date_equals:today' => ['required|date|date_equals:today'],
    'absolute date literal' => ['required|date|after:2030-01-01'],
]);

/**
 * Pins the cap-reset behaviour at `COMPILE_CACHE_MAX = 1024`. Octane workers
 * with high rule-string variance (per-tenant `in:` lists, generated regexes)
 * rely on the cap drop to avoid unbounded growth — a regression that pushes
 * the threshold or skips the reset would surface here.
 */
it('caches up to COMPILE_CACHE_MAX entries before resetting', function (): void {
    // Compile 1024 distinct stable rules. None hit the cap-reset since
    // count >= MAX is checked BEFORE insert: at the 1024th call,
    // pre-insert count = 1023 (no reset), post-insert count = 1024.
    for ($i = 1; $i <= 1024; ++$i) {
        FastCheckCompiler::compile('required|string|max:' . $i);
    }

    // Last entry must still be cached.
    $first = FastCheckCompiler::compile('required|string|max:1024');
    $second = FastCheckCompiler::compile('required|string|max:1024');

    expect($first)->toBe($second);
});

it('drops cache and recompiles fresh after the 1025th distinct rule', function (): void {
    // Pin the original closure for rule #1.
    $original = FastCheckCompiler::compile('required|string|max:1');

    // Fill to 1024 (rule #1 already counts as one of those).
    for ($i = 2; $i <= 1024; ++$i) {
        FastCheckCompiler::compile('required|string|max:' . $i);
    }

    // The 1025th distinct compile triggers the cap reset before insert.
    FastCheckCompiler::compile('required|string|max:1025');

    // Rule #1's old closure was discarded with the cache reset; recompiling
    // yields a different Closure instance.
    $afterReset = FastCheckCompiler::compile('required|string|max:1');

    expect($afterReset)->toBeInstanceOf(Closure::class)
        ->and($afterReset)->not->toBe($original);
});
