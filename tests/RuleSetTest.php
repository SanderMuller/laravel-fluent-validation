<?php

declare(strict_types=1);

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationData;
use Illuminate\Validation\ValidationException;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\FluentValidator;
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
// children() — fixed-key child rules
// =========================================================================

it('flattens children() to fixed paths', function (): void {
    $rules = RuleSet::from([
        'search' => FluentRule::array()->required()->children([
            'value' => FluentRule::string()->nullable(),
            'regex' => FluentRule::string()->nullable()->in(['true', 'false']),
        ]),
    ])->toArray();

    expect($rules)->toHaveKeys(['search', 'search.value', 'search.regex']);
    expect($rules)->not->toHaveKey('search.*.value');
});

it('validates children() rules', function (): void {
    $validated = RuleSet::from([
        'search' => FluentRule::array()->required()->children([
            'value' => FluentRule::string()->nullable(),
            'regex' => FluentRule::string()->nullable()->in(['true', 'false']),
        ]),
    ])->validate([
        'search' => ['value' => 'test', 'regex' => 'true'],
    ]);

    expect($validated['search']['value'])->toBe('test');
});

it('fails validation for invalid children()', function (): void {
    RuleSet::from([
        'search' => FluentRule::array()->required()->children([
            'value' => FluentRule::string()->required()->min(5),
        ]),
    ])->validate([
        'search' => ['value' => 'hi'],
    ]);
})->throws(ValidationException::class);

it('supports nested children()', function (): void {
    $rules = RuleSet::from([
        'config' => FluentRule::array()->required()->children([
            'db' => FluentRule::array()->required()->children([
                'host' => FluentRule::string()->required(),
                'port' => FluentRule::numeric()->required()->integer(),
            ]),
        ]),
    ])->toArray();

    expect($rules)->toHaveKeys(['config', 'config.db', 'config.db.host', 'config.db.port']);
});

it('combines each() and children() on the same parent', function (): void {
    $rules = RuleSet::from([
        'data' => FluentRule::array()->required()
            ->children([
                'meta' => FluentRule::string()->nullable(),
            ])
            ->each([
                'name' => FluentRule::string()->required(),
            ]),
    ])->toArray();

    expect($rules)->toHaveKeys(['data', 'data.meta', 'data.*.name']);
});

// =========================================================================
// when() / unless() — conditional field groups
// =========================================================================

it('adds fields conditionally with when()', function (): void {
    $rules = RuleSet::make()
        ->field('name', FluentRule::string()->required())
        ->when(true, fn (RuleSet $ruleSet) => $ruleSet
            ->field('role', FluentRule::string()->required())
        )
        ->toArray();

    expect($rules)->toHaveKeys(['name', 'role']);
});

it('skips fields when condition is false', function (): void {
    $rules = RuleSet::make()
        ->field('name', FluentRule::string()->required())
        ->when(false, fn (RuleSet $ruleSet) => $ruleSet
            ->field('role', FluentRule::string()->required())
        )
        ->toArray();

    expect($rules)->toHaveKey('name');
    expect($rules)->not->toHaveKey('role');
});

it('supports unless()', function (): void {
    $rules = RuleSet::make()
        ->field('name', FluentRule::string()->required())
        ->unless(true, fn (RuleSet $ruleSet) => $ruleSet
            ->field('role', FluentRule::string()->required())
        )
        ->toArray();

    expect($rules)->not->toHaveKey('role');
});

// =========================================================================
// merge()
// =========================================================================

it('merges another RuleSet', function (): void {
    $ruleSet = RuleSet::from(['name' => FluentRule::string()->required()]);
    $extra = RuleSet::from(['email' => FluentRule::email()->required()]);

    $rules = $ruleSet->merge($extra)->toArray();

    expect($rules)->toHaveKeys(['name', 'email']);
});

it('merges a plain array', function (): void {
    $rules = RuleSet::from(['name' => FluentRule::string()->required()])
        ->merge(['age' => 'required|integer'])
        ->toArray();

    expect($rules)->toHaveKeys(['name', 'age']);
    expect($rules['age'])->toBe('required|integer');
});

it('later merge overwrites earlier fields', function (): void {
    $rules = RuleSet::from(['name' => FluentRule::string()->max(100)])
        ->merge(['name' => FluentRule::string()->max(255)])
        ->toArray();

    expect($rules['name']->compiledRules())->toContain('max:255');
});

// =========================================================================
// FluentRule::field() — untyped entry point
// =========================================================================

it('creates an untyped field rule', function (): void {
    $validator = makeValidator(
        ['answer' => 'yes'],
        ['answer' => FluentRule::field()->present()]
    );

    expect($validator->passes())->toBeTrue();
});

it('field rule fails when not present', function (): void {
    $validator = makeValidator(
        [],
        ['answer' => FluentRule::field()->present()]
    );

    expect($validator->passes())->toBeFalse();
});

it('field rule with label', function (): void {
    $validator = makeValidator(
        [],
        ['answer' => FluentRule::field('Your Answer')->required()]
    );

    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->first('answer'))->toContain('Your Answer');
});

it('field rule with in() and no type constraint', function (): void {
    $validator = makeValidator(
        ['answer' => 'yes'],
        ['answer' => FluentRule::field()->required()->in(['yes', 'no'])]
    );

    expect($validator->passes())->toBeTrue();
});

// =========================================================================
// FluentRule::field() with children()
// =========================================================================

it('field rule supports children() for fixed-key child rules', function (): void {
    $rules = RuleSet::from([
        'answer' => FluentRule::field()->present()->children([
            'email' => FluentRule::email()->required(),
        ]),
    ])->toArray();

    expect($rules)->toHaveKeys(['answer', 'answer.email']);
});

it('validates field rule children() via RuleSet', function (): void {
    $validated = RuleSet::from([
        'answer' => FluentRule::field()->present()->children([
            'email' => FluentRule::string()->required()->rule('email'),
        ]),
    ])->validate([
        'answer' => ['email' => 'test@example.com'],
    ]);

    expect($validated)->toHaveKey('answer');
});

it('fails validation for invalid field rule children()', function (): void {
    RuleSet::from([
        'answer' => FluentRule::field()->present()->children([
            'email' => FluentRule::string()->required()->min(100),
        ]),
    ])->validate([
        'answer' => ['email' => 'short'],
    ]);
})->throws(ValidationException::class);

// =========================================================================
// ->rule() with array tuples
// =========================================================================

it('accepts array tuple in rule()', function (): void {
    $validator = makeValidator(
        ['role' => 'admin'],
        ['role' => FluentRule::string()->required()->rule(['in', 'admin', 'user'])]
    );

    expect($validator->passes())->toBeTrue();
});

it('rejects invalid value with array tuple in rule()', function (): void {
    $validator = makeValidator(
        ['role' => 'hacker'],
        ['role' => FluentRule::string()->required()->rule(['in', 'admin', 'user'])]
    );

    expect($validator->passes())->toBeFalse();
});

it('handles parameterless array tuple in rule()', function (): void {
    $validator = makeValidator(
        [],
        ['name' => FluentRule::string()->rule(['required'])]
    );

    expect($validator->passes())->toBeFalse();
});

// =========================================================================
// in() / notIn() with enum class
// =========================================================================

it('accepts enum class in in() via RuleSet', function (): void {
    $validated = RuleSet::from([
        'status' => FluentRule::string()->required()->in(TestStringEnum::class),
    ])->validate(['status' => 'active']);

    expect($validated['status'])->toBe('active');
});

it('rejects invalid enum value in in() via RuleSet', function (): void {
    RuleSet::from([
        'status' => FluentRule::string()->required()->in(TestStringEnum::class),
    ])->validate(['status' => 'deleted']);
})->throws(ValidationException::class);

// =========================================================================
// prepare() — single-call pipeline for custom Validators
// =========================================================================

it('prepare returns compiled rules with metadata', function (): void {
    $preparedRules = RuleSet::from([
        'name' => FluentRule::string('Full Name')->required()->message('Name is required.')->min(2),
        'items' => FluentRule::array()->required()->each([
            'qty' => FluentRule::numeric('Quantity')->required()->integer()->min(1),
        ]),
    ])->prepare([
        'items' => [['qty' => 1], ['qty' => 2]],
    ]);

    // Rules are compiled (strings, not objects)
    expect($preparedRules->rules['name'])->toBeString();

    // Labels extracted
    expect($preparedRules->attributes['name'])->toBe('Full Name');

    // Messages extracted
    expect($preparedRules->messages['name.required'])->toBe('Name is required.');

    // Implicit attributes for wildcard mapping
    expect($preparedRules->implicitAttributes)->toHaveKey('items.*.qty');
});

it('prepare works with a real Validator', function (): void {
    $preparedRules = RuleSet::from([
        'name' => FluentRule::string('Full Name')->required()->min(5),
    ])->prepare([]);

    $validator = Validator::make(
        ['name' => 'Jo'],
        $preparedRules->rules,
        $preparedRules->messages,
        $preparedRules->attributes,
    );

    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->first('name'))->toContain('Full Name');
});

it('prepare returns implicitAttributes for wildcard rules', function (): void {
    $prepared = RuleSet::from([
        'items' => FluentRule::array()->required()->each([
            'name' => FluentRule::string()->required(),
        ]),
    ])->prepare([
        'items' => [['name' => 'a'], ['name' => 'b'], ['name' => 'c']],
    ]);

    expect($prepared->implicitAttributes)->toHaveKey('items.*.name');
    expect($prepared->implicitAttributes['items.*.name'])->toHaveCount(3);
    expect($prepared->implicitAttributes['items.*.name'])->toBe(['items.0.name', 'items.1.name', 'items.2.name']);
});

it('prepare implicitAttributes enables correct validation when applied', function (): void {
    $prepared = RuleSet::from([
        'items' => FluentRule::array()->required()->each([
            'id' => FluentRule::numeric()->required()->distinct(),
        ]),
    ])->prepare([
        'items' => [['id' => 1], ['id' => 1]],
    ]);

    $validator = Validator::make(
        ['items' => [['id' => 1], ['id' => 1]]],
        $prepared->rules,
        $prepared->messages,
        $prepared->attributes,
    );

    // Apply implicit attributes (needed for distinct to work across items)
    if ($prepared->implicitAttributes !== []) {
        (new ReflectionProperty($validator, 'implicitAttributes'))
            ->setValue($validator, $prepared->implicitAttributes);
    }

    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->keys())->toContain('items.1.id');
});

it('prepare returns empty implicitAttributes for non-wildcard rules', function (): void {
    $prepared = RuleSet::from([
        'name' => FluentRule::string()->required(),
        'email' => FluentRule::email()->required(),
    ])->prepare([]);

    expect($prepared->implicitAttributes)->toBe([]);
});

it('prepare extracts fieldMessage as field-level fallback', function (): void {
    $prepared = RuleSet::from([
        'name' => FluentRule::string()->required()->min(10)->fieldMessage('Check the name.'),
    ])->prepare([]);

    expect($prepared->messages)->toHaveKey('name');
    expect($prepared->messages['name'])->toBe('Check the name.');
});

// =========================================================================
// FluentValidator base class
// =========================================================================

it('FluentValidator validates with compiled rules and labels', function (): void {
    $validator = new class (['name' => 'Jo'], [
        'name' => FluentRule::string('Full Name')->required()->min(5),
    ]) extends FluentValidator {};

    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->first('name'))->toContain('Full Name');
});

it('FluentValidator passes valid data', function (): void {
    $validator = new class (['name' => 'John Doe', 'age' => 25], [
        'name' => FluentRule::string()->required()->min(2),
        'age' => FluentRule::numeric()->required()->integer()->min(0),
    ]) extends FluentValidator {};

    expect($validator->passes())->toBeTrue();
});

it('FluentValidator expands each() rules', function (): void {
    $validator = new class (
        ['items' => [['name' => 'a'], ['name' => 'b']]],
        [
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->min(2),
            ]),
        ]
    ) extends FluentValidator {};

    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->keys())->toContain('items.0.name');
});

it('FluentValidator merges custom messages with rule messages', function (): void {
    $validator = new class (
        ['name' => ''],
        ['name' => FluentRule::string()->required()->message('Fluent message.')],
        ['name.required' => 'Custom override.'],
    ) extends FluentValidator {};

    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->first('name'))->toBe('Custom override.');
});

it('FluentValidator handles cross-field wildcard references', function (): void {
    // Simulates hihaho's JsonInteractionImportValidator pattern:
    // requiredUnless('*.type', ...) references a sibling field via wildcard
    $validator = new class (
        ['items' => [
            ['type' => 'chapter', 'title' => 'Hello', 'end_time' => 10],
            ['type' => 'menu'],  // menu doesn't require end_time
        ]],
        [
            'items' => 'required|array',
            'items.*.type' => ['required', 'string', Rule::in(['chapter', 'menu', 'button'])],
            'items.*.title' => ['nullable', 'string'],
            'items.*.end_time' => [
                ['required_unless', 'items.*.type', 'menu'],
                'numeric',
            ],
        ]
    ) extends FluentValidator {};

    expect($validator->passes())->toBeTrue();
});

it('FluentValidator fails cross-field wildcard references correctly', function (): void {
    $validator = new class (
        ['items' => [
            ['type' => 'chapter'],  // chapter requires end_time but it's missing
        ]],
        [
            'items' => 'required|array',
            'items.*.type' => ['required', 'string'],
            'items.*.end_time' => [
                ['required_unless', 'items.*.type', 'menu'],
                'numeric',
            ],
        ]
    ) extends FluentValidator {};

    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->keys())->toContain('items.0.end_time');
});

it('HasFluentRules handles cross-field wildcard references', function (): void {
    $formRequest = createFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->each([
                'type' => FluentRule::string()->required()->in(['chapter', 'menu']),
                'end_time' => FluentRule::numeric()
                    ->requiredUnless('items.*.type', 'menu'),
            ]),
        ],
        data: [
            'items' => [
                ['type' => 'menu'],  // menu doesn't require end_time
            ],
        ],
    );

    $factory = app(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    expect($validator->passes())->toBeTrue();
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

// =========================================================================
// ArrayRule compiledRules — must include 'array' type
// =========================================================================

it('ArrayRule compiledRules includes array type', function (): void {
    expect(FluentRule::array()->compiledRules())->toBe('array');
    expect(FluentRule::array()->nullable()->compiledRules())->toBe('array|nullable');
    expect(FluentRule::array()->required()->compiledRules())->toBe('array|required');
    expect(FluentRule::array()->required()->min(1)->compiledRules())->toBe('array|required|min:1');
});

it('ArrayRule compiledRules includes keys', function (): void {
    expect(FluentRule::array(['name', 'email'])->compiledRules())->toBe('array:name,email');
    expect(FluentRule::array(['name'])->required()->compiledRules())->toBe('array:name|required');
});

it('ArrayRule with each() compiles parent without children', function (): void {
    $rule = FluentRule::array()->required()->each([
        'name' => FluentRule::string()->required(),
    ]);

    // compiledRules() on the parent should not include child rules
    expect($rule->compiledRules())->toBe('array|required');
});

// =========================================================================
// validated() output — children() keys must appear
// =========================================================================

it('validated() includes children keys via HasFluentRules prepare path', function (): void {
    $rules = [
        'search' => FluentRule::array()->required()->children([
            'value' => FluentRule::string()->nullable(),
            'regex' => FluentRule::string()->nullable(),
        ]),
    ];

    $data = [
        'search' => ['value' => 'foo', 'regex' => 'bar'],
    ];

    $prepared = RuleSet::from($rules)->prepare($data);

    // All keys should be present in compiled rules
    expect($prepared->rules)->toHaveKey('search');
    expect($prepared->rules)->toHaveKey('search.value');
    expect($prepared->rules)->toHaveKey('search.regex');

    // The search key should compile with 'array' type
    expect($prepared->rules['search'])->toContain('array');

    // validated() should include all keys
    $validator = Validator::make($data, $prepared->rules);
    expect($validator->passes())->toBeTrue();
    $validated = $validator->validated();
    expect($validated)->toHaveKey('search');
    expect($validated['search'])->toHaveKey('value');
    expect($validated['search'])->toHaveKey('regex');
});

it('validated() includes nested each+children keys via prepare path', function (): void {
    $rules = [
        'answers' => FluentRule::array()->nullable()->each([
            'action' => FluentRule::array()->nullable()->children([
                'type' => FluentRule::numeric()->required()->integer(),
            ]),
        ]),
    ];

    $data = [
        'answers' => [
            ['action' => ['type' => 1]],
            ['action' => ['type' => 2]],
        ],
    ];

    $prepared = RuleSet::from($rules)->prepare($data);

    // All expanded keys should be present
    expect($prepared->rules)->toHaveKey('answers');
    expect($prepared->rules)->toHaveKey('answers.0.action');
    expect($prepared->rules)->toHaveKey('answers.0.action.type');
    expect($prepared->rules)->toHaveKey('answers.1.action');
    expect($prepared->rules)->toHaveKey('answers.1.action.type');

    // Parent keys should include 'array' type
    expect($prepared->rules['answers'])->toContain('array');
    expect($prepared->rules['answers.0.action'])->toContain('array');

    // validated() should include all nested keys
    $validator = Validator::make($data, $prepared->rules);

    if ($prepared->implicitAttributes !== []) {
        (new ReflectionProperty($validator, 'implicitAttributes'))
            ->setValue($validator, $prepared->implicitAttributes);
    }

    expect($validator->passes())->toBeTrue();
    $validated = $validator->validated();
    expect($validated['answers'][0]['action']['type'])->toBe(1);
    expect($validated['answers'][1]['action']['type'])->toBe(2);
});

it('validated() includes children keys in self-validation mode', function (): void {
    $data = [
        'search' => ['value' => 'foo', 'regex' => 'bar'],
    ];

    $validator = makeValidator($data, [
        'search' => FluentRule::array()->required()->children([
            'value' => FluentRule::string()->nullable(),
            'regex' => FluentRule::string()->nullable(),
        ]),
    ]);

    expect($validator->passes())->toBeTrue();
});
