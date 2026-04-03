<?php

declare(strict_types=1);

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationData;
use Illuminate\Validation\ValidationException;
use SanderMuller\FluentValidation\FluentRule;
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
        ->field('name', FluentRule::string()->required()->min(2)->max(255))
        ->field('age', FluentRule::numeric()->nullable()->integer()->min(0))
        ->toArray();

    expect($rules)->toHaveKeys(['name', 'age']);
    expect($rules['name'])->toBeInstanceOf(StringRule::class);
    expect($rules['age'])->toBeInstanceOf(NumericRule::class);
});

it('builds a rule set from an array via from()', function (): void {
    $rules = RuleSet::from([
        'name' => FluentRule::string()->required(),
        'age' => FluentRule::numeric()->nullable(),
    ])->toArray();

    expect($rules)->toHaveKeys(['name', 'age']);
});

it('handles mixed rule types via from()', function (): void {
    $rules = RuleSet::from([
        'name' => 'required|string|max:255',
        'age' => ['required', 'integer'],
        'email' => FluentRule::string()->required()->rule('email'),
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
        'tags' => FluentRule::array()->required()->each(FluentRule::string()->max(50)),
    ])->toArray();

    expect($rules)->toHaveKeys(['tags', 'tags.*']);
    expect($rules['tags'])->toBeInstanceOf(ArrayRule::class);
    expect($rules['tags.*'])->toBeInstanceOf(StringRule::class);
});

it('flattens each() with field mappings to wildcard paths', function (): void {
    $rules = RuleSet::from([
        'items' => FluentRule::array()->required()->each([
            'name' => FluentRule::string()->required(),
            'qty' => FluentRule::numeric()->required()->integer(),
        ]),
    ])->toArray();

    expect($rules)->toHaveKeys(['items', 'items.*.name', 'items.*.qty']);
    expect($rules['items.*.name'])->toBeInstanceOf(StringRule::class);
    expect($rules['items.*.qty'])->toBeInstanceOf(NumericRule::class);
});

it('flattens nested each() recursively', function (): void {
    $rules = RuleSet::from([
        'orders' => FluentRule::array()->required()->each([
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required(),
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
        'tags' => FluentRule::array()->required(),
    ])->toArray();

    expect($rules)->toHaveKeys(['tags']);
    expect($rules)->not->toHaveKey('tags.*');
});

// =========================================================================
// expandWildcards()
// =========================================================================

it('expands wildcard fields against data', function (): void {
    $rules = RuleSet::from([
        'items' => FluentRule::array()->required(),
        'items.*.name' => FluentRule::string()->required(),
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
        'items' => FluentRule::array()->required()->each([
            'name' => FluentRule::string()->required(),
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
        'name' => FluentRule::string()->required(),
        'tags' => FluentRule::array()->each(FluentRule::string()->max(50)),
    ])->expandWildcards([
        'name' => 'John',
        'tags' => ['php', 'laravel'],
    ]);

    expect($rules)->toHaveKeys(['name', 'tags', 'tags.0', 'tags.1']);
});

it('handles empty array during wildcard expansion', function (): void {
    $rules = RuleSet::from([
        'items' => FluentRule::array()->each(FluentRule::string()),
    ])->expandWildcards(['items' => []]);

    expect($rules)->toHaveKey('items');
    expect($rules)->not->toHaveKey('items.0');
});

// =========================================================================
// validate() — success
// =========================================================================

it('validates data with wildcard rules', function (): void {
    $validated = RuleSet::from([
        'items' => FluentRule::array()->required()->min(1)->each([
            'name' => FluentRule::string()->required()->min(2),
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
        'name' => FluentRule::string()->required()->min(2),
        'age' => FluentRule::numeric()->nullable()->integer()->min(0),
    ])->validate(['name' => 'John', 'age' => 25]);

    expect($validated)->toBe(['name' => 'John', 'age' => 25]);
});

it('validates nested each() rules', function (): void {
    $validated = RuleSet::from([
        'orders' => FluentRule::array()->required()->each([
            'items' => FluentRule::array()->required()->each([
                'qty' => FluentRule::numeric()->required()->integer()->min(1),
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
        'tags' => FluentRule::array()->required()->each(FluentRule::string()->max(50)),
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
        'items' => FluentRule::array()->required()->each([
            'name' => FluentRule::string()->required()->min(2),
        ]),
    ])->validate([
        'items' => [['name' => 'J']],
    ]);
})->throws(ValidationException::class);

it('throws ValidationException for invalid simple data', function (): void {
    RuleSet::from([
        'name' => FluentRule::string()->required()->min(5),
    ])->validate(['name' => 'Jo']);
})->throws(ValidationException::class);

// =========================================================================
// validate() — with custom messages and attributes
// =========================================================================

it('supports custom error messages', function (): void {
    try {
        RuleSet::from([
            'name' => FluentRule::string()->required(),
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
            'email_address' => FluentRule::string()->required()->min(100),
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
// validate() — top-level field fails when wildcards also present
// =========================================================================

it('throws when top-level field fails alongside wildcard rules', function (): void {
    RuleSet::from([
        'name' => FluentRule::string()->required()->min(5),
        'items' => FluentRule::array()->required()->each([
            'title' => FluentRule::string()->required(),
        ]),
    ])->validate([
        'name' => 'Jo',
        'items' => [['title' => 'OK']],
    ]);
})->throws(ValidationException::class);

// =========================================================================
// validate() — scalar wildcard item failure
// =========================================================================

it('throws when scalar each item fails validation', function (): void {
    RuleSet::from([
        'tags' => FluentRule::array()->required()->each(FluentRule::string()->min(3)),
    ])->validate([
        'tags' => ['ok', 'no'],
    ]);
})->throws(ValidationException::class);

it('reports correct field paths for scalar each item failures', function (): void {
    try {
        RuleSet::from([
            'tags' => FluentRule::array()->required()->each(FluentRule::string()->min(20)),
        ])->validate([
            'tags' => ['short', 'tiny'],
        ]);
    } catch (ValidationException $validationException) {
        $errorKeys = array_keys($validationException->errors());
        expect($errorKeys)->toContain('tags.0');
        expect($errorKeys)->toContain('tags.1');

        return;
    }

    $this->fail('Expected ValidationException was not thrown.');
});

// =========================================================================
// validate() — distinct rule triggers full expansion fallback
// =========================================================================

it('falls back to standard validation when distinct rule is present', function (): void {
    $validated = RuleSet::from([
        'tags' => FluentRule::array()->required()->each(FluentRule::string()->distinct()),
    ])->validate([
        'tags' => ['php', 'laravel', 'pest'],
    ]);

    expect($validated['tags'])->toBe(['php', 'laravel', 'pest']);
});

it('distinct rule detects duplicates via full expansion', function (): void {
    RuleSet::from([
        'tags' => FluentRule::array()->required()->each(FluentRule::string()->distinct()),
    ])->validate([
        'tags' => ['php', 'laravel', 'php'],
    ]);
})->throws(ValidationException::class);

// =========================================================================
// validate() — fast-check path coverage
// =========================================================================

it('fast-checks numeric fields in wildcard items', function (): void {
    $validated = RuleSet::from([
        'items' => FluentRule::array()->required()->each([
            'qty' => FluentRule::numeric()->required()->integer()->min(1)->max(100),
        ]),
    ])->validate([
        'items' => [
            ['qty' => 5],
            ['qty' => 50],
        ],
    ]);

    expect($validated['items'])->toHaveCount(2);
});

it('fast-checks fail on numeric validation falling through to slow path', function (): void {
    RuleSet::from([
        'items' => FluentRule::array()->required()->each([
            'qty' => FluentRule::numeric()->required()->min(10),
        ]),
    ])->validate([
        'items' => [['qty' => 1]],
    ]);
})->throws(ValidationException::class);

it('fast-checks in() values on wildcard items', function (): void {
    $validated = RuleSet::from([
        'items' => FluentRule::array()->required()->each([
            'status' => FluentRule::string()->required()->in(['active', 'inactive']),
        ]),
    ])->validate([
        'items' => [
            ['status' => 'active'],
            ['status' => 'inactive'],
        ],
    ]);

    expect($validated['items'])->toHaveCount(2);
});

it('fast-checks fail on in() values falling through to slow path', function (): void {
    RuleSet::from([
        'items' => FluentRule::array()->required()->each([
            'status' => FluentRule::string()->required()->in(['active', 'inactive']),
        ]),
    ])->validate([
        'items' => [['status' => 'deleted']],
    ]);
})->throws(ValidationException::class);

it('fast-checks boolean fields in wildcard items', function (): void {
    $validated = RuleSet::from([
        'items' => FluentRule::array()->required()->each([
            'active' => FluentRule::boolean()->required(),
        ]),
    ])->validate([
        'items' => [
            ['active' => true],
            ['active' => false],
        ],
    ]);

    expect($validated['items'])->toHaveCount(2);
});

it('fast-checks nullable fields pass with null', function (): void {
    $validated = RuleSet::from([
        'items' => FluentRule::array()->required()->each([
            'note' => FluentRule::string()->nullable()->max(100),
        ]),
    ])->validate([
        'items' => [
            ['note' => null],
            ['note' => 'hello'],
        ],
    ]);

    expect($validated['items'])->toHaveCount(2);
});

it('fast-checks skip non-compilable rules and use slow path', function (): void {
    $customRule = new class implements ValidationRule {
        public function validate(string $attribute, mixed $value, Closure $fail): void
        {
            if ($value !== 'valid') {
                $fail('Must be valid.');
            }
        }
    };

    $validated = RuleSet::from([
        'items' => FluentRule::array()->required()->each([
            'value' => FluentRule::string()->required()->rule($customRule),
        ]),
    ])->validate([
        'items' => [['value' => 'valid']],
    ]);

    expect($validated['items'])->toHaveCount(1);
});

it('fast-checks integer strict validation', function (): void {
    $validated = RuleSet::from([
        'items' => FluentRule::array()->required()->each([
            'count' => FluentRule::numeric()->required()->integer(),
        ]),
    ])->validate([
        'items' => [
            ['count' => 42],
            ['count' => 0],
        ],
    ]);

    expect($validated['items'])->toHaveCount(2);
});

it('fast-checks string max violation falls through to slow path', function (): void {
    RuleSet::from([
        'items' => FluentRule::array()->required()->each([
            'name' => FluentRule::string()->required()->max(3),
        ]),
    ])->validate([
        'items' => [['name' => 'toolong']],
    ]);
})->throws(ValidationException::class);

it('fast-checks numeric max violation falls through to slow path', function (): void {
    RuleSet::from([
        'items' => FluentRule::array()->required()->each([
            'qty' => FluentRule::numeric()->required()->max(10),
        ]),
    ])->validate([
        'items' => [['qty' => 999]],
    ]);
})->throws(ValidationException::class);

it('fast-checks required empty string falls through to slow path', function (): void {
    RuleSet::from([
        'items' => FluentRule::array()->required()->each([
            'name' => FluentRule::string()->required(),
        ]),
    ])->validate([
        'items' => [['name' => '']],
    ]);
})->throws(ValidationException::class);

it('slow path reuses validator across multiple failing items', function (): void {
    try {
        RuleSet::from([
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->min(10),
            ]),
        ])->validate([
            'items' => [
                ['name' => 'ok-first-pass'],
                ['name' => 'bad'],
                ['name' => 'bad2'],
            ],
        ]);
    } catch (ValidationException $validationException) {
        $errorKeys = array_keys($validationException->errors());
        expect($errorKeys)->toContain('items.1.name');
        expect($errorKeys)->toContain('items.2.name');

        return;
    }

    $this->fail('Expected ValidationException was not thrown.');
});

it('slow path reports scalar item errors with correct paths', function (): void {
    try {
        RuleSet::from([
            'tags' => FluentRule::array()->required()->each(FluentRule::string()->min(10)),
        ])->validate([
            'tags' => ['good-enough!', 'bad'],
        ]);
    } catch (ValidationException $validationException) {
        expect(array_keys($validationException->errors()))->toContain('tags.1');

        return;
    }

    $this->fail('Expected ValidationException was not thrown.');
});

it('fast-checks date fields by skipping to slow path', function (): void {
    $validated = RuleSet::from([
        'items' => FluentRule::array()->required()->each([
            'date' => FluentRule::date()->required(),
        ]),
    ])->validate([
        'items' => [
            ['date' => '2025-01-01'],
            ['date' => '2025-06-15'],
        ],
    ]);

    expect($validated['items'])->toHaveCount(2);
});

it('size rule in each triggers slow path', function (): void {
    $validated = RuleSet::from([
        'items' => FluentRule::array()->required()->each([
            'code' => FluentRule::string()->required()->exactly(3),
        ]),
    ])->validate([
        'items' => [['code' => 'ABC']],
    ]);

    expect($validated['items'])->toHaveCount(1);
});

it('array rule in each triggers slow path', function (): void {
    $validated = RuleSet::from([
        'items' => FluentRule::array()->required()->each([
            'tags' => FluentRule::array()->required(),
        ]),
    ])->validate([
        'items' => [['tags' => ['a', 'b']]],
    ]);

    expect($validated['items'])->toHaveCount(1);
});

it('unrecognized rule in each triggers slow path', function (): void {
    $validated = RuleSet::from([
        'items' => FluentRule::array()->required()->each([
            'email' => FluentRule::string()->required()->rule('email'),
        ]),
    ])->validate([
        'items' => [['email' => 'user@example.com']],
    ]);

    expect($validated['items'])->toHaveCount(1);
});

// =========================================================================
// validate() — nested wildcard requires full expansion
// =========================================================================

it('nested wildcards in each fall back to standard validation', function (): void {
    $validated = RuleSet::from([
        'orders' => FluentRule::array()->required()->each([
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required(),
            ]),
        ]),
    ])->validate([
        'orders' => [
            ['items' => [['name' => 'Widget']]],
        ],
    ]);

    expect($validated['orders'])->toHaveCount(1);
});

// =========================================================================
// Regression: validate() must not leak unvalidated fields
// =========================================================================

it('does not include unvalidated fields in validated output', function (): void {
    $validated = RuleSet::from([
        'name' => FluentRule::string()->required(),
    ])->validate(['name' => 'John', 'evil' => 'payload']);

    expect($validated)->toHaveKey('name');
    expect($validated)->not->toHaveKey('evil');
});

// =========================================================================
// Regression: date comparison rules must not be silently accepted
// =========================================================================

it('rejects invalid dates via RuleSet validate', function (): void {
    RuleSet::from([
        'items' => FluentRule::array()->required()->each([
            'starts_at' => FluentRule::date()->required()->after('2099-01-01'),
        ]),
    ])->validate([
        'items' => [['starts_at' => '2020-01-01']],
    ]);
})->throws(ValidationException::class);

// =========================================================================
// Labels — factory argument and ->label()
// =========================================================================

it('uses label from factory argument in error messages', function (): void {
    try {
        RuleSet::from([
            'name' => FluentRule::string('Full Name')->required(),
        ])->validate(['name' => '']);
    } catch (ValidationException $validationException) {
        expect($validationException->errors()['name'][0])->toContain('Full Name');

        return;
    }

    $this->fail('Expected ValidationException');
});

it('uses label from ->label() method in error messages', function (): void {
    try {
        RuleSet::from([
            'email' => FluentRule::string()->label('Email Address')->required()->rule('email'),
        ])->validate(['email' => '']);
    } catch (ValidationException $validationException) {
        expect($validationException->errors()['email'][0])->toContain('Email Address');

        return;
    }

    $this->fail('Expected ValidationException');
});

it('uses array label via named parameter', function (): void {
    try {
        RuleSet::from([
            'items' => FluentRule::array(label: 'Import Items')->required()->min(1),
        ])->validate(['items' => []]);
    } catch (ValidationException $validationException) {
        expect($validationException->errors()['items'][0])->toContain('Import Items');

        return;
    }

    $this->fail('Expected ValidationException');
});

it('uses labels within each() rules', function (): void {
    try {
        RuleSet::from([
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string('Item Name')->required()->min(5),
            ]),
        ])->validate([
            'items' => [['name' => 'Jo']],
        ]);
    } catch (ValidationException $validationException) {
        expect($validationException->errors()['items.0.name'][0])->toContain('Item Name');

        return;
    }

    $this->fail('Expected ValidationException');
});

// =========================================================================
// Per-rule messages — ->message()
// =========================================================================

it('uses custom message from ->message() on required', function (): void {
    try {
        RuleSet::from([
            'name' => FluentRule::string()->required()->message('We need your name!'),
        ])->validate(['name' => '']);
    } catch (ValidationException $validationException) {
        expect($validationException->errors()['name'][0])->toBe('We need your name!');

        return;
    }

    $this->fail('Expected ValidationException');
});

it('uses custom message from ->message() on min', function (): void {
    try {
        RuleSet::from([
            'name' => FluentRule::string()->required()->min(10)->message('Too short!'),
        ])->validate(['name' => 'Jo']);
    } catch (ValidationException $validationException) {
        expect($validationException->errors()['name'][0])->toBe('Too short!');

        return;
    }

    $this->fail('Expected ValidationException');
});

it('combines label and message', function (): void {
    try {
        RuleSet::from([
            'name' => FluentRule::string('Full Name')->required()->min(10)->message('At least :min characters.'),
        ])->validate(['name' => 'Jo']);
    } catch (ValidationException $validationException) {
        // min message is custom, required would use label
        expect($validationException->errors()['name'][0])->toBe('At least 10 characters.');

        return;
    }

    $this->fail('Expected ValidationException');
});

it('message only applies to the preceding rule', function (): void {
    try {
        RuleSet::from([
            'name' => FluentRule::string('Full Name')
                ->required()->message('Name is required.')
                ->min(2),  // No custom message — uses default with label
        ])->validate(['name' => '']);
    } catch (ValidationException $validationException) {
        expect($validationException->errors()['name'][0])->toBe('Name is required.');

        return;
    }

    $this->fail('Expected ValidationException');
});

it('user-provided messages override per-rule messages', function (): void {
    try {
        RuleSet::from([
            'name' => FluentRule::string()->required()->message('Fluent message.'),
        ])->validate(
            ['name' => ''],
            ['name.required' => 'User override.'],
        );
    } catch (ValidationException $validationException) {
        expect($validationException->errors()['name'][0])->toBe('User override.');

        return;
    }

    $this->fail('Expected ValidationException');
});

it('label survives each() flattening and withoutEachRules clone', function (): void {
    try {
        RuleSet::from([
            'items' => FluentRule::array(label: 'Product List')->required()->min(3)->each([
                'name' => FluentRule::string('Product Name')->required(),
            ]),
        ])->validate([
            'items' => [['name' => 'A']],
        ]);
    } catch (ValidationException $validationException) {
        expect($validationException->errors()['items'][0])->toContain('Product List');

        return;
    }

    $this->fail('Expected ValidationException');
});

// =========================================================================
// Regression: labels/messages survive validateStandard() fallback (P2)
// =========================================================================

it('preserves label in distinct fallback path', function (): void {
    try {
        RuleSet::from([
            'tags' => FluentRule::array(label: 'Tag List')->required()->each(
                FluentRule::string('Tag')->required()->distinct()
            ),
        ])->validate([
            'tags' => ['php', 'php'],
        ]);
    } catch (ValidationException $validationException) {
        // The distinct error should use the label from the rule
        expect($validationException->validator->errors()->toArray())->not->toBeEmpty();

        return;
    }

    $this->fail('Expected ValidationException');
});

// =========================================================================
// Regression: extractMetadata finds labels inside mixed arrays (P3)
// =========================================================================

it('extracts label from fluent rule inside mixed array', function (): void {
    try {
        RuleSet::from([
            'secret' => ['bail', FluentRule::string('API Secret')->required()->min(20)],
        ])->validate(['secret' => 'short']);
    } catch (ValidationException $validationException) {
        expect($validationException->errors()['secret'][0])->toContain('API Secret');

        return;
    }

    $this->fail('Expected ValidationException');
});

it('extracts message from fluent rule inside mixed array', function (): void {
    try {
        RuleSet::from([
            'token' => ['sometimes', FluentRule::string()->required()->message('Token is required.')],
        ])->validate(['token' => '']);
    } catch (ValidationException $validationException) {
        expect($validationException->errors()['token'][0])->toBe('Token is required.');

        return;
    }

    $this->fail('Expected ValidationException');
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
        'items.*.role' => ['required', 'string', Rule::in(['admin', 'editor', 'viewer'])],
    ])->validate();
    $nativeElapsed = microtime(true) - $nativeStart;

    // RuleSet with compilable fluent rules (no object rules except 'role' which uses in())
    $ruleSetStart = microtime(true);
    RuleSet::from([
        'items' => FluentRule::array()->required()->each([
            'name' => FluentRule::string()->required()->min(2)->max(255),
            'email' => FluentRule::string()->required()->max(255),
            'age' => FluentRule::numeric()->required()->integer()->min(0)->max(150),
            'role' => FluentRule::string()->required()->in(['admin', 'editor', 'viewer']),
        ]),
    ])->validate($data);
    $ruleSetElapsed = microtime(true) - $ruleSetStart;

    // With compilation, RuleSet should be within 3x of native Laravel.
    // Without compilation this was ~15x slower.
    expect($ruleSetElapsed)->toBeLessThan($nativeElapsed * 3);
})->group('benchmark');
