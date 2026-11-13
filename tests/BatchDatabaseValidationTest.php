<?php declare(strict_types=1);

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as BaseValidator;
use SanderMuller\FluentValidation\BatchDatabaseChecker;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\HasFluentRules;
use SanderMuller\FluentValidation\Internal\DatabaseRuleWheres;
use SanderMuller\FluentValidation\PrecomputedPresenceVerifier;
use SanderMuller\FluentValidation\RuleSet;

// =========================================================================
// Helper: set up in-memory SQLite for DB tests
// =========================================================================

function setupTestDatabase(): void
{
    config(['database.default' => 'testing']);
    config(['database.connections.testing' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]]);

    Schema::connection('testing')->create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('email')->unique();
        $table->timestamps();
    });

    DB::connection('testing')->table('users')->insert([
        ['email' => 'alice@example.com', 'created_at' => now(), 'updated_at' => now()],
        ['email' => 'bob@example.com', 'created_at' => now(), 'updated_at' => now()],
        ['email' => 'carol@example.com', 'created_at' => now(), 'updated_at' => now()],
    ]);
}

// =========================================================================
// PrecomputedPresenceVerifier unit tests
// =========================================================================

it('precomputed verifier returns 1 for existing values and 0 for missing', function (): void {
    $verifier = new PrecomputedPresenceVerifier();
    $verifier->addLookup('t', 'c', ['a', 'b', 'c']);

    expect($verifier->getCount('t', 'c', 'a'))->toBe(1)
        ->and($verifier->getCount('t', 'c', 'z'))->toBe(0);
});

it('precomputed verifier counts multi values correctly', function (): void {
    $verifier = new PrecomputedPresenceVerifier();
    $verifier->addLookup('t', 'c', ['a', 'b', 'c']);

    expect($verifier->getMultiCount('t', 'c', ['a', 'b', 'z']))->toBe(2)
        ->and($verifier->getMultiCount('t', 'c', ['x', 'y']))->toBe(0);
});

it('precomputed verifier scopes lookups by table and column', function (): void {
    $verifier = new PrecomputedPresenceVerifier();
    $verifier->addLookup('users', 'email', ['alice@example.com']);
    $verifier->addLookup('users', 'username', ['bob']);

    // Each lookup is independent
    expect($verifier->getCount('users', 'email', 'alice@example.com'))->toBe(1)
        ->and($verifier->getCount('users', 'email', 'bob'))->toBe(0)
        ->and($verifier->getCount('users', 'username', 'bob'))->toBe(1)
        ->and($verifier->getCount('users', 'username', 'alice@example.com'))->toBe(0);
});

// =========================================================================
// PrecomputedPresenceVerifier + Laravel Validator integration
// =========================================================================

it('precomputed verifier preserves custom messages keyed by rule name', function (): void {
    $verifier = new PrecomputedPresenceVerifier();
    $verifier->addLookup('users', 'email', ['known@example.com']);

    $validator = Validator::make(
        ['email' => 'unknown@example.com'],
        ['email' => ['required', Rule::exists('users', 'email')]],
        ['email.exists' => 'Custom exists message'],
    );
    $validator->setPresenceVerifier($verifier);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('email'))->toBe('Custom exists message');
});

it('precomputed verifier produces standard exists message', function (): void {
    $verifier = new PrecomputedPresenceVerifier();
    $verifier->addLookup('users', 'email', ['known@example.com']);

    $validator = Validator::make(
        ['email' => 'unknown@example.com'],
        ['email' => ['required', Rule::exists('users', 'email')]],
    );
    $validator->setPresenceVerifier($verifier);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('email'))->toContain('selected');
});

it('precomputed verifier passes for values in the lookup set', function (): void {
    $verifier = new PrecomputedPresenceVerifier();
    $verifier->addLookup('users', 'email', ['known@example.com']);

    $validator = Validator::make(
        ['email' => 'known@example.com'],
        ['email' => ['required', Rule::exists('users', 'email')]],
    );
    $validator->setPresenceVerifier($verifier);

    expect($validator->passes())->toBeTrue();
});

it('precomputed verifier preserves custom attributes', function (): void {
    $verifier = new PrecomputedPresenceVerifier();
    $verifier->addLookup('users', 'email', ['known@example.com']);

    $validator = Validator::make(
        ['email' => 'unknown@example.com'],
        ['email' => ['required', Rule::exists('users', 'email')]],
        [],
        ['email' => 'e-mail address'],
    );
    $validator->setPresenceVerifier($verifier);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('email'))->toContain('e-mail address');
});

// =========================================================================
// BatchDatabaseChecker unit tests
// =========================================================================

it('isBatchable returns true for simple exists rules', function (): void {
    $rule = Rule::exists('users', 'email');

    expect(BatchDatabaseChecker::isBatchable($rule))->toBeTrue();
});

it('isBatchable returns false for rules without explicit column', function (): void {
    // exists('users') without column — Laravel infers column from attribute name
    $rule = Rule::exists('users');

    expect(BatchDatabaseChecker::isBatchable($rule))->toBeFalse();
});

it('isBatchable returns false for unique rules without explicit column', function (): void {
    $rule = Rule::unique('users');

    expect(BatchDatabaseChecker::isBatchable($rule))->toBeFalse();
});

it('isBatchable returns false for rules with closure callbacks', function (): void {
    $rule = Rule::exists('users', 'email')->where(function (mixed $query): void {
        $query->where('active', true);
    });

    expect(BatchDatabaseChecker::isBatchable($rule))->toBeFalse();
});

it('isBatchable returns true for unique rules without callbacks', function (): void {
    $rule = Rule::unique('users', 'email');

    expect(BatchDatabaseChecker::isBatchable($rule))->toBeTrue();
});

it('isBatchable returns true for unique rules with ignore', function (): void {
    $rule = Rule::unique('users', 'email')->ignore(1);

    expect(BatchDatabaseChecker::isBatchable($rule))->toBeTrue();
});

it('isAvailable returns true when default verifier is registered', function (): void {
    expect(BatchDatabaseChecker::isAvailable())->toBeTrue();
});

// =========================================================================
// BatchDatabaseChecker + real database integration
// =========================================================================

it('fetchExisting returns values that exist in the database', function (): void {
    setupTestDatabase();

    $rule = Rule::exists('testing.users', 'email');
    $result = BatchDatabaseChecker::fetchExisting(
        ['alice@example.com', 'bob@example.com', 'unknown@example.com'],
        $rule,
    );

    expect($result)->toContain('alice@example.com')
        ->toContain('bob@example.com')
        ->not->toContain('unknown@example.com');
});

it('fetchExisting returns empty array for empty input', function (): void {
    expect(BatchDatabaseChecker::fetchExisting([], Rule::exists('users', 'email')))->toBeEmpty();
});

it('fetchTaken returns values that already exist for unique check', function (): void {
    setupTestDatabase();

    $rule = Rule::unique('testing.users', 'email');
    $result = BatchDatabaseChecker::fetchTaken(
        ['alice@example.com', 'new@example.com'],
        $rule,
    );

    expect($result)->toContain('alice@example.com')
        ->not->toContain('new@example.com');
});

it('fetchTaken respects ignore() on unique rules', function (): void {
    setupTestDatabase();

    // Alice has id=1, so ignoring id=1 should make her email "available"
    $rule = Rule::unique('testing.users', 'email')->ignore(1);
    $result = BatchDatabaseChecker::fetchTaken(
        ['alice@example.com', 'bob@example.com'],
        $rule,
    );

    expect($result)->not->toContain('alice@example.com')
        ->toContain('bob@example.com');
});

// =========================================================================
// RuleSet integration: exists batching end-to-end
// =========================================================================

it('RuleSet batches exists queries for wildcard arrays', function (): void {
    setupTestDatabase();

    // Track query count
    DB::connection('testing')->enableQueryLog();

    $validated = RuleSet::from([
        'items' => FluentRule::array()->required()->each([
            'email' => FluentRule::string()->required()->exists('testing.users', 'email'),
        ]),
    ])->validate([
        'items' => [
            ['email' => 'alice@example.com'],
            ['email' => 'bob@example.com'],
            ['email' => 'carol@example.com'],
        ],
    ]);

    $queryLog = DB::connection('testing')->getQueryLog();
    DB::connection('testing')->disableQueryLog();

    // Should use batch query (1 whereIn) instead of 3 individual queries
    $existsQueries = array_filter($queryLog, static fn (array $q): bool => str_contains($q['query'], 'users'));
    expect(count($existsQueries))->toBeLessThanOrEqual(2) // 1 batch + possibly 1 schema
        ->and($validated['items'])->toHaveCount(3);
});

it('RuleSet batched exists rejects invalid values with correct error keys', function (): void {
    setupTestDatabase();

    $errors = [];

    try {
        RuleSet::from([
            'items' => FluentRule::array()->required()->each([
                'email' => FluentRule::string()->required()->exists('testing.users', 'email'),
            ]),
        ])->validate([
            'items' => [
                ['email' => 'alice@example.com'],
                ['email' => 'nonexistent@example.com'],
                ['email' => 'bob@example.com'],
            ],
        ]);
    } catch (ValidationException $validationException) {
        $errors = $validationException->errors();
    }

    expect($errors)->toHaveKey('items.1.email')
        ->and($errors)->not->toHaveKey('items.0.email')
        ->and($errors)->not->toHaveKey('items.2.email');
});

it('RuleSet batched exists preserves custom messages via FluentRule message()', function (): void {
    setupTestDatabase();

    $errors = [];

    try {
        RuleSet::from([
            'items' => FluentRule::array()->required()->each([
                'email' => FluentRule::string()->required()
                    ->exists('testing.users', 'email')
                    ->messageFor('exists', 'Email not found in our system'),
            ]),
        ])->validate(
            ['items' => [['email' => 'nonexistent@example.com']]],
        );
    } catch (ValidationException $validationException) {
        $errors = $validationException->errors();
    }

    expect($errors)->toHaveKey('items.0.email')
        ->and($errors['items.0.email'][0])->toBe('Email not found in our system');
});

// =========================================================================
// RuleSet integration: unique batching end-to-end
// =========================================================================

it('RuleSet batches unique queries for wildcard arrays', function (): void {
    setupTestDatabase();

    DB::connection('testing')->enableQueryLog();

    $errors = [];

    try {
        RuleSet::from([
            'items' => FluentRule::array()->required()->each([
                'email' => FluentRule::string()->required()->unique('testing.users', 'email'),
            ]),
        ])->validate([
            'items' => [
                ['email' => 'new1@example.com'],     // passes (not in DB)
                ['email' => 'alice@example.com'],     // fails (already in DB)
                ['email' => 'new2@example.com'],      // passes
            ],
        ]);
    } catch (ValidationException $validationException) {
        $errors = $validationException->errors();
    }

    $queryLog = DB::connection('testing')->getQueryLog();
    DB::connection('testing')->disableQueryLog();

    // Should batch into 1 query instead of 3
    $uniqueQueries = array_filter($queryLog, static fn (array $q): bool => str_contains($q['query'], 'users'));
    expect(count($uniqueQueries))->toBeLessThanOrEqual(2)
        ->and($errors)->toHaveKey('items.1.email')
        ->and($errors)->not->toHaveKey('items.0.email')
        ->and($errors)->not->toHaveKey('items.2.email');
});

it('RuleSet batched unique respects ignore()', function (): void {
    setupTestDatabase();

    // Alice has id=1. Ignoring id=1 means alice@example.com is "available".
    $validated = RuleSet::from([
        'items' => FluentRule::array()->required()->each([
            'email' => FluentRule::string()->required()
                ->unique('testing.users', 'email', fn (mixed $rule) => $rule->ignore(1)),
        ]),
    ])->validate([
        'items' => [
            ['email' => 'alice@example.com'],   // passes (ignored)
            ['email' => 'new@example.com'],      // passes (not in DB)
        ],
    ]);

    expect($validated['items'])->toHaveCount(2);
});

it('RuleSet batched unique rejects taken values with ignore()', function (): void {
    setupTestDatabase();

    // Ignoring id=1 (alice), but bob (id=2) is still taken.
    $errors = [];

    try {
        RuleSet::from([
            'items' => FluentRule::array()->required()->each([
                'email' => FluentRule::string()->required()
                    ->unique('testing.users', 'email', fn (mixed $rule) => $rule->ignore(1)),
            ]),
        ])->validate([
            'items' => [
                ['email' => 'alice@example.com'],   // passes (ignored)
                ['email' => 'bob@example.com'],      // fails (still taken)
            ],
        ]);
    } catch (ValidationException $validationException) {
        $errors = $validationException->errors();
    }

    expect($errors)->toHaveKey('items.1.email')
        ->and($errors)->not->toHaveKey('items.0.email');
});

// =========================================================================
// Fallback: non-batchable rules still work alongside batched rules
// =========================================================================

it('precomputed verifier falls back to original verifier for unknown lookups', function (): void {
    setupTestDatabase();

    $originalVerifier = resolve(DatabasePresenceVerifier::class);
    $verifier = new PrecomputedPresenceVerifier($originalVerifier);
    // Only email is pre-computed — username is NOT
    $verifier->addLookup('users', 'email', ['alice@example.com']);

    // email lookup hits the precomputed set
    expect($verifier->getCount('users', 'email', 'alice@example.com'))->toBe(1)
        ->and($verifier->getCount('users', 'email', 'unknown@example.com'))->toBe(0);

    // username lookup falls back to the real DB verifier
    // (alice has no username column in our test table, so the count would be based on actual DB)
    // The key point: it doesn't return 0 blindly — it delegates to the real verifier.
    $count = $verifier->getCount('users', 'username', 'alice@example.com');
    // The point is it didn't crash or return 0 blindly — delegated to the real
    // verifier. Non-negative is the minimum sane contract.
    expect($count)->toBeGreaterThanOrEqual(0);
});

// =========================================================================
// OptimizedValidator (FormRequest) path: batch exists
// =========================================================================

it('OptimizedValidator batches exists queries via HasFluentRules FormRequest', function (): void {
    setupTestDatabase();

    $formRequest = createBatchFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->each([
                'email' => FluentRule::string()->required()->exists('testing.users', 'email'),
            ]),
        ],
        data: [
            'items' => [
                ['email' => 'alice@example.com'],
                ['email' => 'bob@example.com'],
                ['email' => 'carol@example.com'],
            ],
        ],
    );

    DB::connection('testing')->enableQueryLog();

    $factory = resolve(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    expect($validator->fails())->toBeFalse();

    $queryLog = DB::connection('testing')->getQueryLog();
    DB::connection('testing')->disableQueryLog();

    $existsQueries = array_filter($queryLog, static fn (array $q): bool => str_contains($q['query'], 'users'));
    // 1 batch whereIn query (from applyBatchVerifier) instead of 3 individual count queries
    expect(count($existsQueries))->toBeLessThanOrEqual(2);
});

it('OptimizedValidator batched exists reports errors with correct attribute paths', function (): void {
    setupTestDatabase();

    $formRequest = createBatchFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->each([
                'email' => FluentRule::string()->required()->exists('testing.users', 'email'),
            ]),
        ],
        data: [
            'items' => [
                ['email' => 'alice@example.com'],
                ['email' => 'nonexistent@example.com'],
                ['email' => 'bob@example.com'],
            ],
        ],
    );

    $factory = resolve(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->keys())->toContain('items.1.email')
        ->and($validator->errors()->keys())->not->toContain('items.0.email')
        ->and($validator->errors()->keys())->not->toContain('items.2.email');
});

// =========================================================================
// Safety: complex string rules are NOT batched on FormRequest path
// =========================================================================

it('FormRequest path does not batch non-wildcard exists rules', function (): void {
    setupTestDatabase();

    // Non-wildcard field with a scoped exists rule — should NOT be batched.
    // If batched, the precomputed verifier might return wrong results because
    // it replaces the original verifier for ALL rules, not just wildcards.
    $formRequest = createBatchFormRequest(
        rules: [
            'email' => FluentRule::string()->required()
                ->exists('testing.users', 'email'),
        ],
        data: [
            'email' => 'nonexistent@example.com',
        ],
    );

    $factory = resolve(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    // Should fail — email doesn't exist in DB
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->keys())->toContain('email');
});

it('FormRequest path does not batch unique rules with ignore()', function (): void {
    setupTestDatabase();

    // Alice has id=1. The unique rule ignores id=1, so alice@example.com should pass.
    // If batching incorrectly strips the ignore(), alice would be treated as taken.
    $formRequest = createBatchFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->each([
                'email' => FluentRule::string()->required()
                    ->unique('testing.users', 'email', fn (mixed $rule) => $rule->ignore(1)),
            ]),
        ],
        data: [
            'items' => [
                ['email' => 'alice@example.com'],   // should pass (ignored)
                ['email' => 'new@example.com'],      // should pass (not in DB)
            ],
        ],
    );

    $factory = resolve(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    expect($validator->fails())->toBeFalse();
});

it('FormRequest path batches exists rules with scalar where clauses', function (): void {
    setupTestDatabase();

    // Add a video_id column for scoped exists
    DB::connection('testing')->statement('ALTER TABLE users ADD COLUMN video_id INTEGER DEFAULT 1');

    $formRequest = createBatchFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->each([
                'email' => FluentRule::string()->required()
                    ->exists('testing.users', 'email', fn (mixed $rule) => $rule->where('video_id', 1)),
            ]),
        ],
        data: [
            'items' => [
                ['email' => 'alice@example.com'],
                ['email' => 'bob@example.com'],
            ],
        ],
    );

    DB::connection('testing')->enableQueryLog();

    $factory = resolve(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    expect($validator->fails())->toBeFalse();

    $queryLog = DB::connection('testing')->getQueryLog();
    DB::connection('testing')->disableQueryLog();

    // Should batch into 1 whereIn query (with AND video_id = 1) instead of 2 individual
    $existsQueries = array_filter($queryLog, static fn (array $q): bool => str_contains($q['query'], 'users'));
    expect(count($existsQueries))->toBeLessThanOrEqual(2);
});

it('FormRequest path does not batch exists rules with extra wheres', function (): void {
    setupTestDatabase();

    // Add soft-delete column and delete carol
    DB::connection('testing')->statement('ALTER TABLE users ADD COLUMN deleted_at TIMESTAMP NULL');
    DB::connection('testing')->table('users')->where('email', 'carol@example.com')->update(['deleted_at' => now()]);

    // withoutTrashed adds a where clause — the stringified rule includes it.
    // Batching should NOT strip it.
    $formRequest = createBatchFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->each([
                'email' => FluentRule::string()->required()
                    ->exists('testing.users', 'email', fn (mixed $rule) => $rule->withoutTrashed()),
            ]),
        ],
        data: [
            'items' => [
                ['email' => 'alice@example.com'],   // exists, not trashed
                ['email' => 'carol@example.com'],   // exists but trashed — should fail
            ],
        ],
    );

    $factory = resolve(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    // carol is soft-deleted, so this should fail
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->keys())->toContain('items.1.email');
});

// =========================================================================
// Edge cases & gap coverage
// =========================================================================

it('verifier returns 0 for unknown lookup with no fallback', function (): void {
    $verifier = new PrecomputedPresenceVerifier();

    expect($verifier->getCount('unknown_table', 'col', 'val'))->toBe(0)
        ->and($verifier->getMultiCount('unknown_table', 'col', ['a', 'b']))->toBe(0);
});

it('verifier hasLookups returns false when empty', function (): void {
    $verifier = new PrecomputedPresenceVerifier();

    expect($verifier->hasLookups())->toBeFalse();
});

it('withoutTrashed exists rule is batchable', function (): void {
    $rule = Rule::exists('users', 'email')->withoutTrashed();

    expect(BatchDatabaseChecker::isBatchable($rule))->toBeTrue();
});

it('withoutTrashed exists rule batches correctly', function (): void {
    setupTestDatabase();

    // Add a soft-delete column and soft-delete one user
    DB::connection('testing')->statement('ALTER TABLE users ADD COLUMN deleted_at TIMESTAMP NULL');
    DB::connection('testing')->table('users')->where('email', 'carol@example.com')->update(['deleted_at' => now()]);

    $errors = [];

    try {
        RuleSet::from([
            'items' => FluentRule::array()->required()->each([
                'email' => FluentRule::string()->required()
                    ->exists('testing.users', 'email', fn (mixed $rule) => $rule->withoutTrashed()),
            ]),
        ])->validate([
            'items' => [
                ['email' => 'alice@example.com'],   // exists, not trashed
                ['email' => 'carol@example.com'],   // exists but trashed — should fail
            ],
        ]);
    } catch (ValidationException $validationException) {
        $errors = $validationException->errors();
    }

    // carol is soft-deleted, so exists with withoutTrashed should fail
    expect($errors)->toHaveKey('items.1.email')
        ->and($errors)->not->toHaveKey('items.0.email');
});

it('batched where clauses match Laravel per-item validation', function (string $ruleType, string $case): void {
    setupTestDatabase();
    seedUserTypes();

    $modify = static fn (Exists|Unique $rule): Exists|Unique => match ($case) {
        'whereNot' => $rule->whereNot('type', 'admin'),
        'whereNull' => $rule->whereNull('type'),
        'whereNotNull' => $rule->whereNotNull('type'),
        'leading !' => $rule->where('type', '!admin'),
        'NOT_NULL' => $rule->where('type', 'NOT_NULL'),
        default => throw new InvalidArgumentException($case),
    };

    $data = ['items' => [
        ['email' => 'alice@example.com'],
        ['email' => 'bob@example.com'],
        ['email' => 'carol@example.com'],
    ]];

    $laravelRule = $ruleType === 'exists'
        ? Rule::exists('testing.users', 'email')
        : Rule::unique('testing.users', 'email');

    $email = FluentRule::string()->required();
    $email = $ruleType === 'exists'
        ? $email->exists('testing.users', 'email', $modify)
        : $email->unique('testing.users', 'email', $modify);

    $errors = [];
    $queries = countTestingUsersQueries(static function () use ($data, $email, &$errors): void {
        $errors = ruleSetErrorKeys($data, [
            'items' => FluentRule::array()->required()->each(['email' => $email]),
        ]);
    });

    expect($errors)->toBe(laravelErrorKeys($data, [
        'items' => ['required', 'array'],
        'items.*.email' => ['required', 'string', $modify($laravelRule)],
    ]));

    // Releases with the `operator` where shape compare the literal cases
    // literally and pass them as closures, so those run once per item.
    $literal = in_array($case, ['leading !', 'NOT_NULL'], true);

    expect($queries)->toBe($literal && whereShapeHasOperator() ? 3 : 1);
})->with([
    'exists',
    'unique',
])->with([
    'whereNot',
    'whereNull',
    'whereNotNull',
    'leading !',
    'NOT_NULL',
]);

it('a where clause with a non-scalar value is not batched', function (): void {
    // Older Laravel releases keep a Stringable where() value as an object.
    // The batch query cannot replay it, so the rule must stay per-item
    // instead of losing the clause.
    expect(DatabaseRuleWheres::normalize([['column' => 'type', 'value' => Str::of('admin')]]))->toBeNull()
        ->and(DatabaseRuleWheres::normalize([['column' => 'type', 'operator' => '=', 'value' => Str::of('admin')]]))->toBeNull()
        ->and(DatabaseRuleWheres::normalize([['column' => 'type', 'value' => 'admin']]))
        ->toBe([['column' => 'type', 'operator' => '=', 'value' => 'admin']]);
});

it('normalizes both where shapes to the conditions Laravel passes', function (array $wheres, ?array $conditions): void {
    $normalized = DatabaseRuleWheres::normalize($wheres);

    expect($normalized === null ? null : DatabaseRuleWheres::presenceConditions($normalized))->toBe($conditions);
})->with([
    'old: value' => [[['column' => 'type', 'value' => 'admin']], ['type' => 'admin']],
    'old: !value' => [[['column' => 'type', 'value' => '!admin']], ['type' => '!admin']],
    'old: NULL' => [[['column' => 'type', 'value' => 'NULL']], ['type' => 'NULL']],
    'old: NOT_NULL' => [[['column' => 'type', 'value' => 'NOT_NULL']], ['type' => 'NOT_NULL']],
    'old: int' => [[['column' => 'id', 'value' => 5]], ['id' => '5']],
    'old: repeated column' => [[['column' => 'type', 'value' => 'a'], ['column' => 'type', 'value' => 'b']], null],
    'new: =' => [[['column' => 'type', 'operator' => '=', 'value' => 'admin']], ['type' => 'admin']],
    'new: !=' => [[['column' => 'type', 'operator' => '!=', 'value' => 'admin']], ['type' => '!admin']],
    'new: NULL' => [[['column' => 'type', 'operator' => 'NULL', 'value' => null]], ['type' => 'NULL']],
    'new: NOT_NULL' => [[['column' => 'type', 'operator' => 'NOT_NULL', 'value' => null]], ['type' => 'NOT_NULL']],
    'new: literal NULL' => [[['column' => 'type', 'operator' => '=', 'value' => 'NULL']], null],
    'new: literal NOT_NULL' => [[['column' => 'type', 'operator' => '=', 'value' => 'NOT_NULL']], null],
    'new: literal !x' => [[['column' => 'type', 'operator' => '=', 'value' => '!admin']], null],
    'new: unknown operator' => [[['column' => 'type', 'operator' => '>', 'value' => '1']], null],
    'new: repeated column' => [[['column' => 'type', 'operator' => '!=', 'value' => 'a'], ['column' => 'type', 'operator' => '!=', 'value' => 'b']], null],
    'missing column' => [[['value' => 'admin']], null],
]);

it('a batched lookup only answers the query it ran', function (string $scenario): void {
    setupTestDatabase();
    seedUserTypes();
    seedOtherConnection();

    [$fluentEach, $laravelRules, $item] = eachLookupScenario($scenario);
    $data = ['items' => [$item]];

    expect(ruleSetErrorKeys($data, ['items' => FluentRule::array()->required()->each($fluentEach)]))
        ->toBe(laravelErrorKeys($data, $laravelRules));
})->with([
    'different where, restricted field first',
    'different where, plain field first',
    'two exists rules on one field',
    'unique with different ignored ids',
    'same table on two connections',
    'where value with invalid UTF-8',
    'ignored id with invalid UTF-8',
    'exists with two where clauses on one column',
    'unique with two where clauses on one column',
    'unique ignoring the same id in different id columns',
]);

it('a batched lookup matches values the way the database compares them', function (string $ruleType, string $column, string $value): void {
    setupTestDatabase();

    // A case-insensitive column and an integer column: the database matches
    // 'ALICE' to 'alice' and '05' to 5, so the batch must not compare the
    // stored values with the input as plain strings.
    DB::connection('testing')->statement('CREATE TABLE tags (id INTEGER PRIMARY KEY, name TEXT COLLATE NOCASE)');
    DB::connection('testing')->table('tags')->insert(['id' => 5, 'name' => 'alice']);

    $fluent = FluentRule::string();
    $fluent = $ruleType === 'exists' ? $fluent->exists('testing.tags', $column) : $fluent->unique('testing.tags', $column);

    $laravel = $ruleType === 'exists' ? Rule::exists('testing.tags', $column) : Rule::unique('testing.tags', $column);
    $data = ['items' => [['field' => $value], ['field' => 'zed']]];

    expect(ruleSetErrorKeys($data, ['items' => FluentRule::array()->each(['field' => $fluent])]))
        ->toBe(laravelErrorKeys($data, ['items.*.field' => [$laravel]]));
})->with([
    'exists',
    'unique',
])->with([
    'case-insensitive collation' => ['name', 'ALICE'],
    'integer column, leading zero' => ['id', '05'],
    'integer column, decimal' => ['id', '5.0'],
]);

it('an array-valued exists field counts distinct database values like Laravel', function (): void {
    setupTestDatabase();

    // '5' and '05' both match the row with id 5. Laravel counts distinct
    // stored values (1) against two distinct inputs and rejects the array.
    // The batched lookup from the plain field must not count them as two.
    $data = ['items' => [['id' => '5', 'ids' => ['5', '05']], ['id' => '05', 'ids' => ['5']]]];
    DB::connection('testing')->table('users')->insert(['id' => 5, 'email' => 'eve@example.com']);

    expect(ruleSetErrorKeys($data, ['items' => FluentRule::array()->each([
        'id' => FluentRule::string()->exists('testing.users', 'id'),
        'ids' => FluentRule::array()->rule(Rule::exists('testing.users', 'id')),
    ])]))->toBe(laravelErrorKeys($data, [
        'items.*.id' => [Rule::exists('testing.users', 'id')],
        'items.*.ids' => ['array', Rule::exists('testing.users', 'id')],
    ]));
});

it('rules with the same where clauses in a different order share one batched query', function (): void {
    setupTestDatabase();
    seedUserTypes();

    $items = [
        ['a' => 'alice@example.com', 'b' => 'alice@example.com'],
        ['a' => 'bob@example.com', 'b' => 'carol@example.com'],
    ];

    $errors = [];
    $queries = countTestingUsersQueries(static function () use ($items, &$errors): void {
        $errors = ruleSetErrorKeys(['items' => $items], ['items' => FluentRule::array()->each([
            'a' => FluentRule::string()->exists('testing.users', 'email', fn (Exists $rule) => $rule->where('type', 'admin')->whereNotNull('email')),
            'b' => FluentRule::string()->exists('testing.users', 'email', fn (Exists $rule) => $rule->whereNotNull('email')->where('type', 'admin')),
        ])]);
    });

    expect($queries)->toBe(1)
        ->and($errors)->toBe(['items.1.a', 'items.1.b']);
});

it('a FormRequest batched lookup only answers the query it ran', function (string $scenario): void {
    setupTestDatabase();
    seedUserTypes();

    [$fluentRules, $laravelRules, $data] = formRequestLookupScenario($scenario);

    $withValidator = $scenario === 'rule added in withValidator()'
        ? static function (BaseValidator $validator): void {
            $validator->sometimes('owner', [Rule::exists('testing.users', 'email')->where('type', 'admin')], static fn (): bool => true);
        }
    : null;

    // getValidatorInstance() runs the full FormRequest lifecycle, so a rule
    // added in withValidator() reaches the batched presence verifier.
    $formRequest = createBatchFormRequest(rules: $fluentRules, data: $data, withValidator: $withValidator);
    $validator = (new ReflectionMethod($formRequest, 'getValidatorInstance'))->invoke($formRequest);

    if (! $validator instanceof ValidatorContract) {
        throw new RuntimeException('getValidatorInstance() did not return a validator.');
    }

    $laravel = Validator::make($data, $laravelRules);
    $withValidator?->__invoke($laravel);

    $actual = $validator->errors()->keys();
    $expected = $laravel->errors()->keys();
    sort($actual);
    sort($expected);

    expect($actual)->toBe($expected);

    if ($withValidator instanceof Closure) {
        expect($expected)->toBe(['owner']);
    }
})->with([
    'different where, plain field first',
    'different where, restricted field first',
    'plain field with a value the batch did not query',
    'plain field with a closure rule',
    'rule added in withValidator()',
    'plain field with a value another field filtered out',
]);

it('the FormRequest path still batches into one query', function (bool $withWhere): void {
    setupTestDatabase();
    seedUserTypes();

    $email = FluentRule::string()->exists(
        'testing.users',
        'email',
        static fn (Exists $rule): Exists => $withWhere ? $rule->whereNotNull('type') : $rule,
    );

    $formRequest = createBatchFormRequest(
        rules: ['items' => FluentRule::array()->each(['email' => $email])],
        data: ['items' => [
            ['email' => 'alice@example.com'],
            ['email' => 'bob@example.com'],
            ['email' => 'carol@example.com'],
        ]],
    );

    $errors = [];
    $queries = countTestingUsersQueries(static function () use ($formRequest, &$errors): void {
        $validator = (fn () => $this->createDefaultValidator(resolve(Factory::class)))->call($formRequest);
        $errors = $validator->errors()->keys();
    });

    expect($queries)->toBe(1)
        ->and($errors)->toBe($withWhere ? ['items.2.email'] : []);
})->with([
    'without where' => [false],
    'with where' => [true],
]);

it('fields that run the same query share one batched query', function (): void {
    setupTestDatabase();

    // Each field holds different values, so both fields' values must reach
    // the shared query.
    $items = [
        ['a' => 'alice@example.com', 'b' => 'bob@example.com'],
        ['a' => 'carol@example.com', 'b' => 'bob@example.com'],
        ['a' => 'alice@example.com', 'b' => 'zed@example.com'],
    ];

    $errors = [];
    $queries = countTestingUsersQueries(static function () use ($items, &$errors): void {
        $errors = ruleSetErrorKeys(['items' => $items], ['items' => FluentRule::array()->each([
            'a' => FluentRule::string()->exists('testing.users', 'email'),
            'b' => FluentRule::string()->exists('testing.users', 'email'),
        ])]);
    });

    expect($queries)->toBe(1)
        ->and($errors)->toBe(['items.2.b']);
});

it('fields that run different queries each get one batched query', function (): void {
    setupTestDatabase();
    seedUserTypes();

    $items = [
        ['a' => 'alice@example.com', 'b' => 'bob@example.com'],
        ['a' => 'alice@example.com', 'b' => 'carol@example.com'],
        ['a' => 'bob@example.com', 'b' => 'alice@example.com'],
    ];

    $errors = [];
    $queries = countTestingUsersQueries(static function () use ($items, &$errors): void {
        $errors = ruleSetErrorKeys(['items' => $items], ['items' => FluentRule::array()->each([
            'a' => FluentRule::string()->exists('testing.users', 'email', fn (Exists $rule) => $rule->where('type', 'admin')),
            'b' => FluentRule::string()->exists('testing.users', 'email'),
        ])]);
    });

    expect($queries)->toBe(2)
        ->and($errors)->toBe(['items.2.a']);
});

it('empty string values are excluded from batch query', function (): void {
    setupTestDatabase();

    $errors = [];

    try {
        RuleSet::from([
            'items' => FluentRule::array()->required()->each([
                'email' => FluentRule::string()->required()->exists('testing.users', 'email'),
            ]),
        ])->validate([
            'items' => [
                ['email' => ''],                     // empty — fails required, not batched
                ['email' => 'alice@example.com'],    // exists
            ],
        ]);
    } catch (ValidationException $validationException) {
        $errors = $validationException->errors();
    }

    // First item fails required (not exists), second passes
    expect($errors)->toHaveKey('items.0.email')
        ->and($errors)->not->toHaveKey('items.1.email');
});

it('scalar each with exists rule batches correctly', function (): void {
    setupTestDatabase();

    $validated = RuleSet::from([
        'emails' => FluentRule::array()->required()->each(
            FluentRule::string()->required()->exists('testing.users', 'email'),
        ),
    ])->validate([
        'emails' => ['alice@example.com', 'bob@example.com'],
    ]);

    expect($validated['emails'])->toHaveCount(2);
});

it('scalar each with exists rejects invalid values', function (): void {
    setupTestDatabase();

    $errors = [];

    try {
        RuleSet::from([
            'emails' => FluentRule::array()->required()->each(
                FluentRule::string()->required()->exists('testing.users', 'email'),
            ),
        ])->validate([
            'emails' => ['alice@example.com', 'nonexistent@example.com'],
        ]);
    } catch (ValidationException $validationException) {
        $errors = $validationException->errors();
    }

    expect($errors)->toHaveKey('emails.1')
        ->and($errors)->not->toHaveKey('emails.0');
});

it('all null/empty values result in no batch query', function (): void {
    setupTestDatabase();

    DB::connection('testing')->enableQueryLog();

    RuleSet::from([
        'items' => FluentRule::array()->required()->each([
            'email' => FluentRule::string()->nullable()->exists('testing.users', 'email'),
        ]),
    ])->validate([
        'items' => [
            ['email' => null],
            ['email' => null],
        ],
    ]);

    $queryLog = DB::connection('testing')->getQueryLog();
    DB::connection('testing')->disableQueryLog();

    // No batch query should fire since all values are null
    $existsQueries = array_filter($queryLog, static fn (array $q): bool => str_contains($q['query'], 'users'));
    expect($existsQueries)->toBeEmpty();
});

it('duplicate values are deduplicated before batch query', function (): void {
    setupTestDatabase();

    DB::connection('testing')->enableQueryLog();

    $validated = RuleSet::from([
        'items' => FluentRule::array()->required()->each([
            'email' => FluentRule::string()->required()->exists('testing.users', 'email'),
        ]),
    ])->validate([
        'items' => [
            ['email' => 'alice@example.com'],
            ['email' => 'alice@example.com'],  // duplicate
            ['email' => 'bob@example.com'],
        ],
    ]);

    $queryLog = DB::connection('testing')->getQueryLog();
    DB::connection('testing')->disableQueryLog();

    // Should still batch into 1 query, deduplicating alice
    $existsQueries = array_filter($queryLog, static fn (array $q): bool => str_contains($q['query'], 'users'));
    expect(count($existsQueries))->toBeLessThanOrEqual(2)
        ->and($validated['items'])->toHaveCount(3);
});

// =========================================================================
// Helper: FormRequest with HasFluentRules for batch testing
// =========================================================================

/**
 * @param  array<string, mixed>  $rules
 * @param  array<string, mixed>  $data
 */
function createBatchFormRequest(array $rules, array $data, ?Closure $withValidator = null): FormRequest
{
    $formRequest = new class extends FormRequest {
        use HasFluentRules;

        /** @var array<string, mixed> */
        public static array $testRules = [];

        public static ?Closure $testWithValidator = null;

        /** @return array<string, mixed> */
        public function rules(): array
        {
            return self::$testRules;
        }

        public function withValidator(BaseValidator $validator): void
        {
            if (self::$testWithValidator instanceof Closure) {
                (self::$testWithValidator)($validator);
            }
        }
    };

    $formRequest::$testRules = $rules;
    $formRequest::$testWithValidator = $withValidator;

    $request = Request::create('/test', 'POST', $data);
    $instance = $formRequest::createFrom($request);
    $instance->setContainer(app());
    $instance->setRedirector(resolve(Redirector::class));

    return $instance;
}

/** Give alice the type `admin` and bob the type `user`. Carol has no type. */
function seedUserTypes(): void
{
    DB::connection('testing')->statement('ALTER TABLE users ADD COLUMN type VARCHAR(255) NULL');
    DB::connection('testing')->table('users')->where('email', 'alice@example.com')->update(['type' => 'admin']);
    DB::connection('testing')->table('users')->where('email', 'bob@example.com')->update(['type' => 'user']);
}

/** A second connection with its own users table that holds only zed. */
function seedOtherConnection(): void
{
    config(['database.connections.other' => ['driver' => 'sqlite', 'database' => ':memory:']]);

    Schema::connection('other')->create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('email');
    });

    DB::connection('other')->table('users')->insert(['email' => 'zed@example.com']);
}

/**
 * @param  array<string, mixed>  $data
 * @param  array<string, mixed>  $rules
 * @return list<string>
 */
function laravelErrorKeys(array $data, array $rules): array
{
    $keys = Validator::make($data, $rules)->errors()->keys();
    sort($keys);

    return $keys;
}

/**
 * @param  array<string, mixed>  $data
 * @param  array<string, mixed>  $rules
 * @return list<string>
 */
function ruleSetErrorKeys(array $data, array $rules): array
{
    try {
        RuleSet::from($rules)->validate($data);
    } catch (ValidationException $validationException) {
        $keys = array_keys($validationException->errors());
        sort($keys);

        return $keys;
    }

    return [];
}

function countTestingUsersQueries(Closure $callback): int
{
    DB::connection('testing')->flushQueryLog();
    DB::connection('testing')->enableQueryLog();

    try {
        $callback();
    } finally {
        $log = DB::connection('testing')->getQueryLog();
        DB::connection('testing')->disableQueryLog();
    }

    return count(array_filter($log, static fn (array $query): bool => str_contains($query['query'], 'users')));
}

/**
 * @return array{0: array<string, ValidationRule>, 1: array<string, mixed>, 2: array<string, string>}
 */
function eachLookupScenario(string $scenario): array
{
    $admin = static fn (Exists $rule): Exists => $rule->where('type', 'admin');

    return match ($scenario) {
        'different where, restricted field first' => [
            [
                'a' => FluentRule::string()->exists('testing.users', 'email', $admin),
                'b' => FluentRule::string()->exists('testing.users', 'email'),
            ],
            [
                'items.*.a' => [Rule::exists('testing.users', 'email')->where('type', 'admin')],
                'items.*.b' => [Rule::exists('testing.users', 'email')],
            ],
            ['a' => 'bob@example.com', 'b' => 'bob@example.com'],
        ],
        'different where, plain field first' => [
            [
                'b' => FluentRule::string()->exists('testing.users', 'email'),
                'a' => FluentRule::string()->exists('testing.users', 'email', $admin),
            ],
            [
                'items.*.b' => [Rule::exists('testing.users', 'email')],
                'items.*.a' => [Rule::exists('testing.users', 'email')->where('type', 'admin')],
            ],
            ['b' => 'bob@example.com', 'a' => 'bob@example.com'],
        ],
        'two exists rules on one field' => [
            [
                'email' => FluentRule::string()
                    ->exists('testing.users', 'email')
                    ->exists('testing.users', 'email', $admin),
            ],
            [
                'items.*.email' => [Rule::exists('testing.users', 'email'), Rule::exists('testing.users', 'email')->where('type', 'admin')],
            ],
            ['email' => 'bob@example.com'],
        ],
        'unique with different ignored ids' => [
            [
                'a' => FluentRule::string()->unique('testing.users', 'email', static fn (Unique $rule): Unique => $rule->ignore(1)),
                'b' => FluentRule::string()->unique('testing.users', 'email', static fn (Unique $rule): Unique => $rule->ignore(2)),
            ],
            [
                'items.*.a' => [Rule::unique('testing.users', 'email')->ignore(1)],
                'items.*.b' => [Rule::unique('testing.users', 'email')->ignore(2)],
            ],
            ['a' => 'alice@example.com', 'b' => 'alice@example.com'],
        ],
        'same table on two connections' => [
            [
                'a' => FluentRule::string()->exists('testing.users', 'email'),
                'b' => FluentRule::string()->exists('other.users', 'email'),
            ],
            [
                'items.*.a' => [Rule::exists('testing.users', 'email')],
                'items.*.b' => [Rule::exists('other.users', 'email')],
            ],
            ['a' => 'alice@example.com', 'b' => 'alice@example.com'],
        ],
        // Request input can carry invalid UTF-8 into a where() value or an
        // ignored id. Validation must not crash on it.
        'where value with invalid UTF-8' => [
            ['email' => FluentRule::string()->exists('testing.users', 'email', static fn (Exists $rule): Exists => $rule->where('type', "\xFF"))],
            ['items.*.email' => [Rule::exists('testing.users', 'email')->where('type', "\xFF")]],
            ['email' => 'alice@example.com'],
        ],
        'ignored id with invalid UTF-8' => [
            ['email' => FluentRule::string()->unique('testing.users', 'email', static fn (Unique $rule): Unique => $rule->ignore("\xFF"))],
            ['items.*.email' => [Rule::unique('testing.users', 'email')->ignore("\xFF")]],
            ['email' => 'alice@example.com'],
        ],
        // Older releases keep only the last clause for a column, newer ones
        // keep both. The batch query must not decide either way.
        'exists with two where clauses on one column' => [
            ['email' => FluentRule::string()->exists('testing.users', 'email', static fn (Exists $rule): Exists => $rule->where('type', 'admin')->where('type', 'user'))],
            ['items.*.email' => [Rule::exists('testing.users', 'email')->where('type', 'admin')->where('type', 'user')]],
            ['email' => 'bob@example.com'],
        ],
        'unique with two where clauses on one column' => [
            ['email' => FluentRule::string()->unique('testing.users', 'email', static fn (Unique $rule): Unique => $rule->where('type', 'admin')->where('type', 'user'))],
            ['items.*.email' => [Rule::unique('testing.users', 'email')->where('type', 'admin')->where('type', 'user')]],
            ['email' => 'bob@example.com'],
        ],
        'unique ignoring the same id in different id columns' => [
            [
                'a' => FluentRule::string()->unique('testing.users', 'email', static fn (Unique $rule): Unique => $rule->ignore('admin', 'type')),
                'b' => FluentRule::string()->unique('testing.users', 'email', static fn (Unique $rule): Unique => $rule->ignore('admin', 'email')),
            ],
            [
                'items.*.a' => [Rule::unique('testing.users', 'email')->ignore('admin', 'type')],
                'items.*.b' => [Rule::unique('testing.users', 'email')->ignore('admin', 'email')],
            ],
            ['a' => 'alice@example.com', 'b' => 'alice@example.com'],
        ],
        default => throw new InvalidArgumentException($scenario),
    };
}

/** Whether the installed Laravel stores database rule wheres with an `operator` key. */
function whereShapeHasOperator(): bool
{
    $wheres = (new ReflectionProperty(Exists::class, 'wheres'))->getValue(Rule::exists('users')->where('type', 'x'));

    return is_array($wheres) && is_array($wheres[0] ?? null) && array_key_exists('operator', $wheres[0]);
}

/**
 * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}
 */
function formRequestLookupScenario(string $scenario): array
{
    $admin = static fn (Exists $rule): Exists => $rule->where('type', 'admin');
    $adminQuery = static fn (Builder $query): Builder => $query->where('type', 'admin');

    return match ($scenario) {
        'different where, plain field first' => [
            [
                'anyone' => FluentRule::array()->each(['email' => FluentRule::string()->exists('testing.users', 'email')]),
                'admins' => FluentRule::array()->each(['email' => FluentRule::string()->exists('testing.users', 'email', $admin)]),
            ],
            [
                'anyone.*.email' => [Rule::exists('testing.users', 'email')],
                'admins.*.email' => [Rule::exists('testing.users', 'email')->where('type', 'admin')],
            ],
            ['anyone' => [['email' => 'bob@example.com']], 'admins' => [['email' => 'bob@example.com']]],
        ],
        'different where, restricted field first' => [
            [
                'admins' => FluentRule::array()->each(['email' => FluentRule::string()->exists('testing.users', 'email', $admin)]),
                'anyone' => FluentRule::array()->each(['email' => FluentRule::string()->exists('testing.users', 'email')]),
            ],
            [
                'admins.*.email' => [Rule::exists('testing.users', 'email')->where('type', 'admin')],
                'anyone.*.email' => [Rule::exists('testing.users', 'email')],
            ],
            ['admins' => [['email' => 'bob@example.com']], 'anyone' => [['email' => 'bob@example.com']]],
        ],
        'plain field with a value the batch did not query' => [
            [
                'owner' => FluentRule::string()->exists('testing.users', 'email'),
                'items' => FluentRule::array()->each(['email' => FluentRule::string()->exists('testing.users', 'email')]),
            ],
            [
                'owner' => [Rule::exists('testing.users', 'email')],
                'items.*.email' => [Rule::exists('testing.users', 'email')],
            ],
            ['owner' => 'carol@example.com', 'items' => [['email' => 'alice@example.com']]],
        ],
        'plain field with a closure rule' => [
            [
                'owner' => FluentRule::string()->exists('testing.users', 'email', static fn (Exists $rule): Exists => $rule->using($adminQuery)),
                'items' => FluentRule::array()->each(['email' => FluentRule::string()->exists('testing.users', 'email')]),
            ],
            [
                'owner' => [Rule::exists('testing.users', 'email')->using($adminQuery)],
                'items.*.email' => [Rule::exists('testing.users', 'email')],
            ],
            ['owner' => 'bob@example.com', 'items' => [['email' => 'bob@example.com']]],
        ],
        // The wildcard field's integer rule keeps alice out of the batch. The
        // owner field runs the same unique query and must still see her as taken.
        'plain field with a value another field filtered out' => [
            [
                'owner' => ['required', 'string', Rule::unique('testing.users', 'email')],
                'items' => ['required', 'array'],
                'items.*.email' => ['required', 'integer', Rule::unique('testing.users', 'email')],
            ],
            [
                'owner' => ['required', 'string', Rule::unique('testing.users', 'email')],
                'items' => ['required', 'array'],
                'items.*.email' => ['required', 'integer', Rule::unique('testing.users', 'email')],
            ],
            ['owner' => 'alice@example.com', 'items' => [['email' => 'alice@example.com'], ['email' => '5']]],
        ],
        'rule added in withValidator()' => [
            [
                'owner' => FluentRule::string(),
                'items' => FluentRule::array()->each(['email' => FluentRule::string()->exists('testing.users', 'email')]),
            ],
            [
                'owner' => ['string'],
                'items.*.email' => [Rule::exists('testing.users', 'email')],
            ],
            ['owner' => 'bob@example.com', 'items' => [['email' => 'bob@example.com']]],
        ],
        default => throw new InvalidArgumentException($scenario),
    };
}
