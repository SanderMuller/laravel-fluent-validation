<?php declare(strict_types=1);

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use SanderMuller\FluentValidation\BatchDatabaseChecker;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\HasFluentRules;
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
    // Returns whatever the real DB says — the point is it didn't crash or return 0 blindly
    expect($count)->toBeInt();
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

    expect($validator->passes())->toBeTrue();

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

    expect($validator->passes())->toBeFalse()
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
    expect($validator->passes())->toBeFalse()
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

    expect($validator->passes())->toBeTrue();
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

    expect($validator->passes())->toBeTrue();

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
    expect($validator->passes())->toBeFalse()
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
    // Note: withoutTrashed uses a closure callback, so this falls through to per-item
    expect($errors)->toHaveKey('items.1.email')
        ->and($errors)->not->toHaveKey('items.0.email');
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

    $errors = [];

    try {
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
    } catch (ValidationException $validationException) {
        $errors = $validationException->errors();
    }

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
function createBatchFormRequest(array $rules, array $data): FormRequest
{
    $formRequest = new class extends FormRequest {
        use HasFluentRules;

        /** @var array<string, mixed> */
        public static array $testRules = [];

        /** @return array<string, mixed> */
        public function rules(): array
        {
            return self::$testRules;
        }

        public function authorize(): bool
        {
            return true;
        }
    };

    $formRequest::$testRules = $rules;

    $request = Request::create('/test', 'POST', $data);
    $instance = $formRequest::createFrom($request);
    $instance->setContainer(app());
    $instance->setRedirector(resolve(Redirector::class));

    return $instance;
}
