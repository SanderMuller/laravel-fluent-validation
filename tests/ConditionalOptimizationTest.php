<?php

declare(strict_types=1);

use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use SanderMuller\FluentValidation\RuleSet;

/**
 * Tests for conditional pre-evaluation optimizations in RuleSet::validate().
 * Covers type-dispatch, conditional rule reduction, validator caching,
 * and per-dispatch fast-check compilation.
 *
 * Note: RuleSet::validate() returns data from the top-level validator.
 * Excluded fields skip validation but still appear in the output
 * (the `exclude` pseudo-failure is handled per-item, not globally).
 */

// =========================================================================
// RuleSet::validate() — conditional rules don't cause errors
// =========================================================================

it('does not error on excluded conditional fields in RuleSet::validate()', function (): void {
    // isbn is required, but excluded for dvd. Should pass without error.
    $validated = RuleSet::from([
        'items' => 'required|array',
        'items.*.type' => ['required', 'string', Rule::in(['book', 'dvd'])],
        'items.*.isbn' => [['exclude_unless', 'items.*.type', 'book'], 'required', 'string', 'min:10'],
    ])->validate([
        'items' => [
            ['type' => 'dvd', 'isbn' => 'X'],         // isbn excluded — invalid value doesn't matter
            ['type' => 'book', 'isbn' => '1234567890'], // isbn validated — must pass
        ],
    ]);

    expect($validated['items'][1]['isbn'])->toBe('1234567890');
});

it('errors on surviving conditional fields that fail validation', function (): void {
    expect(fn () => RuleSet::from([
        'items' => 'required|array',
        'items.*.type' => ['required', 'string', Rule::in(['book', 'dvd'])],
        'items.*.isbn' => [['exclude_unless', 'items.*.type', 'book'], 'required', 'string', 'min:10'],
    ])->validate([
        'items' => [
            ['type' => 'book', 'isbn' => 'short'], // fails min:10
        ],
    ]))->toThrow(ValidationException::class);
});

it('handles exclude_if conditions correctly', function (): void {
    // price is excluded when type=free. Should pass.
    $validated = RuleSet::from([
        'items' => 'required|array',
        'items.*.type' => ['required', 'string'],
        'items.*.price' => [['exclude_if', 'items.*.type', 'free'], 'required', 'numeric', 'min:1'],
    ])->validate([
        'items' => [
            ['type' => 'free', 'price' => 0],     // excluded — price 0 doesn't trigger min:1 error
            ['type' => 'paid', 'price' => 9.99],   // validated
        ],
    ]);

    expect($validated['items'][1]['price'])->toBe(9.99);
});

it('handles multiple types in exclude_unless condition', function (): void {
    // style_top excluded unless type is button, text, or image.
    // frame type should pass even with invalid style_top.
    $validated = RuleSet::from([
        'items' => 'required|array',
        'items.*.type' => ['required', 'string', Rule::in(['button', 'text', 'image', 'frame'])],
        'items.*.style_top' => [['exclude_unless', 'items.*.type', 'button', 'text', 'image'], 'required', 'string', 'min:3'],
    ])->validate([
        'items' => [
            ['type' => 'button', 'style_top' => '10%'],
            ['type' => 'frame', 'style_top' => 'X'],   // excluded — 'X' is too short but doesn't error
            ['type' => 'image', 'style_top' => '30%'],
        ],
    ]);

    expect($validated['items'][0]['style_top'])->toBe('10%')
        ->and($validated['items'][2]['style_top'])->toBe('30%');
});

// =========================================================================
// Dispatch table: reuses validators for same type
// =========================================================================

it('validates 20 items with 2 types using dispatch table', function (): void {
    $items = array_map(fn (int $i): array => [
        'type' => $i % 2 === 0 ? 'chapter' : 'button',
        'title' => "Item {$i}",
    ], range(1, 20));

    $validated = RuleSet::from([
        'items' => 'required|array',
        'items.*.type' => ['required', 'string', Rule::in(['chapter', 'button'])],
        'items.*.title' => [['exclude_unless', 'items.*.type', 'chapter'], 'required', 'string'],
    ])->validate(['items' => $items]);

    // Validation passes — title is required for chapters but excluded for buttons.
    expect($validated['items'])->toHaveCount(20);
});

// =========================================================================
// Stripped conditional rules enable fast-checking
// =========================================================================

it('validates conditional fields with fast-checkable rules after stripping', function (): void {
    // After stripping exclude_unless, "boolean" and "nullable|string" are fast-checkable.
    $validated = RuleSet::from([
        'items' => 'required|array',
        'items.*.type' => ['required', 'string', Rule::in(['chapter', 'button'])],
        'items.*.collapsed' => [['exclude_unless', 'items.*.type', 'chapter'], 'boolean'],
        'items.*.text' => [['exclude_unless', 'items.*.type', 'button'], 'nullable', 'string'],
    ])->validate([
        'items' => [
            ['type' => 'chapter', 'collapsed' => false, 'text' => null],
            ['type' => 'button', 'collapsed' => true, 'text' => '<p>Hello</p>'],
        ],
    ]);

    // Both pass — collapsed validated for chapter, text validated for button.
    expect($validated['items'][0]['collapsed'])->toBeFalse();
    expect($validated['items'][1]['text'])->toBe('<p>Hello</p>');
});

it('fast-checks stringified In/NotIn objects', function (): void {
    $validated = RuleSet::from([
        'items' => 'required|array',
        'items.*.type' => ['required', 'string', Rule::in(['chapter', 'button'])],
        'items.*.speed' => [['exclude_unless', 'items.*.type', 'button'], 'string', Rule::in(['slow', 'normal', 'fast'])],
    ])->validate([
        'items' => [
            ['type' => 'button', 'speed' => 'normal'],
            ['type' => 'chapter', 'speed' => 'invalid'],  // excluded — invalid doesn't error
        ],
    ]);

    expect($validated['items'][0]['speed'])->toBe('normal');
});

it('errors when stringified In rule fails on non-excluded item', function (): void {
    expect(fn () => RuleSet::from([
        'items' => 'required|array',
        'items.*.type' => ['required', 'string', Rule::in(['chapter', 'button'])],
        'items.*.speed' => [['exclude_unless', 'items.*.type', 'button'], 'string', Rule::in(['slow', 'normal', 'fast'])],
    ])->validate([
        'items' => [
            ['type' => 'button', 'speed' => 'invalid'],  // NOT excluded — fails In check
        ],
    ]))->toThrow(ValidationException::class);
});

// =========================================================================
// Mixed scenarios
// =========================================================================

it('handles mix of conditional and unconditional rules', function (): void {
    $validated = RuleSet::from([
        'items' => 'required|array',
        'items.*.type' => ['required', 'string'],
        'items.*.name' => 'required|string|max:255',  // unconditional
        'items.*.chapters' => [['exclude_unless', 'items.*.type', 'chapter'], 'array'],
    ])->validate([
        'items' => [
            ['type' => 'button', 'name' => 'Btn', 'chapters' => []],
            ['type' => 'chapter', 'name' => 'Ch', 'chapters' => [['title' => 'C1']]],
        ],
    ]);

    expect($validated['items'][0]['name'])->toBe('Btn')
        ->and($validated['items'][1]['name'])->toBe('Ch');
});

it('handles items with no conditional rules', function (): void {
    $validated = RuleSet::from([
        'items' => 'required|array',
        'items.*.name' => 'required|string|max:255',
        'items.*.age' => 'required|numeric|min:0',
    ])->validate([
        'items' => [
            ['name' => 'Alice', 'age' => 30],
            ['name' => 'Bob', 'age' => 25],
        ],
    ]);

    expect($validated['items'])->toHaveCount(2)
        ->and($validated['items'][0]['name'])->toBe('Alice');
});

it('all items excluded for a conditional field still pass validation', function (): void {
    // All items are "dvd" — isbn excluded for all. required doesn't trigger.
    $validated = RuleSet::from([
        'items' => 'required|array',
        'items.*.type' => ['required', 'string'],
        'items.*.isbn' => [['exclude_unless', 'items.*.type', 'book'], 'required', 'string'],
    ])->validate([
        'items' => [
            ['type' => 'dvd'],
            ['type' => 'dvd'],
        ],
    ]);

    expect($validated['items'])->toHaveCount(2);
});
