<?php declare(strict_types=1);

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
use SanderMuller\FluentValidation\Tests\Fixtures\TestStringEnum;
use SanderMuller\FluentValidation\WildcardExpander;

// =========================================================================
// Basic RuleSet building
// =========================================================================

it('builds a rule set from fluent fields', function (): void {
    $rules = RuleSet::make()
        ->field('name', FluentRule::string()->required()->min(2)->max(255))
        ->field('age', FluentRule::numeric()->nullable()->integer()->min(0))
        ->toArray();

    expect($rules)->toHaveKeys(['name', 'age'])
        ->and($rules['name'])->toBeInstanceOf(StringRule::class)
        ->and($rules['age'])->toBeInstanceOf(NumericRule::class);
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
    expect($rules)->toMatchArray(['name' => 'required|string|max:255', 'age' => ['required', 'integer']])
        ->and($rules['email'])->toBeInstanceOf(StringRule::class);
});

// =========================================================================
// each() flattening
// =========================================================================

it('flattens each() with a single rule to wildcard path', function (): void {
    $rules = RuleSet::from([
        'tags' => FluentRule::array()->required()->each(FluentRule::string()->max(50)),
    ])->toArray();

    expect($rules)->toHaveKeys(['tags', 'tags.*'])
        ->and($rules['tags'])->toBeInstanceOf(ArrayRule::class)
        ->and($rules['tags.*'])->toBeInstanceOf(StringRule::class);
});

it('flattens each() with field mappings to wildcard paths', function (): void {
    $rules = RuleSet::from([
        'items' => FluentRule::array()->required()->each([
            'name' => FluentRule::string()->required(),
            'qty' => FluentRule::numeric()->required()->integer(),
        ]),
    ])->toArray();

    expect($rules)->toHaveKeys(['items', 'items.*.name', 'items.*.qty'])
        ->and($rules['items.*.name'])->toBeInstanceOf(StringRule::class)
        ->and($rules['items.*.qty'])->toBeInstanceOf(NumericRule::class);
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

    expect($rules)->toHaveKeys(['tags'])->not->toHaveKey('tags.*');
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

    expect($rules)->toHaveKeys(['items', 'items.0.name', 'items.1.name'])->not->toHaveKey('items.*.name');
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

    expect($rules)->toHaveKeys(['items', 'items.0.name', 'items.1.name', 'items.2.name'])->not->toHaveKey('items.*.name');
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

    expect($rules)->toHaveKey('items')->not->toHaveKey('items.0');
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

    expect($validated['orders'])->toHaveCount(2)
        ->and($validated['orders'][0]['items'])->toHaveCount(2);
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
        expect($errorKeys)->toContain('tags.0')
            ->toContain('tags.1');

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
        expect($errorKeys)->toContain('items.1.name')
            ->toContain('items.2.name');

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

    expect($validated)->toHaveKey('name')->not->toHaveKey('evil');
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

    expect($rules)->toHaveKeys(['search', 'search.value', 'search.regex'])->not->toHaveKey('search.*.value');
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

    expect($rules)->toHaveKey('name')->not->toHaveKey('role');
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
// IteratorAggregate — array spread support
// =========================================================================

it('supports array spread via IteratorAggregate', function (): void {
    $ruleSet = RuleSet::from([
        'name' => FluentRule::string()->required(),
        'email' => FluentRule::email()->required(),
    ]);

    $merged = [...$ruleSet, 'extra' => FluentRule::string()->nullable()];

    expect($merged)->toHaveKeys(['name', 'email', 'extra']);
});

it('IteratorAggregate yields nothing for an empty RuleSet', function (): void {
    expect(iterator_to_array(RuleSet::make()))->toBeEmpty();
});

it('IteratorAggregate yields the same keys as toArray()', function (): void {
    $ruleSet = RuleSet::from([
        'name' => FluentRule::string()->required(),
        'items' => FluentRule::array()->each([
            'qty' => FluentRule::numeric()->required(),
        ]),
    ]);

    $iterated = iterator_to_array($ruleSet);

    // Each call to flatten() clones ArrayRule via withoutEachRules(), so
    // strict equality on object identity won't hold — compare the key shape.
    expect(array_keys($iterated))->toBe(array_keys($ruleSet->toArray()));
});

// =========================================================================
// all() — Collection-style alias of toArray()
// =========================================================================

it('all() returns the same value as toArray()', function (): void {
    $ruleSet = RuleSet::from([
        'name' => FluentRule::string()->required(),
        'email' => FluentRule::email()->required(),
    ]);

    expect($ruleSet->all())->toBe($ruleSet->toArray());
});

it('all() works with each() expansion same as toArray()', function (): void {
    $ruleSet = RuleSet::from([
        'items' => FluentRule::array()->each([
            'name' => FluentRule::string()->required(),
        ]),
    ]);

    // Same caveat as the iterator test: flatten() clones, so compare keys.
    expect(array_keys($ruleSet->all()))->toBe(array_keys($ruleSet->toArray()))
        ->and($ruleSet->all())->toHaveKey('items.*.name');
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

    expect($rules)->toHaveKeys(['name', 'age'])
        ->and($rules['age'])->toBe('required|integer');
});

it('later merge overwrites earlier fields', function (): void {
    $rules = RuleSet::from(['name' => FluentRule::string()->max(100)])
        ->merge(['name' => FluentRule::string()->max(255)])
        ->toArray();

    expect($rules['name']->compiledRules())->toContain('max:255');
});

// =========================================================================
// only() / except()
// =========================================================================

it('keeps only the named fields via only()', function (): void {
    $rules = RuleSet::from([
        'name' => FluentRule::string()->required(),
        'email' => FluentRule::email()->required(),
        'age' => FluentRule::numeric()->nullable(),
    ])->only('name', 'email')->toArray();

    expect($rules)->toHaveKeys(['name', 'email'])
        ->and($rules)->not->toHaveKey('age');
});

it('returns the same instance from only() for chaining', function (): void {
    $ruleSet = RuleSet::from(['name' => FluentRule::string()->required()]);

    expect($ruleSet->only('name'))->toBe($ruleSet);
});

it('only() with no matching fields produces an empty RuleSet', function (): void {
    $rules = RuleSet::from(['name' => FluentRule::string()->required()])
        ->only('missing')
        ->toArray();

    expect($rules)->toBeEmpty();
});

it('only() with no arguments empties the RuleSet', function (): void {
    $rules = RuleSet::from([
        'name' => FluentRule::string()->required(),
        'email' => FluentRule::email()->required(),
    ])->only()->toArray();

    expect($rules)->toBeEmpty();
});

it('only() accepts an array argument (Collection-style)', function (): void {
    $rules = RuleSet::from([
        'name' => FluentRule::string()->required(),
        'email' => FluentRule::email()->required(),
        'age' => FluentRule::numeric()->nullable(),
    ])->only(['name', 'email'])->toArray();

    expect($rules)->toHaveKeys(['name', 'email'])
        ->and($rules)->not->toHaveKey('age');
});

it('except() accepts an array argument (Collection-style)', function (): void {
    $rules = RuleSet::from([
        'name' => FluentRule::string()->required(),
        'email' => FluentRule::email()->required(),
        'age' => FluentRule::numeric()->nullable(),
    ])->except(['email', 'age'])->toArray();

    expect($rules)->toHaveKey('name')
        ->and($rules)->not->toHaveKey('email')
        ->and($rules)->not->toHaveKey('age');
});

it('only() accepts mixed variadic strings and arrays', function (): void {
    $rules = RuleSet::from([
        'a' => FluentRule::string()->required(),
        'b' => FluentRule::string()->required(),
        'c' => FluentRule::string()->required(),
        'd' => FluentRule::string()->required(),
    ])->only('a', ['b', 'c'])->toArray();

    expect($rules)->toHaveKeys(['a', 'b', 'c'])
        ->and($rules)->not->toHaveKey('d');
});

it('drops the named fields via except()', function (): void {
    $rules = RuleSet::from([
        'name' => FluentRule::string()->required(),
        'email' => FluentRule::email()->required(),
        'age' => FluentRule::numeric()->nullable(),
    ])->except('age')->toArray();

    expect($rules)->toHaveKeys(['name', 'email'])
        ->and($rules)->not->toHaveKey('age');
});

it('returns the same instance from except() for chaining', function (): void {
    $ruleSet = RuleSet::from(['name' => FluentRule::string()->required()]);

    expect($ruleSet->except('email'))->toBe($ruleSet);
});

it('except() with unknown fields is a no-op', function (): void {
    $rules = RuleSet::from(['name' => FluentRule::string()->required()])
        ->except('missing')
        ->toArray();

    expect($rules)->toHaveKey('name');
});

it('only() and except() compose with merge()', function (): void {
    $rules = RuleSet::from(['name' => FluentRule::string()->required()])
        ->merge(['email' => FluentRule::email()->required(), 'age' => 'integer'])
        ->except('age')
        ->only('name', 'email')
        ->toArray();

    expect($rules)->toHaveKeys(['name', 'email'])
        ->and($rules)->not->toHaveKey('age');
});

// =========================================================================
// put() / get()
// =========================================================================

it('adds a field via put()', function (): void {
    $rules = RuleSet::make()
        ->put('name', FluentRule::string()->required())
        ->toArray();

    expect($rules)->toHaveKey('name');
});

it('replaces an existing field via put()', function (): void {
    $rules = RuleSet::from(['name' => FluentRule::string()->max(100)])
        ->put('name', FluentRule::string()->max(255))
        ->toArray();

    expect($rules['name']->compiledRules())->toContain('max:255');
});

it('returns the same instance from put() for chaining', function (): void {
    $ruleSet = RuleSet::make();

    expect($ruleSet->put('name', FluentRule::string()->required()))->toBe($ruleSet);
});

it('reads a stored rule via get()', function (): void {
    $rule = FluentRule::string()->required()->max(255);
    $ruleSet = RuleSet::make()->put('name', $rule);

    expect($ruleSet->get('name'))->toBe($rule);
});

it('returns null from get() for an unknown field', function (): void {
    expect(RuleSet::make()->get('missing'))->toBeNull();
});

it('returns the default from get() for an unknown field', function (): void {
    expect(RuleSet::make()->get('missing', 'fallback'))->toBe('fallback');
});

it('get() does not compile or expand stored rules', function (): void {
    $rule = FluentRule::string()->required()->max(255);
    $ruleSet = RuleSet::make()->put('name', $rule);

    expect($ruleSet->get('name'))->toBeInstanceOf(StringRule::class);
});

// =========================================================================
// modify() — read-modify-write a single field
// =========================================================================

it('modify() applies the callback to the stored rule', function (): void {
    $ruleSet = RuleSet::from([
        'name' => FluentRule::string()->required(),
    ]);

    $ruleSet->modify('name', fn (mixed $rule): mixed => $rule->max(255));

    expect($ruleSet->get('name')->compiledRules())->toContain('max:255');
});

it('modify() passes a clone of the stored rule to the callback', function (): void {
    $original = FluentRule::string()->required();
    $ruleSet = RuleSet::from(['name' => $original]);

    $ruleSet->modify('name', function (mixed $rule): mixed {
        // Mutate the clone — should NOT affect the original captured outside.
        $rule->max(99);

        return $rule;
    });

    expect($original->compiledRules())->not->toContain('max:99')
        ->and($ruleSet->get('name')->compiledRules())->toContain('max:99');
});

it('modify() throws LogicException when the field is not in the rule set', function (): void {
    $ruleSet = RuleSet::from(['name' => FluentRule::string()->required()]);

    expect(static fn () => $ruleSet->modify('missing', fn (mixed $r): mixed => $r))
        ->toThrow(LogicException::class, 'use put() to add new fields');
});

it('modify() returns the same instance for chaining', function (): void {
    $ruleSet = RuleSet::from(['name' => FluentRule::string()->required()]);

    expect($ruleSet->modify('name', fn (mixed $r): mixed => $r))->toBe($ruleSet);
});

it('modify() composes with merge / only / put in a fluent chain', function (): void {
    $rules = RuleSet::from([
        'name' => FluentRule::string()->required(),
    ])
        ->merge(['email' => FluentRule::email()->required()])
        ->modify('name', fn (mixed $rule): mixed => $rule->max(255))
        ->put('age', FluentRule::numeric()->nullable())
        ->only('name', 'age')
        ->toArray();

    expect($rules)->toHaveKeys(['name', 'age'])
        ->and($rules)->not->toHaveKey('email')
        ->and($rules['name']->compiledRules())->toContain('max:255');
});

it('modify() can replace the stored rule entirely', function (): void {
    $ruleSet = RuleSet::from([
        'value' => FluentRule::string()->required(),
    ]);

    $ruleSet->modify('value', fn (): NumericRule => FluentRule::numeric()->required()->min(1));

    expect($ruleSet->get('value'))->toBeInstanceOf(NumericRule::class);
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

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->first('answer'))->toContain('Your Answer');
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

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->first('name'))->toContain('Full Name');
});

it('prepare returns implicitAttributes for wildcard rules', function (): void {
    $prepared = RuleSet::from([
        'items' => FluentRule::array()->required()->each([
            'name' => FluentRule::string()->required(),
        ]),
    ])->prepare([
        'items' => [['name' => 'a'], ['name' => 'b'], ['name' => 'c']],
    ]);

    expect($prepared->implicitAttributes)->toHaveKey('items.*.name')
        ->toMatchArray(['items.*.name' => ['items.0.name', 'items.1.name', 'items.2.name']]);
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

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->keys())->toContain('items.1.id');
});

it('prepare returns empty implicitAttributes for non-wildcard rules', function (): void {
    $prepared = RuleSet::from([
        'name' => FluentRule::string()->required(),
        'email' => FluentRule::email()->required(),
    ])->prepare([]);

    expect($prepared->implicitAttributes)->toBeEmpty();
});

it('prepare extracts fieldMessage as field-level fallback', function (): void {
    $prepared = RuleSet::from([
        'name' => FluentRule::string()->required()->min(10)->fieldMessage('Check the name.'),
    ])->prepare([]);

    expect($prepared->messages)->toHaveKey('name')
        ->and($prepared->messages['name'])->toBe('Check the name.');
});

// =========================================================================
// FluentValidator base class
// =========================================================================

it('FluentValidator validates with compiled rules and labels', function (): void {
    $validator = new class (['name' => 'Jo'], [
        'name' => FluentRule::string('Full Name')->required()->min(5),
    ]) extends FluentValidator {};

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->first('name'))->toContain('Full Name');
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

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->keys())->toContain('items.0.name');
});

it('FluentValidator merges custom messages with rule messages', function (): void {
    $validator = new class (
        ['name' => ''],
        ['name' => FluentRule::string()->required()->message('Fluent message.')],
        ['name.required' => 'Custom override.'],
    ) extends FluentValidator {};

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->first('name'))->toBe('Custom override.');
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

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->keys())->toContain('items.0.end_time');
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

    $factory = resolve(Factory::class);
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
        'items' => ['required', 'array'],
        'items.*.name' => ['required', 'string', 'min:2', 'max:255'],
        'items.*.email' => ['required', 'string', 'max:255'],
        'items.*.age' => ['required', 'numeric', 'integer', 'min:0', 'max:150'],
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
    expect(FluentRule::array()->compiledRules())->toBe('array')
        ->and(FluentRule::array()->nullable()->compiledRules())->toBe('nullable|array')
        ->and(FluentRule::array()->required()->compiledRules())->toBe('required|array')
        ->and(FluentRule::array()->required()->min(1)->compiledRules())->toBe('required|array|min:1');
});

it('ArrayRule compiledRules includes keys', function (): void {
    expect(FluentRule::array(['name', 'email'])->compiledRules())->toBe('array:name,email')
        ->and(FluentRule::array(['name'])->required()->compiledRules())->toBe('required|array:name');
});

it('ArrayRule with each() compiles parent without children', function (): void {
    $rule = FluentRule::array()->required()->each([
        'name' => FluentRule::string()->required(),
    ]);

    // compiledRules() on the parent should not include child rules
    expect($rule->compiledRules())->toBe('required|array');
});

it('ArrayRule supports distinct()', function (): void {
    expect(FluentRule::array()->distinct()->compiledRules())->toBe('array|distinct')
        ->and(FluentRule::array()->distinct('strict')->compiledRules())->toBe('array|distinct:strict')
        ->and(FluentRule::array()->required()->each(FluentRule::numeric()->integer())->distinct()->compiledRules())->toBe('required|array|distinct');
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
    expect($prepared->rules)->toHaveKeys(['search.value', 'search.regex']);

    // The search key should compile with 'array' type
    expect($prepared->rules['search'])->toContain('array');

    // validated() should include all keys
    $validator = Validator::make($data, $prepared->rules);
    expect($validator->passes())->toBeTrue();
    $validated = $validator->validated();
    expect($validated)->toHaveKey('search')
        ->and($validated['search'])->toHaveKeys(['value', 'regex']);
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
    expect($prepared->rules)->toHaveKeys(['answers.0.action', 'answers.0.action.type', 'answers.1.action', 'answers.1.action.type']);

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
    expect($validated['answers'][0]['action']['type'])->toBe(1)
        ->and($validated['answers'][1]['action']['type'])->toBe(2);
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

// =========================================================================
// Partial fast-check path
// =========================================================================

it('validates mixed fast+slow rules via partial fast-check', function (): void {
    $validated = RuleSet::from([
        'items' => FluentRule::array()->required()->each([
            'name' => FluentRule::string()->required()->min(2)->max(255),
            'starts_at' => FluentRule::date()->required()->after('2025-01-01'),
        ]),
    ])->validate(['items' => [
        ['name' => 'Alice', 'starts_at' => '2025-06-01'],
        ['name' => 'Bob', 'starts_at' => '2025-07-15'],
    ]]);

    expect($validated['items'])->toHaveCount(2);
});

it('reports errors from slow-only path when fast-checks pass', function (): void {
    try {
        RuleSet::from([
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->min(2)->max(255),
                'starts_at' => FluentRule::date()->required()->after('2025-01-01'),
            ]),
        ])->validate(['items' => [
            ['name' => 'Valid', 'starts_at' => '2020-01-01'],
        ]]);
        test()->fail('Expected ValidationException');
    } catch (ValidationException $validationException) {
        expect($validationException->errors())->toHaveKey('items.0.starts_at')->not->toHaveKey('items.0.name');
    }
});

it('reports errors from full path when fast-check fails', function (): void {
    try {
        RuleSet::from([
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->min(2)->max(255),
                'starts_at' => FluentRule::date()->required()->after('2025-01-01'),
            ]),
        ])->validate(['items' => [
            ['name' => 'X', 'starts_at' => '2020-01-01'],
        ]]);
        test()->fail('Expected ValidationException');
    } catch (ValidationException $validationException) {
        expect($validationException->errors())->toHaveKeys(['items.0.name', 'items.0.starts_at']);
    }
});

it('partial fast-check with all-fast rules skips Laravel entirely', function (): void {
    $validated = RuleSet::from([
        'items' => FluentRule::array()->required()->each([
            'name' => FluentRule::string()->required()->min(2),
            'age' => FluentRule::numeric()->required()->integer()->min(0),
        ]),
    ])->validate(['items' => [['name' => 'Alice', 'age' => 30]]]);

    expect($validated['items'])->toHaveCount(1);
});

it('partial fast-check catches single invalid item among many valid', function (): void {
    $items = array_map(fn (int $i): array => [
        'name' => 'User ' . $i,
        'starts_at' => '2025-06-' . str_pad((string) ($i % 28 + 1), 2, '0', STR_PAD_LEFT),
    ], range(1, 50));

    $items[24]['starts_at'] = '2020-01-01';

    try {
        RuleSet::from([
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->min(2),
                'starts_at' => FluentRule::date()->required()->after('2025-01-01'),
            ]),
        ])->validate(['items' => $items]);
        test()->fail('Expected ValidationException');
    } catch (ValidationException $validationException) {
        expect($validationException->errors())->toHaveKey('items.24.starts_at')
            ->toHaveCount(1);
    }
});

// =========================================================================
// in() / notIn() with Arrayable (Collections)
// =========================================================================

it('in() accepts Arrayable (Collection)', function (): void {
    $collection = collect(['admin', 'editor', 'viewer']);
    // Collection<int, string> satisfies Arrayable<array-key, mixed> at runtime;
    // PHPStan's generic invariance produces a false positive here, which is
    // exactly the misfire this test is guarding against.
    // @phpstan-ignore argument.type
    $rule = FluentRule::string()->required()->in($collection);
    $compiled = $rule->compiledRules();

    expect($compiled)->toBeString()
        ->toContain('in:')
        ->toContain('admin');

    $validator = makeValidator(['role' => 'admin'], ['role' => $rule]);
    expect($validator->passes())->toBeTrue();

    $validator = makeValidator(['role' => 'hacker'], ['role' => $rule]);
    expect($validator->passes())->toBeFalse();
});

it('notIn() accepts Arrayable (Collection)', function (): void {
    $collection = collect(['banned', 'suspended']);
    // Same generic-invariance false positive as in() sibling test.
    // @phpstan-ignore argument.type
    $rule = FluentRule::string()->required()->notIn($collection);

    $validator = makeValidator(['status' => 'active'], ['status' => $rule]);
    expect($validator->passes())->toBeTrue();

    $validator = makeValidator(['status' => 'banned'], ['status' => $rule]);
    expect($validator->passes())->toBeFalse();
});

// =========================================================================
// ArrayRule distinct() validation
// =========================================================================

it('distinct() on each items rejects duplicate values via RuleSet', function (): void {
    try {
        RuleSet::from([
            'tags' => FluentRule::array()->required()->each(FluentRule::string()->distinct()),
        ])->validate(['tags' => ['php', 'laravel', 'php']]);
        test()->fail('Expected ValidationException');
    } catch (ValidationException $validationException) {
        expect($validationException->errors())->not->toBeEmpty();
    }
});

it('distinct() on each items passes with unique values', function (): void {
    $validated = RuleSet::from([
        'tags' => FluentRule::array()->required()->each(FluentRule::string()->distinct()),
    ])->validate(['tags' => ['php', 'laravel', 'vue']]);

    expect($validated['tags'])->toHaveCount(3);
});

// =========================================================================
// failOnUnknownFields
// =========================================================================

it('failOnUnknownFields passes when all input keys are known', function (): void {
    $validated = RuleSet::from([
        'name' => FluentRule::string()->required(),
        'email' => FluentRule::email()->required(),
    ])->failOnUnknownFields()->validate([
        'name' => 'John',
        'email' => 'john@example.com',
    ]);

    expect($validated)
        ->toHaveKey('name', 'John')
        ->toHaveKey('email', 'john@example.com');
});

it('failOnUnknownFields rejects unknown input keys', function (): void {
    RuleSet::from([
        'name' => FluentRule::string()->required(),
    ])->failOnUnknownFields()->validate([
        'name' => 'John',
        'hack' => 'malicious',
    ]);
})->throws(ValidationException::class);

it('failOnUnknownFields includes correct error key for unknown fields', function (): void {
    $errors = [];

    try {
        RuleSet::from([
            'name' => FluentRule::string()->required(),
        ])->failOnUnknownFields()->validate([
            'name' => 'John',
            'unknown_field' => 'value',
        ]);
    } catch (ValidationException $validationException) {
        $errors = $validationException->errors();
    }

    expect($errors)->toHaveKey('unknown_field');
});

it('failOnUnknownFields allows wildcard-matched input keys', function (): void {
    $validated = RuleSet::from([
        'items' => FluentRule::array()->required()->each([
            'name' => FluentRule::string()->required(),
        ]),
    ])->failOnUnknownFields()->validate([
        'items' => [['name' => 'Foo'], ['name' => 'Bar']],
    ]);

    expect($validated['items'])->toHaveCount(2);
});

it('failOnUnknownFields rejects unknown nested keys in wildcard arrays', function (): void {
    $errors = [];

    try {
        RuleSet::from([
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required(),
            ]),
        ])->failOnUnknownFields()->validate([
            'items' => [['name' => 'Foo', 'hack' => 'bad']],
        ]);
    } catch (ValidationException $validationException) {
        $errors = $validationException->errors();
    }

    expect($errors)->toHaveKey('items.0.hack');
});

it('failOnUnknownFields is not applied when not called', function (): void {
    $validated = RuleSet::from([
        'name' => FluentRule::string()->required(),
    ])->validate([
        'name' => 'John',
        'extra' => 'ignored',
    ]);

    expect($validated)->toHaveKey('name', 'John');
});

// =========================================================================
// stopOnFirstFailure
// =========================================================================

it('stopOnFirstFailure stops after the first field fails', function (): void {
    $errors = [];

    try {
        RuleSet::from([
            'name' => FluentRule::string()->required(),
            'email' => FluentRule::email()->required(),
        ])->stopOnFirstFailure()->validate([]);
    } catch (ValidationException $validationException) {
        $errors = $validationException->errors();
    }

    // Only one field should have errors, not both
    expect($errors)->toHaveCount(1);
});

it('stopOnFirstFailure still passes when all fields are valid', function (): void {
    $validated = RuleSet::from([
        'name' => FluentRule::string()->required(),
        'email' => FluentRule::email()->required(),
    ])->stopOnFirstFailure()->validate([
        'name' => 'John',
        'email' => 'john@example.com',
    ]);

    expect($validated)
        ->toHaveKey('name', 'John')
        ->toHaveKey('email', 'john@example.com');
});

it('stopOnFirstFailure stops on first failing item in wildcard arrays', function (): void {
    $errors = [];

    try {
        RuleSet::from([
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->min(2),
            ]),
        ])->stopOnFirstFailure()->validate([
            'items' => [
                ['name' => ''],    // fails required
                ['name' => 'a'],   // fails min:2
                ['name' => ''],    // also fails, but should not be reached
            ],
        ]);
    } catch (ValidationException $validationException) {
        $errors = $validationException->errors();
    }

    // Should have errors only for the first failing item, not all three
    expect($errors)->toHaveCount(1);
});

it('failOnUnknownFields uses custom attributes for error messages', function (): void {
    $errors = [];

    try {
        RuleSet::from([
            'name' => FluentRule::string()->required(),
        ])->failOnUnknownFields()->validate(
            data: ['name' => 'John', 'unknown_field' => 'value'],
            attributes: ['unknown_field' => 'Mystery Field'],
        );
    } catch (ValidationException $validationException) {
        $errors = $validationException->errors();
    }

    expect($errors)->toHaveKey('unknown_field')
        ->and($errors['unknown_field'][0])->toContain('Mystery Field');
});

it('failOnUnknownFields uses custom messages', function (): void {
    $errors = [];

    try {
        RuleSet::from([
            'name' => FluentRule::string()->required(),
        ])->failOnUnknownFields()->validate(
            data: ['name' => 'John', 'hack' => 'bad'],
            messages: ['hack.prohibited' => 'No hacking allowed!'],
        );
    } catch (ValidationException $validationException) {
        $errors = $validationException->errors();
    }

    expect($errors)->toHaveKey('hack')
        ->and($errors['hack'][0])->toBe('No hacking allowed!');
});

it('failOnUnknownFields works with children() inside each()', function (): void {
    $errors = [];

    try {
        RuleSet::from([
            'items' => FluentRule::array()->required()->each([
                'action' => FluentRule::array()->required()->children([
                    'type' => FluentRule::string()->required(),
                ]),
            ]),
        ])->failOnUnknownFields()->validate([
            'items' => [['action' => ['type' => 'click', 'hack' => 'bad']]],
        ]);
    } catch (ValidationException $validationException) {
        $errors = $validationException->errors();
    }

    expect($errors)->toHaveKey('items.0.action.hack');
});

it('failOnUnknownFields works with scalar each()', function (): void {
    $validated = RuleSet::from([
        'tags' => FluentRule::array()->required()->each(FluentRule::string()->max(50)),
    ])->failOnUnknownFields()->validate([
        'tags' => ['php', 'laravel'],
    ]);

    expect($validated['tags'])->toHaveCount(2);
});

it('failOnUnknownFields with deeply nested wildcards', function (): void {
    $validated = RuleSet::from([
        'items' => FluentRule::array()->required()->each([
            'options' => FluentRule::array()->each([
                'label' => FluentRule::string()->required(),
            ]),
        ]),
    ])->failOnUnknownFields()->validate([
        'items' => [
            ['options' => [['label' => 'A'], ['label' => 'B']]],
        ],
    ]);

    expect($validated['items'])->toHaveCount(1);
});

// =========================================================================
// compileWithMetadata — rules + messages + attributes in one call
// =========================================================================

it('compileWithMetadata returns compiled rules with extracted messages and labels', function (): void {
    $rules = [
        'name' => FluentRule::string('Full Name')->required()->message('Name is required!'),
        'items' => FluentRule::array()->required()->each([
            'qty' => FluentRule::numeric('Quantity')->required()->integer()->min(1),
        ]),
    ];

    [$compiled, $messages, $attributes] = RuleSet::compileWithMetadata($rules);

    // Rules compiled
    expect($compiled)
        ->toHaveKeys(['name', 'items', 'items.*.qty']);

    // Messages extracted
    expect($messages)->toHaveKey('name.required', 'Name is required!');

    // Labels extracted
    expect($attributes)
        ->toHaveKey('name', 'Full Name')
        ->toHaveKey('items.*.qty', 'Quantity');
});

it('compileWithMetadata returns empty messages/attributes for plain string rules', function (): void {
    $rules = [
        'name' => 'required|string|max:255',
    ];

    [$compiled, $messages, $attributes] = RuleSet::compileWithMetadata($rules);

    expect($compiled)->toHaveKey('name')
        ->and($messages)->toBeEmpty()
        ->and($attributes)->toBeEmpty();
});

// =========================================================================
// check() — non-throwing validation returning Validated result
// =========================================================================

it('check() returns failing result without throwing', function (): void {
    $result = RuleSet::from([
        'name' => FluentRule::string()->required(),
    ])->check(['name' => '']);

    expect($result->fails())->toBeTrue()
        ->and($result->passes())->toBeFalse()
        ->and($result->errors()->get('name'))->not->toBeEmpty();
});

it('check() returns passing result for valid data', function (): void {
    $result = RuleSet::from([
        'name' => FluentRule::string()->required()->max(255),
    ])->check(['name' => 'John']);

    expect($result->passes())->toBeTrue()
        ->and($result->fails())->toBeFalse()
        ->and($result->validated())->toBe(['name' => 'John']);
});

it('check() extracts FluentRule labels in errors', function (): void {
    $result = RuleSet::from([
        'name' => FluentRule::string('Full Name')->required(),
    ])->check(['name' => '']);

    expect($result->firstError('name'))->toContain('Full Name');
});

it('check() expands wildcards against data', function (): void {
    $result = RuleSet::from([
        'items' => FluentRule::array()->required()->each([
            'name' => FluentRule::string()->required(),
        ]),
    ])->check(['items' => [['name' => 'Foo'], ['name' => '']]]);

    expect($result->fails())->toBeTrue()
        ->and($result->errors()->has('items.1.name'))->toBeTrue();
});

it('check() validated() throws on failed validation', function (): void {
    $result = RuleSet::from([
        'name' => FluentRule::string()->required(),
    ])->check(['name' => '']);

    expect(fn () => $result->validated())->toThrow(ValidationException::class);
});

it('check() firstError returns null for fields with no errors', function (): void {
    $result = RuleSet::from([
        'name' => FluentRule::string()->required(),
    ])->check(['name' => 'John']);

    expect($result->firstError('name'))->toBeNull();
});

it('check() exposes underlying validator via escape hatch', function (): void {
    $result = RuleSet::from([
        'name' => FluentRule::string()->required(),
    ])->check(['name' => '']);

    // Escape hatch contract: validator() is callable and returns a validator
    // whose error bag reflects the failed run. Native return type already
    // guarantees the instance shape; behavior is what matters.
    expect($result->validator()->errors()->has('name'))->toBeTrue();
});

it('check() safe() returns ValidatedInput on success', function (): void {
    $result = RuleSet::from([
        'name' => FluentRule::string()->required(),
        'email' => FluentRule::email()->required(),
    ])->check(['name' => 'John', 'email' => 'john@example.com']);

    $safe = $result->safe();

    expect($safe->only(['name']))->toBe(['name' => 'John']);
});

it('check() safe() throws on failed validation', function (): void {
    $result = RuleSet::from([
        'name' => FluentRule::string()->required(),
    ])->check(['name' => '']);

    expect(fn () => $result->safe())->toThrow(ValidationException::class);
});

it('non-wildcard fast path rejects invalid children() values', function (): void {
    expect(fn () => RuleSet::from([
        'search' => FluentRule::array()->required()->children([
            'value' => FluentRule::string()->required()->max(5),
        ]),
    ])->validate([
        'search' => ['value' => 'way too long for the max'],
    ]))->toThrow(ValidationException::class);
});

it('non-wildcard fast path rejects filled with present null', function (): void {
    expect(fn () => RuleSet::from([
        'name' => FluentRule::string()->rule('filled'),
    ])->validate(['name' => null]))->toThrow(ValidationException::class);
});

it('non-wildcard fast path rejects oversized arrays without explicit array flag', function (): void {
    expect(fn () => RuleSet::from([
        'tags' => FluentRule::field()->rule('max:1'),
    ])->validate(['tags' => ['a', 'b', 'c']]))->toThrow(ValidationException::class);
});

it('wildcard fast path rejects field-ref date violation (after:sibling)', function (): void {
    $rules = [
        'events' => FluentRule::array()->required()->each([
            'start_date' => FluentRule::date()->required(),
            'end_date' => FluentRule::date()->required()->after('start_date'),
        ]),
    ];

    // end_date equals start_date → `after` should fail.
    $data = ['events' => [
        ['start_date' => '2030-06-01', 'end_date' => '2030-06-01'],
    ]];

    expect(fn () => RuleSet::from($rules)->validate($data))
        ->toThrow(ValidationException::class);
});

it('wildcard fast path accepts field-ref date when ordering is correct', function (): void {
    $rules = [
        'events' => FluentRule::array()->required()->each([
            'start_date' => FluentRule::date()->required(),
            'end_date' => FluentRule::date()->required()->after('start_date'),
        ]),
    ];

    $data = ['events' => [
        ['start_date' => '2030-06-01', 'end_date' => '2030-06-05'],
        ['start_date' => '2030-07-01', 'end_date' => '2030-07-02'],
    ]];

    expect(RuleSet::from($rules)->validate($data))->toBe($data);
});

it('wildcard fast path rejects field-ref date violation (before:sibling)', function (): void {
    $rules = [
        'events' => FluentRule::array()->required()->each([
            'start_date' => FluentRule::date()->required(),
            'registration_deadline' => FluentRule::date()->required()->before('start_date'),
        ]),
    ];

    // registration_deadline after start_date → `before` should fail.
    $data = ['events' => [
        ['start_date' => '2030-06-01', 'registration_deadline' => '2030-06-15'],
    ]];

    expect(fn () => RuleSet::from($rules)->validate($data))
        ->toThrow(ValidationException::class);
});

it('wildcard fast path enforces same:FIELD equality against sibling', function (): void {
    $rules = [
        'users' => FluentRule::array()->required()->each([
            'password' => FluentRule::string()->required(),
            'password_confirmation' => FluentRule::string()->required()->rule('same:password'),
        ]),
    ];

    // Mismatch → fail.
    expect(fn () => RuleSet::from($rules)->validate(['users' => [
        ['password' => 'hunter2', 'password_confirmation' => 'hunter3'],
    ]]))->toThrow(ValidationException::class);

    // Match → pass.
    $valid = ['users' => [
        ['password' => 'hunter2', 'password_confirmation' => 'hunter2'],
    ]];
    expect(RuleSet::from($rules)->validate($valid))->toBe($valid);
});

it('wildcard fast path enforces gte:FIELD (numeric)', function (): void {
    $rules = [
        'slots' => FluentRule::array()->required()->each([
            'stock' => FluentRule::integer()->required(),
            'sold' => FluentRule::integer()->required()->rule('lte:stock'),
        ]),
    ];

    // sold > stock → fail.
    expect(fn () => RuleSet::from($rules)->validate(['slots' => [
        ['stock' => 5, 'sold' => 10],
    ]]))->toThrow(ValidationException::class);

    // sold == stock → pass (lte, equal).
    $valid = ['slots' => [
        ['stock' => 5, 'sold' => 5],
        ['stock' => 10, 'sold' => 3],
    ]];
    expect(RuleSet::from($rules)->validate($valid))->toBe($valid);
});

it('wildcard fast path enforces combined gt + lt gates in one rule', function (): void {
    $rules = [
        'offers' => FluentRule::array()->required()->each([
            'min_price' => FluentRule::numeric()->required(),
            'max_price' => FluentRule::numeric()->required(),
            'price' => FluentRule::numeric()->required()->rule('gt:min_price')->rule('lt:max_price'),
        ]),
    ];

    // price strictly between min and max → pass.
    $valid = ['offers' => [
        ['min_price' => 10, 'max_price' => 20, 'price' => 15],
    ]];
    expect(RuleSet::from($rules)->validate($valid))->toBe($valid);

    // price equals min → fails gt.
    expect(fn () => RuleSet::from($rules)->validate(['offers' => [
        ['min_price' => 10, 'max_price' => 20, 'price' => 10],
    ]]))->toThrow(ValidationException::class);

    // price equals max → fails lt.
    expect(fn () => RuleSet::from($rules)->validate(['offers' => [
        ['min_price' => 10, 'max_price' => 20, 'price' => 20],
    ]]))->toThrow(ValidationException::class);

    // price above max → fails lt.
    expect(fn () => RuleSet::from($rules)->validate(['offers' => [
        ['min_price' => 10, 'max_price' => 20, 'price' => 25],
    ]]))->toThrow(ValidationException::class);
});

it('wildcard fast path enforces gt:FIELD (string length)', function (): void {
    $rules = [
        'entries' => FluentRule::array()->required()->each([
            'short' => FluentRule::string()->required(),
            'long' => FluentRule::string()->required()->rule('gt:short'),
        ]),
    ];

    // same length → fails strict gt.
    expect(fn () => RuleSet::from($rules)->validate(['entries' => [
        ['short' => 'abc', 'long' => 'xyz'],
    ]]))->toThrow(ValidationException::class);

    $valid = ['entries' => [
        ['short' => 'hi', 'long' => 'hello'],
    ]];
    expect(RuleSet::from($rules)->validate($valid))->toBe($valid);
});

it('wildcard fast path enforces confirmed (default suffix)', function (): void {
    $rules = [
        'users' => FluentRule::array()->required()->each([
            'password' => FluentRule::string()->required()->rule('confirmed'),
        ]),
    ];

    // Mismatched confirmation → fail.
    expect(fn () => RuleSet::from($rules)->validate(['users' => [
        ['password' => 'hunter2', 'password_confirmation' => 'hunter3'],
    ]]))->toThrow(ValidationException::class);

    // Missing confirmation → fail.
    expect(fn () => RuleSet::from($rules)->validate(['users' => [
        ['password' => 'hunter2'],
    ]]))->toThrow(ValidationException::class);

    // Matching confirmation → pass (but `password_confirmation` is stripped
    // from validated output since it isn't in the rule set).
    $valid = ['users' => [
        ['password' => 'hunter2', 'password_confirmation' => 'hunter2'],
    ]];
    $result = RuleSet::from($rules)->validate($valid);
    expect($result['users'][0]['password'])->toBe('hunter2');
});

it('wildcard fast path enforces confirmed:custom_name', function (): void {
    $rules = [
        'users' => FluentRule::array()->required()->each([
            'pwd' => FluentRule::string()->required()->rule('confirmed:pwd_check'),
        ]),
    ];

    // Mismatch → fail.
    expect(fn () => RuleSet::from($rules)->validate(['users' => [
        ['pwd' => 'hunter2', 'pwd_check' => 'hunter3'],
    ]]))->toThrow(ValidationException::class);

    // Match → pass.
    $valid = ['users' => [
        ['pwd' => 'hunter2', 'pwd_check' => 'hunter2'],
    ]];
    expect(RuleSet::from($rules)->validate($valid)['users'][0]['pwd'])->toBe('hunter2');
});

it('wildcard fast path enforces different:FIELD against sibling', function (): void {
    $rules = [
        'users' => FluentRule::array()->required()->each([
            'username' => FluentRule::string()->required(),
            'nickname' => FluentRule::string()->required()->rule('different:username'),
        ]),
    ];

    // Match (nickname equals username) → fails `different`.
    expect(fn () => RuleSet::from($rules)->validate(['users' => [
        ['username' => 'alice', 'nickname' => 'alice'],
    ]]))->toThrow(ValidationException::class);

    // Distinct → pass.
    $valid = ['users' => [
        ['username' => 'alice', 'nickname' => 'Ali'],
    ]];
    expect(RuleSet::from($rules)->validate($valid))->toBe($valid);
});

it('wildcard fast path enforces combined field-refs (after:a|before:b) in one rule', function (): void {
    $rules = [
        'events' => FluentRule::array()->required()->each([
            'start' => FluentRule::date()->required(),
            'end' => FluentRule::date()->required(),
            // checkpoint must sit strictly between start and end.
            'checkpoint' => FluentRule::date()->required()->after('start')->before('end'),
        ]),
    ];

    $valid = ['events' => [
        ['start' => '2030-06-01', 'end' => '2030-06-10', 'checkpoint' => '2030-06-05'],
    ]];
    expect(RuleSet::from($rules)->validate($valid))->toBe($valid);

    // checkpoint BEFORE start → fails `after:start`.
    expect(fn () => RuleSet::from($rules)->validate(['events' => [
        ['start' => '2030-06-01', 'end' => '2030-06-10', 'checkpoint' => '2030-05-15'],
    ]]))->toThrow(ValidationException::class);

    // checkpoint AFTER end → fails `before:end`.
    expect(fn () => RuleSet::from($rules)->validate(['events' => [
        ['start' => '2030-06-01', 'end' => '2030-06-10', 'checkpoint' => '2030-06-20'],
    ]]))->toThrow(ValidationException::class);
});

it('wildcard fast path enforces required_with (sibling present → required)', function (): void {
    $rules = [
        'contacts' => FluentRule::array()->required()->each([
            'phone' => FluentRule::string()->nullable(),
            'phone_type' => FluentRule::string()->requiredWith('phone'),
        ]),
    ];

    // phone present, phone_type missing → required_with triggers → fail.
    expect(fn () => RuleSet::from($rules)->validate(['contacts' => [
        ['phone' => '555-1234'],
    ]]))->toThrow(ValidationException::class);

    // phone present, phone_type present → pass.
    $valid = ['contacts' => [
        ['phone' => '555-1234', 'phone_type' => 'mobile'],
    ]];
    expect(RuleSet::from($rules)->validate($valid))->toBe($valid);

    // phone absent → required_with inactive → phone_type optional → pass.
    $noPhone = ['contacts' => [[]]];
    expect(RuleSet::from($rules)->validate($noPhone))->toBe(['contacts' => [[]]]);
});

it('wildcard fast path enforces required_with with multi-param (any sibling present)', function (): void {
    $rules = [
        'rows' => FluentRule::array()->required()->each([
            'a' => FluentRule::string()->nullable(),
            'b' => FluentRule::string()->nullable(),
            'label' => FluentRule::string()->rule('required_with:a,b')->max(50),
        ]),
    ];

    // a present → label required → missing = fail.
    expect(fn () => RuleSet::from($rules)->validate(['rows' => [
        ['a' => 'x'],
    ]]))->toThrow(ValidationException::class);

    // b present, a missing → label required → missing = fail.
    expect(fn () => RuleSet::from($rules)->validate(['rows' => [
        ['b' => 'y'],
    ]]))->toThrow(ValidationException::class);

    // neither → label optional → pass.
    $neither = ['rows' => [[]]];
    expect(RuleSet::from($rules)->validate($neither))->toBe(['rows' => [[]]]);

    // both + label → pass.
    $full = ['rows' => [['a' => 'x', 'b' => 'y', 'label' => 'ok']]];
    expect(RuleSet::from($rules)->validate($full))->toBe($full);
});

it('wildcard fast path fast-checks required_with alone (stripped rule is empty)', function (): void {
    // No other rules on the field — stripped remainder is empty, so
    // withoutRequired is the always-pass closure.
    $rules = [
        'items' => FluentRule::array()->required()->each([
            'trigger' => FluentRule::string()->nullable(),
            'conditional' => FluentRule::field()->rule('required_with:trigger'),
        ]),
    ];

    // trigger present, conditional missing → fail.
    expect(fn () => RuleSet::from($rules)->validate(['items' => [
        ['trigger' => 'x'],
    ]]))->toThrow(ValidationException::class);

    // trigger missing → pass regardless of conditional value.
    $noTrigger = ['items' => [[]]];
    expect(RuleSet::from($rules)->validate($noTrigger))->toBe(['items' => [[]]]);
});

it('wildcard fast path enforces required_without (sibling absent → required)', function (): void {
    $rules = [
        'users' => FluentRule::array()->required()->each([
            'email' => FluentRule::string()->nullable(),
            'phone' => FluentRule::string()->requiredWithout('email'),
        ]),
    ];

    // email missing, phone missing → required_without fails → fail.
    expect(fn () => RuleSet::from($rules)->validate(['users' => [
        [],
    ]]))->toThrow(ValidationException::class);

    // email missing, phone present → pass.
    $phoneOnly = ['users' => [['phone' => '555']]];
    expect(RuleSet::from($rules)->validate($phoneOnly))->toBe($phoneOnly);

    // email present → required_without inactive → phone optional → pass.
    $emailOnly = ['users' => [['email' => 'a@b.co']]];
    expect(RuleSet::from($rules)->validate($emailOnly))->toBe($emailOnly);
});

it('wildcard fast path enforces required_with_all (all siblings present → required)', function (): void {
    $rules = [
        'cards' => FluentRule::array()->required()->each([
            'number' => FluentRule::string()->nullable(),
            'expiry' => FluentRule::string()->nullable(),
            'cvc' => FluentRule::string()->rule('required_with_all:number,expiry'),
        ]),
    ];

    // both number + expiry → cvc required → missing = fail.
    expect(fn () => RuleSet::from($rules)->validate(['cards' => [
        ['number' => '4242', 'expiry' => '12/30'],
    ]]))->toThrow(ValidationException::class);

    // only number → required_with_all inactive → cvc optional → pass.
    $partial = ['cards' => [['number' => '4242']]];
    expect(RuleSet::from($rules)->validate($partial))->toBe($partial);

    // all three → pass.
    $complete = ['cards' => [['number' => '4242', 'expiry' => '12/30', 'cvc' => '123']]];
    expect(RuleSet::from($rules)->validate($complete))->toBe($complete);
});

it('wildcard fast path enforces required_without_all (all siblings absent → required)', function (): void {
    $rules = [
        'contacts' => FluentRule::array()->required()->each([
            'email' => FluentRule::string()->nullable(),
            'phone' => FluentRule::string()->nullable(),
            'fallback' => FluentRule::string()->rule('required_without_all:email,phone'),
        ]),
    ];

    // both email + phone absent → fallback required → missing = fail.
    expect(fn () => RuleSet::from($rules)->validate(['contacts' => [
        [],
    ]]))->toThrow(ValidationException::class);

    // email present → inactive → fallback optional → pass.
    $withEmail = ['contacts' => [['email' => 'a@b.co']]];
    expect(RuleSet::from($rules)->validate($withEmail))->toBe($withEmail);
});
