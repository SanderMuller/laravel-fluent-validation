<?php

declare(strict_types=1);

use SanderMuller\FluentValidation\WildcardExpander;

it('returns pattern unchanged when no wildcards', function (): void {
    expect(WildcardExpander::expand('name', ['name' => 'John']))
        ->toBe(['name']);
});

it('expands simple wildcard on array items', function (): void {
    expect(WildcardExpander::expand('items.*', ['items' => ['a', 'b', 'c']]))
        ->toBe(['items.0', 'items.1', 'items.2']);
});

it('expands wildcard on nested field', function (): void {
    $data = [
        'items' => [
            ['name' => 'John'],
            ['name' => 'Jane'],
        ],
    ];

    expect(WildcardExpander::expand('items.*.name', $data))
        ->toBe(['items.0.name', 'items.1.name']);
});

it('expands double wildcard', function (): void {
    $data = [
        'items' => [
            ['options' => ['a', 'b']],
            ['options' => ['c']],
        ],
    ];

    expect(WildcardExpander::expand('items.*.options.*', $data))
        ->toBe(['items.0.options.0', 'items.0.options.1', 'items.1.options.0']);
});

it('expands deeply nested wildcards', function (): void {
    $data = [
        'a' => [
            ['b' => [
                ['c' => 'x'],
                ['c' => 'y'],
            ]],
        ],
    ];

    expect(WildcardExpander::expand('a.*.b.*.c', $data))
        ->toBe(['a.0.b.0.c', 'a.0.b.1.c']);
});

it('returns empty when data is missing', function (): void {
    expect(WildcardExpander::expand('items.*.name', ['other' => 'data']))
        ->toBe([]);
});

it('returns empty when wildcard target is not an array', function (): void {
    expect(WildcardExpander::expand('items.*', ['items' => 'not-an-array']))
        ->toBe([]);
});

it('handles associative array keys', function (): void {
    $data = [
        'users' => [
            'admin' => ['name' => 'Alice'],
            'editor' => ['name' => 'Bob'],
        ],
    ];

    expect(WildcardExpander::expand('users.*.name', $data))
        ->toBe(['users.admin.name', 'users.editor.name']);
});

it('handles empty arrays', function (): void {
    expect(WildcardExpander::expand('items.*', ['items' => []]))
        ->toBe([]);
});

it('handles wildcard at end without sub-field', function (): void {
    expect(WildcardExpander::expand('tags.*', ['tags' => ['php', 'laravel', 'pest']]))
        ->toBe(['tags.0', 'tags.1', 'tags.2']);
});

it('produces paths for missing keys after wildcard', function (): void {
    $data = [
        'items' => [
            ['sort_order' => 1],
            ['title' => 'hello', 'sort_order' => 2],
        ],
    ];

    expect(WildcardExpander::expand('items.*.title', $data))
        ->toBe(['items.0.title', 'items.1.title']);
});

it('produces paths for missing nested keys after wildcard', function (): void {
    $data = [
        'items' => [
            ['style' => ['top' => '10%']],
            ['style' => []],
        ],
    ];

    expect(WildcardExpander::expand('items.*.style.color', $data))
        ->toBe(['items.0.style.color', 'items.1.style.color']);
});
