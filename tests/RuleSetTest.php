<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationData;
use Illuminate\Validation\ValidationException;
use SanderMuller\FluentValidation\Rule;
use SanderMuller\FluentValidation\Rules\ArrayRule;
use SanderMuller\FluentValidation\Rules\NumericRule;
use SanderMuller\FluentValidation\Rules\StringRule;
use SanderMuller\FluentValidation\RuleSet;
use SanderMuller\FluentValidation\WildcardExpander;

// =========================================================================
// Basic RuleSet building
// =========================================================================

it('builds a rule set from fluent fields', function (): void {
    $rules = RuleSet::make()
        ->field('name', Rule::string()->required()->min(2)->max(255))
        ->field('age', Rule::numeric()->nullable()->integer()->min(0))
        ->toArray();

    expect($rules)->toHaveKeys(['name', 'age']);
    expect($rules['name'])->toBeInstanceOf(StringRule::class);
    expect($rules['age'])->toBeInstanceOf(NumericRule::class);
});

it('builds a rule set from an array via from()', function (): void {
    $rules = RuleSet::from([
        'name' => Rule::string()->required(),
        'age' => Rule::numeric()->nullable(),
    ])->toArray();

    expect($rules)->toHaveKeys(['name', 'age']);
});

it('handles mixed rule types via from()', function (): void {
    $rules = RuleSet::from([
        'name' => 'required|string|max:255',
        'age' => ['required', 'integer'],
        'email' => Rule::string()->required()->rule('email'),
    ])->toArray();

    expect($rules['name'])->toBe('required|string|max:255');
    expect($rules['age'])->toBe(['required', 'integer']);
    expect($rules['email'])->toBeInstanceOf(StringRule::class);
});

// =========================================================================
// each() flattening
// =========================================================================

it('flattens each() with a single rule to wildcard path', function (): void {
    $rules = RuleSet::from([
        'tags' => Rule::array()->required()->each(Rule::string()->max(50)),
    ])->toArray();

    expect($rules)->toHaveKeys(['tags', 'tags.*']);
    expect($rules['tags'])->toBeInstanceOf(ArrayRule::class);
    expect($rules['tags.*'])->toBeInstanceOf(StringRule::class);
});

it('flattens each() with field mappings to wildcard paths', function (): void {
    $rules = RuleSet::from([
        'items' => Rule::array()->required()->each([
            'name' => Rule::string()->required(),
            'qty' => Rule::numeric()->required()->integer(),
        ]),
    ])->toArray();

    expect($rules)->toHaveKeys(['items', 'items.*.name', 'items.*.qty']);
    expect($rules['items.*.name'])->toBeInstanceOf(StringRule::class);
    expect($rules['items.*.qty'])->toBeInstanceOf(NumericRule::class);
});

it('flattens nested each() recursively', function (): void {
    $rules = RuleSet::from([
        'orders' => Rule::array()->required()->each([
            'items' => Rule::array()->required()->each([
                'name' => Rule::string()->required(),
            ]),
        ]),
    ])->toArray();

    expect($rules)->toHaveKeys([
        'orders',
        'orders.*.items',
        'orders.*.items.*.name',
    ]);
});

it('does not flatten array without each()', function (): void {
    $rules = RuleSet::from([
        'tags' => Rule::array()->required(),
    ])->toArray();

    expect($rules)->toHaveKeys(['tags']);
    expect($rules)->not->toHaveKey('tags.*');
});

// =========================================================================
// expandWildcards()
// =========================================================================

it('expands wildcard fields against data', function (): void {
    $rules = RuleSet::from([
        'items' => Rule::array()->required(),
        'items.*.name' => Rule::string()->required(),
    ])->expandWildcards([
        'items' => [
            ['name' => 'John'],
            ['name' => 'Jane'],
        ],
    ]);

    expect($rules)->toHaveKeys(['items', 'items.0.name', 'items.1.name']);
    expect($rules)->not->toHaveKey('items.*.name');
});

it('expands each() rules against data', function (): void {
    $rules = RuleSet::from([
        'items' => Rule::array()->required()->each([
            'name' => Rule::string()->required(),
        ]),
    ])->expandWildcards([
        'items' => [
            ['name' => 'John'],
            ['name' => 'Jane'],
            ['name' => 'Jim'],
        ],
    ]);

    expect($rules)->toHaveKeys(['items', 'items.0.name', 'items.1.name', 'items.2.name']);
    expect($rules)->not->toHaveKey('items.*.name');
});

it('leaves non-wildcard fields unchanged during expansion', function (): void {
    $rules = RuleSet::from([
        'name' => Rule::string()->required(),
        'tags' => Rule::array()->each(Rule::string()->max(50)),
    ])->expandWildcards([
        'name' => 'John',
        'tags' => ['php', 'laravel'],
    ]);

    expect($rules)->toHaveKeys(['name', 'tags', 'tags.0', 'tags.1']);
});

it('handles empty array during wildcard expansion', function (): void {
    $rules = RuleSet::from([
        'items' => Rule::array()->each(Rule::string()),
    ])->expandWildcards(['items' => []]);

    expect($rules)->toHaveKey('items');
    expect($rules)->not->toHaveKey('items.0');
});

// =========================================================================
// validate() — success
// =========================================================================

it('validates data with wildcard rules', function (): void {
    $validated = RuleSet::from([
        'items' => Rule::array()->required()->min(1)->each([
            'name' => Rule::string()->required()->min(2),
        ]),
    ])->validate([
        'items' => [
            ['name' => 'John'],
            ['name' => 'Jane'],
        ],
    ]);

    expect($validated['items'])->toHaveCount(2);
});

it('validates simple rules without wildcards', function (): void {
    $validated = RuleSet::from([
        'name' => Rule::string()->required()->min(2),
        'age' => Rule::numeric()->nullable()->integer()->min(0),
    ])->validate(['name' => 'John', 'age' => 25]);

    expect($validated)->toBe(['name' => 'John', 'age' => 25]);
});

it('validates nested each() rules', function (): void {
    $validated = RuleSet::from([
        'orders' => Rule::array()->required()->each([
            'items' => Rule::array()->required()->each([
                'qty' => Rule::numeric()->required()->integer()->min(1),
            ]),
        ]),
    ])->validate([
        'orders' => [
            ['items' => [['qty' => 2], ['qty' => 5]]],
            ['items' => [['qty' => 1]]],
        ],
    ]);

    expect($validated['orders'])->toHaveCount(2);
    expect($validated['orders'][0]['items'])->toHaveCount(2);
});

it('validates scalar each() rules', function (): void {
    $validated = RuleSet::from([
        'tags' => Rule::array()->required()->each(Rule::string()->max(50)),
    ])->validate([
        'tags' => ['php', 'laravel'],
    ]);

    expect($validated['tags'])->toBe(['php', 'laravel']);
});

// =========================================================================
// validate() — failure
// =========================================================================

it('throws ValidationException for invalid wildcard data', function (): void {
    RuleSet::from([
        'items' => Rule::array()->required()->each([
            'name' => Rule::string()->required()->min(2),
        ]),
    ])->validate([
        'items' => [['name' => 'J']],
    ]);
})->throws(ValidationException::class);

it('throws ValidationException for invalid simple data', function (): void {
    RuleSet::from([
        'name' => Rule::string()->required()->min(5),
    ])->validate(['name' => 'Jo']);
})->throws(ValidationException::class);

// =========================================================================
// validate() — with custom messages and attributes
// =========================================================================

it('supports custom error messages', function (): void {
    try {
        RuleSet::from([
            'name' => Rule::string()->required(),
        ])->validate(
            ['name' => ''],
            ['name.required' => 'Please provide your name.']
        );
    } catch (ValidationException $validationException) {
        expect($validationException->errors()['name'][0])->toBe('Please provide your name.');

        return;
    }

    $this->fail('Expected ValidationException was not thrown.');
});

it('supports custom attribute names', function (): void {
    try {
        RuleSet::from([
            'email_address' => Rule::string()->required()->min(100),
        ])->validate(
            ['email_address' => 'test@example.com'],
            [],
            ['email_address' => 'email address']
        );
    } catch (ValidationException $validationException) {
        expect($validationException->errors()['email_address'][0])->toContain('email address');

        return;
    }

    $this->fail('Expected ValidationException was not thrown.');
});

// =========================================================================
// Benchmarks — excluded from default test run, use --group=benchmark
// =========================================================================

it('WildcardExpander outperforms native Laravel expansion with many patterns', function (): void {
    // Simulates a real-world import validator with deeply nested objects
    // and many wildcard patterns (similar to JsonInteractionImportValidator).
    $items = array_map(fn (int $i): array => [
        'type' => 'button',
        'title' => "Item {$i}",
        'start_time' => $i * 10,
        'end_time' => $i * 10 + 5,
        'style' => [
            'top' => '10%',
            'left' => '20%',
            'height' => '30%',
            'width' => '40%',
            'background_color' => '#ff0000',
            'border_radius' => 5,
            'padding_top' => 10,
            'padding_bottom' => 10,
            'border' => ['width' => 1, 'style' => 'solid', 'color' => '#000000'],
        ],
        'action' => [
            'type' => 'link',
            'link' => 'https://example.com',
            'time' => 0,
        ],
        'attributes' => [
            'show_indicator' => true,
            'indicator_color' => '#00ff00',
            'options' => ['menu_button_location' => 'top', 'menu_button_name' => 'Menu'],
        ],
        'chapters' => array_map(fn (int $j): array => [
            'title' => "Chapter {$j}",
            'start_time' => $j * 5,
            'end_time' => $j * 5 + 4,
            'sort_order' => $j,
        ], range(1, 4)),
    ], range(1, 200));
    $data = ['items' => $items];

    // All wildcard patterns that would exist in a complex validator
    $patterns = [
        'items.*.type', 'items.*.title', 'items.*.start_time', 'items.*.end_time',
        'items.*.style.top', 'items.*.style.left', 'items.*.style.height', 'items.*.style.width',
        'items.*.style.background_color', 'items.*.style.border_radius',
        'items.*.style.padding_top', 'items.*.style.padding_bottom',
        'items.*.style.border', 'items.*.style.border.width',
        'items.*.style.border.style', 'items.*.style.border.color',
        'items.*.action', 'items.*.action.type', 'items.*.action.link', 'items.*.action.time',
        'items.*.attributes', 'items.*.attributes.show_indicator',
        'items.*.attributes.indicator_color', 'items.*.attributes.options',
        'items.*.attributes.options.menu_button_location', 'items.*.attributes.options.menu_button_name',
        'items.*.chapters', 'items.*.chapters.*.title',
        'items.*.chapters.*.start_time', 'items.*.chapters.*.end_time',
        'items.*.chapters.*.sort_order',
    ];

    // Native Laravel: Arr::dot() + regex per pattern
    $nativeStart = microtime(true);

    foreach ($patterns as $pattern) {
        ValidationData::initializeAndGatherData($pattern, $data);
    }

    $nativeElapsed = microtime(true) - $nativeStart;

    // WildcardExpander: direct tree traversal per pattern
    $expanderStart = microtime(true);

    foreach ($patterns as $pattern) {
        WildcardExpander::expand($pattern, $data);
    }

    $expanderElapsed = microtime(true) - $expanderStart;

    // WildcardExpander should be at least 50% faster
    expect($expanderElapsed)->toBeLessThan($nativeElapsed * 0.5);
})->group('benchmark');

it('RuleSet::validate() with compiled rules matches native Laravel performance', function (): void {
    $items = array_map(fn (int $i): array => [
        'name' => "Item {$i}",
        'email' => "user{$i}@example.com",
        'age' => $i % 80 + 18,
        'role' => ['admin', 'editor', 'viewer'][$i % 3],
    ], range(1, 500));
    $data = ['items' => $items];

    // Native Laravel with string rules
    $nativeStart = microtime(true);
    Validator::make($data, [
        'items' => 'required|array',
        'items.*.name' => 'required|string|min:2|max:255',
        'items.*.email' => 'required|string|max:255',
        'items.*.age' => 'required|numeric|integer|min:0|max:150',
        'items.*.role' => ['required', 'string', Illuminate\Validation\Rule::in(['admin', 'editor', 'viewer'])],
    ])->validate();
    $nativeElapsed = microtime(true) - $nativeStart;

    // RuleSet with compilable fluent rules (no object rules except 'role' which uses in())
    $ruleSetStart = microtime(true);
    RuleSet::from([
        'items' => Rule::array()->required()->each([
            'name' => Rule::string()->required()->min(2)->max(255),
            'email' => Rule::string()->required()->max(255),
            'age' => Rule::numeric()->required()->integer()->min(0)->max(150),
            'role' => Rule::string()->required()->in(['admin', 'editor', 'viewer']),
        ]),
    ])->validate($data);
    $ruleSetElapsed = microtime(true) - $ruleSetStart;

    // With compilation, RuleSet should be within 3x of native Laravel.
    // Without compilation this was ~15x slower.
    expect($ruleSetElapsed)->toBeLessThan($nativeElapsed * 3);
})->group('benchmark');
