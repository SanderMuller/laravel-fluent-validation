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
