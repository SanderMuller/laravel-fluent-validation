<?php declare(strict_types=1);

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use SanderMuller\FluentValidation\BatchDatabaseChecker;
use SanderMuller\FluentValidation\Exceptions\BatchLimitExceededException;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\HasFluentRules;
use SanderMuller\FluentValidation\RuleSet;

// =========================================================================
// Helper: set up in-memory SQLite with mixed integer + uuid tables
// =========================================================================

function setupGuardsDatabase(): void
{
    config(['database.default' => 'testing']);
    config(['database.connections.testing' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]]);

    Schema::connection('testing')->create('widgets', function (Blueprint $table): void {
        $table->id();
        $table->string('uuid', 36)->unique();
        $table->timestamps();
    });

    DB::connection('testing')->table('widgets')->insert([
        ['uuid' => '11111111-2222-3333-4444-555555555555', 'created_at' => now(), 'updated_at' => now()],
        ['uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'created_at' => now(), 'updated_at' => now()],
        ['uuid' => '01010101-0101-0101-0101-010101010101', 'created_at' => now(), 'updated_at' => now()],
    ]);
}

/**
 * @param  array<string, mixed>  $rules
 * @param  array<string, mixed>  $data
 */
function createGuardsFormRequest(array $rules, array $data): FormRequest
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

// =========================================================================
// filterValuesByType — unit tests
// =========================================================================

it('filterValuesByType keeps integer-like values and drops garbage for integer rule', function (): void {
    $result = BatchDatabaseChecker::filterValuesByType(
        [1, '2', 'abc', 3.5, '4', null, ''],
        ['integer'],
    );

    // filter_var validates INT strings; 3.5 is not valid int; null/'' drop out
    expect($result)->toBe([1, '2', '4']);
});

it('filterValuesByType keeps numeric values and drops non-numeric for numeric rule', function (): void {
    $result = BatchDatabaseChecker::filterValuesByType(
        [1, '2.5', 'abc', '1e3', null],
        ['numeric'],
    );

    expect($result)->toBe([1, '2.5', '1e3']);
});

it('filterValuesByType keeps UUIDs and drops malformed for uuid rule', function (): void {
    $result = BatchDatabaseChecker::filterValuesByType(
        [
            '11111111-2222-3333-4444-555555555555',
            'not-a-uuid',
            'AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE', // case-insensitive
            'abc',
            123,
        ],
        ['required', 'uuid'],
    );

    expect($result)->toBe([
        '11111111-2222-3333-4444-555555555555',
        'AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE',
    ]);
});

it('filterValuesByType handles parameterised type tokens (uuid:4)', function (): void {
    $result = BatchDatabaseChecker::filterValuesByType(
        ['11111111-2222-3333-4444-555555555555', 'bad'],
        ['uuid:4'],
    );

    expect($result)->toBe(['11111111-2222-3333-4444-555555555555']);
});

it('filterValuesByType keeps ULIDs and drops malformed for ulid rule', function (): void {
    $result = BatchDatabaseChecker::filterValuesByType(
        ['01ARZ3NDEKTSV4RRFFQ69G5FAV', 'not-a-ulid', 'I0000000000000000000000000'],
        ['ulid'],
    );

    // ULID excludes I, L, O, U; first passes, second & third reject
    expect($result)->toBe(['01ARZ3NDEKTSV4RRFFQ69G5FAV']);
});

it('filterValuesByType preserves scalar and Stringable for string rule', function (): void {
    $stringable = new class implements Stringable {
        public function __toString(): string
        {
            return 'x';
        }
    };

    $result = BatchDatabaseChecker::filterValuesByType(
        ['a', 1, 2.5, true, $stringable, ['not-scalar']],
        ['string'],
    );

    expect($result)->toHaveCount(5); // drops the array
});

it('filterValuesByType returns values unchanged when no known type rule present', function (): void {
    $result = BatchDatabaseChecker::filterValuesByType(
        [1, 'abc', null],
        ['required', 'exists:users,id'],
    );

    expect($result)->toBe([1, 'abc', null]);
});

it('filterValuesByType accepts pipe-delimited string rule form', function (): void {
    $result = BatchDatabaseChecker::filterValuesByType(
        [1, '2', 'abc'],
        'required|integer|exists:users,id',
    );

    expect($result)->toBe([1, '2']);
});

it('filterValuesByType applies multiple type rules as AND', function (): void {
    // integer + numeric together — both must pass. 2.5 is numeric but not int.
    $result = BatchDatabaseChecker::filterValuesByType(
        [1, '2', 2.5, 'abc'],
        ['integer', 'numeric'],
    );

    expect($result)->toBe([1, '2']);
});

it('filterValuesByType returns empty list when input is empty', function (): void {
    expect(BatchDatabaseChecker::filterValuesByType([], ['integer']))->toBeEmpty();
});

// =========================================================================
// Integration: bad-type values never reach the query log
// =========================================================================

it('FormRequest path skips bad-type integer values when batching exists', function (): void {
    setupGuardsDatabase();

    $formRequest = createGuardsFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->each([
                'id' => FluentRule::integer()->required()->exists('testing.widgets', 'id'),
            ]),
        ],
        data: [
            'items' => [
                ['id' => 1],
                ['id' => 'abc'],   // hostile — would crash PG
                ['id' => 2],
                ['id' => '3.5'],   // not an int
            ],
        ],
    );

    DB::connection('testing')->enableQueryLog();

    $factory = resolve(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);
    $validator->passes();

    $queryLog = DB::connection('testing')->getQueryLog();
    DB::connection('testing')->disableQueryLog();

    $widgetQueries = array_values(array_filter(
        $queryLog,
        static fn (array $q): bool => str_contains($q['query'], 'widgets'),
    ));

    // One batched whereIn; bindings only contain valid ints.
    expect($widgetQueries)->toHaveCount(1);
    $bindings = array_map(
        static fn (mixed $b): string => is_scalar($b) ? (string) $b : '',
        $widgetQueries[0]['bindings'],
    );
    expect($bindings)->toContain('1')
        ->and($bindings)->toContain('2')
        ->and($bindings)->not->toContain('abc')
        ->and($bindings)->not->toContain('3.5');
});

it('FormRequest path skips malformed UUID values when batching exists', function (): void {
    setupGuardsDatabase();

    $formRequest = createGuardsFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->each([
                'uuid' => FluentRule::string()->required()->uuid()->exists('testing.widgets', 'uuid'),
            ]),
        ],
        data: [
            'items' => [
                ['uuid' => '11111111-2222-3333-4444-555555555555'],
                ['uuid' => 'not-a-uuid'],
                ['uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'],
            ],
        ],
    );

    DB::connection('testing')->enableQueryLog();

    $factory = resolve(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);
    $validator->passes();

    $queryLog = DB::connection('testing')->getQueryLog();
    DB::connection('testing')->disableQueryLog();

    $widgetQueries = array_values(array_filter(
        $queryLog,
        static fn (array $q): bool => str_contains($q['query'], 'widgets'),
    ));

    expect($widgetQueries)->toHaveCount(1);
    $bindings = $widgetQueries[0]['bindings'];
    expect($bindings)->not->toContain('not-a-uuid');
});

it('FormRequest path preserves current behaviour when no type rule present', function (): void {
    setupGuardsDatabase();

    // No known type rule (integer/numeric/uuid/ulid/string) on the item — all
    // values are cast to string and passed through as before.
    $formRequest = createGuardsFormRequest(
        rules: [
            // @phpstan-ignore argument.type
            'items' => FluentRule::array()->required()->each([
                'id' => ['required', Rule::exists('testing.widgets', 'id')],
            ]),
        ],
        data: [
            'items' => [
                ['id' => 1],
                ['id' => 'abc'],
                ['id' => 2],
            ],
        ],
    );

    DB::connection('testing')->enableQueryLog();

    $factory = resolve(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);
    $validator->passes();

    $queryLog = DB::connection('testing')->getQueryLog();
    DB::connection('testing')->disableQueryLog();

    $widgetQueries = array_values(array_filter(
        $queryLog,
        static fn (array $q): bool => str_contains($q['query'], 'widgets'),
    ));

    expect($widgetQueries)->toHaveCount(1);
    $bindings = array_map(
        static fn (mixed $b): string => is_scalar($b) ? (string) $b : '',
        $widgetQueries[0]['bindings'],
    );
    // Without a type rule, 'abc' reaches the query like before.
    expect($bindings)->toContain('abc');
});

it('RuleSet path skips bad-type integer values when batching exists', function (): void {
    setupGuardsDatabase();

    DB::connection('testing')->enableQueryLog();

    $result = RuleSet::from([
        'items' => FluentRule::array()->required()->each([
            'id' => FluentRule::integer()->required()->exists('testing.widgets', 'id'),
        ]),
    ])->check([
        'items' => [
            ['id' => 1],
            ['id' => 'abc'],
            ['id' => 2],
        ],
    ]);

    $queryLog = DB::connection('testing')->getQueryLog();
    DB::connection('testing')->disableQueryLog();

    $widgetQueries = array_values(array_filter(
        $queryLog,
        static fn (array $q): bool => str_contains($q['query'], 'widgets'),
    ));

    // Batched query must not carry 'abc' as a binding.
    foreach ($widgetQueries as $query) {
        $bindings = array_map(
            static fn (mixed $b): string => is_scalar($b) ? (string) $b : '',
            $query['bindings'],
        );
        expect($bindings)->not->toContain('abc');
    }

    // Validation still fails because 'abc' violates the per-item integer rule.
    expect($result->fails())->toBeTrue();
});

// =========================================================================
// Phase 2: group-key disambiguation
// =========================================================================

it('exists and unique against same (table, column) fall back to per-item queries (conflict skip)', function (): void {
    setupGuardsDatabase();

    // Same (table, column) carrying both an exists and a unique group cannot
    // be unambiguously routed through the shared PresenceVerifier interface
    // — `getCount` is called by both rule types. registerLookups detects the
    // conflict and registers neither lookup, letting the fallback
    // DatabasePresenceVerifier handle each rule with a real per-item query.
    $existsRule = Rule::exists('testing.widgets', 'uuid');
    $uniqueRule = Rule::unique('testing.widgets', 'uuid');

    $groups = [
        'widgets:uuid:exists' => [
            'rule' => $existsRule,
            'values' => ['11111111-2222-3333-4444-555555555555'],
        ],
        'widgets:uuid:unique' => [
            'rule' => $uniqueRule,
            'values' => ['99999999-0000-0000-0000-000000000000'],
        ],
    ];

    $verifier = BatchDatabaseChecker::buildVerifier($groups);

    // Verifier is null because no lookups were registered (both groups skipped).
    expect($verifier)->toBeNull();
});

it('non-conflicting (different columns) exists and unique groups are both registered', function (): void {
    setupGuardsDatabase();

    // Same table but DIFFERENT columns — no ambiguity, both lookups register.
    $existsRule = Rule::exists('testing.widgets', 'uuid');
    $uniqueRule = Rule::unique('testing.widgets', 'id');

    $groups = [
        'widgets:uuid:exists' => [
            'rule' => $existsRule,
            'values' => ['11111111-2222-3333-4444-555555555555'],
        ],
        'widgets:id:unique' => [
            'rule' => $uniqueRule,
            'values' => ['1'],
        ],
    ];

    $verifier = BatchDatabaseChecker::buildVerifier($groups);

    expect($verifier)->not->toBeNull()
        ->and($verifier->getMultiCount('widgets', 'uuid', ['11111111-2222-3333-4444-555555555555']))->toBe(1)
        // id=1 exists in the table, so getCount returns 1 (unique fails, as expected).
        ->and($verifier->getCount('widgets', 'id', '1'))->toBe(1);
});

it('collectExpandedValues keeps exists and unique against same (table, column) in separate groups', function (): void {
    $rules = [
        // Two distinct concrete attributes, same (table, column), different rule types
        'items.0.email' => ['required', Rule::exists('users', 'email')],
        'items.1.email' => ['required', Rule::unique('users', 'email')],
    ];

    $groups = BatchDatabaseChecker::collectExpandedValues($rules, [
        'items' => [
            ['email' => 'alice@example.com'],
            ['email' => 'new@example.com'],
        ],
    ]);

    $keys = array_keys($groups);
    expect($keys)->toContain('users:email:exists')
        ->and($keys)->toContain('users:email:unique')
        ->and($groups)->toHaveCount(2)
        ->and($groups['users:email:exists']['values'])->toBe(['alice@example.com'])
        ->and($groups['users:email:unique']['values'])->toBe(['new@example.com']);
});

// =========================================================================
// Phase 2: hard-cap + exception + remap
// =========================================================================

it('buildVerifier throws BatchLimitExceededException when group exceeds cap', function (): void {
    $rule = Rule::exists('widgets', 'id');

    $values = [];
    for ($i = 1; $i <= 11; ++$i) {
        $values[] = (string) $i;
    }

    $groups = [
        'widgets:id:exists' => ['rule' => $rule, 'values' => $values],
    ];

    $previous = BatchDatabaseChecker::$maxValuesPerGroup;
    BatchDatabaseChecker::$maxValuesPerGroup = 10;

    try {
        BatchDatabaseChecker::buildVerifier($groups);
        $this->fail('Expected BatchLimitExceededException');
    } catch (BatchLimitExceededException $batchLimitExceededException) {
        expect($batchLimitExceededException->table)->toBe('widgets')
            ->and($batchLimitExceededException->column)->toBe('id')
            ->and($batchLimitExceededException->ruleType)->toBe('exists')
            ->and($batchLimitExceededException->reason)->toBe(BatchLimitExceededException::REASON_HARD_CAP)
            ->and($batchLimitExceededException->valueCount)->toBe(11)
            ->and($batchLimitExceededException->limit)->toBe(10)
            ->and($batchLimitExceededException->attribute)->toBeNull();
    } finally {
        BatchDatabaseChecker::$maxValuesPerGroup = $previous;
    }
});

it('buildVerifier carries ruleType=unique on hard-cap exception for unique rules', function (): void {
    $rule = Rule::unique('widgets', 'id');

    $groups = [
        'widgets:id:unique' => ['rule' => $rule, 'values' => ['a', 'b', 'c']],
    ];

    $previous = BatchDatabaseChecker::$maxValuesPerGroup;
    BatchDatabaseChecker::$maxValuesPerGroup = 2;

    try {
        BatchDatabaseChecker::buildVerifier($groups);
        $this->fail('Expected BatchLimitExceededException');
    } catch (BatchLimitExceededException $batchLimitExceededException) {
        expect($batchLimitExceededException->ruleType)->toBe('unique')
            ->and($batchLimitExceededException->reason)->toBe(BatchLimitExceededException::REASON_HARD_CAP);
    } finally {
        BatchDatabaseChecker::$maxValuesPerGroup = $previous;
    }
});

it('buildVerifier does not throw when group is at or below cap', function (): void {
    setupGuardsDatabase();

    $rule = Rule::exists('testing.widgets', 'id');
    $groups = [
        'widgets:id:exists' => ['rule' => $rule, 'values' => ['1', '2', '3']],
    ];

    $previous = BatchDatabaseChecker::$maxValuesPerGroup;
    BatchDatabaseChecker::$maxValuesPerGroup = 3;

    try {
        $verifier = BatchDatabaseChecker::buildVerifier($groups);
        expect($verifier)->not->toBeNull();
    } finally {
        BatchDatabaseChecker::$maxValuesPerGroup = $previous;
    }
});

it('FormRequest path remaps hard-cap breach to ValidationException', function (): void {
    setupGuardsDatabase();

    // Build 11 distinct valid ids; cap 10 so we trip the hard cap.
    $items = [];
    for ($i = 1; $i <= 11; ++$i) {
        $items[] = ['id' => $i];
    }

    $formRequest = createGuardsFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->each([
                'id' => FluentRule::integer()->required()->exists('testing.widgets', 'id'),
            ]),
        ],
        data: ['items' => $items],
    );

    $previous = BatchDatabaseChecker::$maxValuesPerGroup;
    BatchDatabaseChecker::$maxValuesPerGroup = 10;

    try {
        $factory = resolve(Factory::class);
        $caught = null;

        try {
            (fn () => $this->createDefaultValidator($factory))->call($formRequest);
        } catch (ValidationException $e) {
            $caught = $e;
        }

        expect($caught)->not->toBeNull()
            ->and($caught->validator->errors()->keys())->toContain('items.0.id');
    } finally {
        BatchDatabaseChecker::$maxValuesPerGroup = $previous;
    }
});

it('FormRequest path raw BatchLimitExceededException carries ruleType and reason pre-remap', function (): void {
    setupGuardsDatabase();

    $items = [];
    for ($i = 1; $i <= 11; ++$i) {
        $items[] = ['id' => $i];
    }

    // Call the collector + buildVerifier chain directly — bypasses the trait's
    // remap so we can inspect the raw exception payload.
    $preparedRules = [];
    foreach (array_keys($items) as $k) {
        $preparedRules["items.{$k}.id"] = ['integer', Rule::exists('testing.widgets', 'id')];
    }

    $groups = BatchDatabaseChecker::collectExpandedValues($preparedRules, ['items' => $items]);

    $previous = BatchDatabaseChecker::$maxValuesPerGroup;
    BatchDatabaseChecker::$maxValuesPerGroup = 10;

    try {
        $caught = null;
        try {
            BatchDatabaseChecker::buildVerifier($groups);
        } catch (BatchLimitExceededException $e) {
            $caught = $e;
        }

        expect($caught)->not->toBeNull()
            ->and($caught->ruleType)->toBe('exists')
            ->and($caught->reason)->toBe(BatchLimitExceededException::REASON_HARD_CAP)
            ->and($caught->valueCount)->toBe(11)
            ->and($caught->limit)->toBe(10)
            ->and($caught->attribute)->toBeNull();
    } finally {
        BatchDatabaseChecker::$maxValuesPerGroup = $previous;
    }
});

it('RuleSet check() returns failed Validated on hard-cap breach without throwing', function (): void {
    setupGuardsDatabase();

    $items = [];
    for ($i = 1; $i <= 11; ++$i) {
        $items[] = ['id' => $i];
    }

    $previous = BatchDatabaseChecker::$maxValuesPerGroup;
    BatchDatabaseChecker::$maxValuesPerGroup = 10;

    try {
        $result = RuleSet::from([
            'items' => FluentRule::array()->required()->each([
                'id' => FluentRule::integer()->required()->exists('testing.widgets', 'id'),
            ]),
        ])->check(['items' => $items]);

        expect($result->fails())->toBeTrue()
            ->and($result->errors()->isEmpty())->toBeFalse();
    } finally {
        BatchDatabaseChecker::$maxValuesPerGroup = $previous;
    }
});

it('RuleSet validate() throws ValidationException on hard-cap breach (not BatchLimitExceededException)', function (): void {
    setupGuardsDatabase();

    $items = [];
    for ($i = 1; $i <= 11; ++$i) {
        $items[] = ['id' => $i];
    }

    $previous = BatchDatabaseChecker::$maxValuesPerGroup;
    BatchDatabaseChecker::$maxValuesPerGroup = 10;

    try {
        $caught = null;
        try {
            RuleSet::from([
                'items' => FluentRule::array()->required()->each([
                    'id' => FluentRule::integer()->required()->exists('testing.widgets', 'id'),
                ]),
            ])->validate(['items' => $items]);
        } catch (ValidationException $e) {
            $caught = $e;
        }

        expect($caught)->not->toBeNull();
    } finally {
        BatchDatabaseChecker::$maxValuesPerGroup = $previous;
    }
});

it('duplicate values deduplicate before cap check (10001 identical ids with cap 10 do not trip)', function (): void {
    setupGuardsDatabase();

    // 10_001 identical ids — after dedup this is 1 value, well under any cap.
    $items = [];
    for ($i = 0; $i < 10_001; ++$i) {
        $items[] = ['id' => 1];
    }

    $previous = BatchDatabaseChecker::$maxValuesPerGroup;
    BatchDatabaseChecker::$maxValuesPerGroup = 10;

    try {
        $result = RuleSet::from([
            'items' => FluentRule::array()->required()->each([
                'id' => FluentRule::integer()->required()->exists('testing.widgets', 'id'),
            ]),
        ])->check(['items' => $items]);

        // Dedup to 1; cap not tripped; id=1 exists → pass.
        expect($result->passes())->toBeTrue();
    } finally {
        BatchDatabaseChecker::$maxValuesPerGroup = $previous;
    }
});

it('bad-type values drop before cap check (mostly hostile input with cap 10 does not trip)', function (): void {
    setupGuardsDatabase();

    // 11 items: 2 valid ints, 9 bad-type strings. After type filter → 2 values.
    $items = [
        ['id' => 1],
        ['id' => 'bad1'], ['id' => 'bad2'], ['id' => 'bad3'],
        ['id' => 'bad4'], ['id' => 'bad5'], ['id' => 'bad6'],
        ['id' => 'bad7'], ['id' => 'bad8'], ['id' => 'bad9'],
        ['id' => 2],
    ];

    $previous = BatchDatabaseChecker::$maxValuesPerGroup;
    BatchDatabaseChecker::$maxValuesPerGroup = 10;

    try {
        $caught = null;
        try {
            RuleSet::from([
                'items' => FluentRule::array()->required()->each([
                    'id' => FluentRule::integer()->required()->exists('testing.widgets', 'id'),
                ]),
            ])->validate(['items' => $items]);
        } catch (ValidationException $e) {
            $caught = $e;
        }

        // ValidationException is expected (bad-type per-item rejections), but
        // it must be the per-item integer error — NOT a hard-cap remap.
        expect($caught)->not->toBeNull()
            ->and($caught->validator->errors()->keys())->not->toContain('items');
    } finally {
        BatchDatabaseChecker::$maxValuesPerGroup = $previous;
    }
});

// =========================================================================
// Phase 3: parent-max short-circuit (FormRequest path)
// =========================================================================

it('FormRequest path short-circuits when flat parent array exceeds max:N', function (): void {
    setupGuardsDatabase();

    // Parent says max:5, user sends 100 items.
    $items = [];
    for ($i = 1; $i <= 100; ++$i) {
        $items[] = ['id' => $i];
    }

    $formRequest = createGuardsFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->max(5)->each([
                'id' => FluentRule::integer()->required()->exists('testing.widgets', 'id'),
            ]),
        ],
        data: ['items' => $items],
    );

    DB::connection('testing')->enableQueryLog();

    $caught = null;
    try {
        $factory = resolve(Factory::class);
        (fn () => $this->createDefaultValidator($factory))->call($formRequest);
    } catch (ValidationException $validationException) {
        $caught = $validationException;
    }

    $queryLog = DB::connection('testing')->getQueryLog();
    DB::connection('testing')->disableQueryLog();

    $widgetQueries = array_filter(
        $queryLog,
        static fn (array $q): bool => str_contains($q['query'], 'widgets'),
    );

    // 0 DB queries — short-circuit before any whereIn runs.
    expect($widgetQueries)->toBeEmpty()
        ->and($caught)->not->toBeNull()
        ->and($caught->validator->errors()->keys())->toContain('items');
});

it('FormRequest path does not short-circuit when parent is within max:N', function (): void {
    setupGuardsDatabase();

    $formRequest = createGuardsFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->max(5)->each([
                'id' => FluentRule::integer()->required()->exists('testing.widgets', 'id'),
            ]),
        ],
        data: [
            'items' => [
                ['id' => 1],
                ['id' => 2],
                ['id' => 3],
            ],
        ],
    );

    DB::connection('testing')->enableQueryLog();

    $factory = resolve(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    expect($validator->passes())->toBeTrue();

    $queryLog = DB::connection('testing')->getQueryLog();
    DB::connection('testing')->disableQueryLog();

    $widgetQueries = array_filter(
        $queryLog,
        static fn (array $q): bool => str_contains($q['query'], 'widgets'),
    );

    // One batched whereIn.
    expect($widgetQueries)->toHaveCount(1);
});

it('FormRequest path short-circuits nested wildcard when inner parent exceeds max:N', function (): void {
    setupGuardsDatabase();

    // orders.*.items has max:3; the first order sends 10 items.
    $overflow = [];
    for ($i = 1; $i <= 10; ++$i) {
        $overflow[] = ['id' => $i];
    }

    $formRequest = createGuardsFormRequest(
        rules: [
            'orders' => FluentRule::array()->required()->each([
                'items' => FluentRule::array()->required()->max(3)->each([
                    'id' => FluentRule::integer()->required()->exists('testing.widgets', 'id'),
                ]),
            ]),
        ],
        data: [
            'orders' => [
                ['items' => $overflow],
            ],
        ],
    );

    DB::connection('testing')->enableQueryLog();

    $caught = null;
    try {
        $factory = resolve(Factory::class);
        (fn () => $this->createDefaultValidator($factory))->call($formRequest);
    } catch (ValidationException $validationException) {
        $caught = $validationException;
    }

    $queryLog = DB::connection('testing')->getQueryLog();
    DB::connection('testing')->disableQueryLog();

    $widgetQueries = array_filter(
        $queryLog,
        static fn (array $q): bool => str_contains($q['query'], 'widgets'),
    );

    expect($widgetQueries)->toBeEmpty()
        ->and($caught)->not->toBeNull()
        ->and($caught->validator->errors()->keys())->toContain('orders.0.items');
});

it('FormRequest path resolves parent for scalar wildcard (items.* of integers) with max:N breach', function (): void {
    setupGuardsDatabase();

    // Scalar wildcard: items is a flat array of ids (no inner key).
    $ids = range(1, 20);

    $formRequest = createGuardsFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->max(5)->each(
                FluentRule::integer()->required()->exists('testing.widgets', 'id'),
            ),
        ],
        data: ['items' => $ids],
    );

    DB::connection('testing')->enableQueryLog();

    $caught = null;
    try {
        $factory = resolve(Factory::class);
        (fn () => $this->createDefaultValidator($factory))->call($formRequest);
    } catch (ValidationException $validationException) {
        $caught = $validationException;
    }

    $queryLog = DB::connection('testing')->getQueryLog();
    DB::connection('testing')->disableQueryLog();

    $widgetQueries = array_filter(
        $queryLog,
        static fn (array $q): bool => str_contains($q['query'], 'widgets'),
    );

    expect($widgetQueries)->toBeEmpty()
        ->and($caught)->not->toBeNull();
});

it('parent-max detection handles string-form parent rule (array|max:5)', function (): void {
    setupGuardsDatabase();

    // Use plain array rules (not FluentRule) so the parent ends up as a
    // pipe-delimited string — forces the string branch of extractParentMax.
    $items = [];
    for ($i = 1; $i <= 10; ++$i) {
        $items[] = ['id' => $i];
    }

    $formRequest = createGuardsFormRequest(
        rules: [
            'items' => 'required|array|max:5',
            'items.*.id' => ['required', 'integer', Rule::exists('testing.widgets', 'id')],
        ],
        data: ['items' => $items],
    );

    DB::connection('testing')->enableQueryLog();

    $caught = null;
    try {
        $factory = resolve(Factory::class);
        (fn () => $this->createDefaultValidator($factory))->call($formRequest);
    } catch (ValidationException $validationException) {
        $caught = $validationException;
    }

    $queryLog = DB::connection('testing')->getQueryLog();
    DB::connection('testing')->disableQueryLog();

    $widgetQueries = array_filter(
        $queryLog,
        static fn (array $q): bool => str_contains($q['query'], 'widgets'),
    );

    expect($widgetQueries)->toBeEmpty()
        ->and($caught)->not->toBeNull();
});

it('FormRequest raw BatchLimitExceededException for parent-max carries attribute and limit', function (): void {
    setupGuardsDatabase();

    // Bypass the trait remap by calling the private method directly.
    $items = [];
    for ($i = 1; $i <= 10; ++$i) {
        $items[] = ['id' => $i];
    }

    $preparedRules = [
        'items' => ['required', 'array', 'max:5'],
    ];
    $wildcardAttributes = [];
    for ($i = 0; $i < 10; ++$i) {
        $key = "items.{$i}.id";
        $preparedRules[$key] = ['required', 'integer', Rule::exists('testing.widgets', 'id')];
        $wildcardAttributes[] = $key;
    }

    $formRequest = createGuardsFormRequest(rules: [], data: []);

    $caught = null;
    try {
        (fn () => $this->buildFormRequestBatchVerifier(
            $preparedRules,
            ['items' => $items],
            $wildcardAttributes,
        ))->call($formRequest);
    } catch (BatchLimitExceededException $batchLimitExceededException) {
        $caught = $batchLimitExceededException;
    }

    expect($caught)->not->toBeNull()
        ->and($caught->reason)->toBe(BatchLimitExceededException::REASON_PARENT_MAX)
        ->and($caught->attribute)->toBe('items')
        ->and($caught->limit)->toBe(5)
        ->and($caught->valueCount)->toBe(10)
        ->and($caught->ruleType)->toBe('exists');
});

it('FormRequest failedValidation() override sees parent-max remapped ValidationException', function (): void {
    setupGuardsDatabase();

    $items = [];
    for ($i = 1; $i <= 10; ++$i) {
        $items[] = ['id' => $i];
    }

    $formRequest = new class extends FormRequest {
        use HasFluentRules;

        public static bool $failedValidationWasCalled = false;

        public static ?ValidationException $capturedException = null;

        /** @return array<string, mixed> */
        public function rules(): array
        {
            return [
                'items' => FluentRule::array()->required()->max(5)->each([
                    'id' => FluentRule::integer()->required()->exists('testing.widgets', 'id'),
                ]),
            ];
        }

        public function authorize(): bool
        {
            return true;
        }

        protected function failedValidation(Validator $validator): void
        {
            // Failed-validation hook fires on standard ValidationException only.
            self::$failedValidationWasCalled = true;
            self::$capturedException = new ValidationException($validator);
            throw self::$capturedException;
        }
    };

    $request = Request::create('/test', 'POST', ['items' => $items]);
    $instance = $formRequest::createFrom($request);
    $instance->setContainer(app());
    $instance->setRedirector(resolve(Redirector::class));

    $caught = null;
    try {
        $instance->validateResolved();
    } catch (ValidationException $validationException) {
        $caught = $validationException;
    }

    // The trait remaps BatchLimitExceededException → ValidationException
    // BEFORE the validator runs, so failedValidation() does NOT fire (the
    // validator is never built for this request). A raw ValidationException
    // bubbles up, and the anon class's static flag stays false — pinning
    // the known gap documented in src/HasFluentRules.php.
    $flagClass = $formRequest::class;
    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($flagClass::$failedValidationWasCalled)->toBeFalse();
});

// =========================================================================
// Phase 4: RuleSet-path invariants
// =========================================================================

it('RuleSet validateInternal rejects flat parent max:N before any DB query fires', function (): void {
    setupGuardsDatabase();

    // Flat parent `items` with max:5, user sends 20 items. In the RuleSet
    // path, the top validator catches `max` before per-item wildcard
    // validation runs (and before batching).
    $items = [];
    for ($i = 1; $i <= 20; ++$i) {
        $items[] = ['id' => $i];
    }

    DB::connection('testing')->enableQueryLog();

    $caught = null;
    try {
        RuleSet::from([
            'items' => FluentRule::array()->required()->max(5)->each([
                'id' => FluentRule::integer()->required()->exists('testing.widgets', 'id'),
            ]),
        ])->validate(['items' => $items]);
    } catch (ValidationException $validationException) {
        $caught = $validationException;
    }

    $queryLog = DB::connection('testing')->getQueryLog();
    DB::connection('testing')->disableQueryLog();

    $widgetQueries = array_filter(
        $queryLog,
        static fn (array $q): bool => str_contains($q['query'], 'widgets'),
    );

    expect($widgetQueries)->toBeEmpty()
        ->and($caught)->not->toBeNull()
        ->and($caught->validator->errors()->keys())->toContain('items');
});

it('RuleSet nested second-level wildcard does NOT batch today (accidental DoS safety)', function (): void {
    setupGuardsDatabase();

    // Nested wildcards: orders.*.items.*.id. The RuleSet path's collectValues()
    // does a direct `$itemData[$field] ?? null` lookup which fails for nested
    // `.*.` field names — so nested batching is a no-op. Pin this so any
    // future change to enable nested batching MUST also add the guards.
    $orders = [];
    for ($o = 0; $o < 2; ++$o) {
        $items = [];
        for ($i = 1; $i <= 2; ++$i) {
            $items[] = ['id' => $i];
        }

        $orders[] = ['items' => $items];
    }

    DB::connection('testing')->enableQueryLog();

    RuleSet::from([
        'orders' => FluentRule::array()->required()->each([
            'items' => FluentRule::array()->required()->each([
                'id' => FluentRule::integer()->required()->exists('testing.widgets', 'id'),
            ]),
        ]),
    ])->validate(['orders' => $orders]);

    $queryLog = DB::connection('testing')->getQueryLog();
    DB::connection('testing')->disableQueryLog();

    $widgetQueries = array_filter(
        $queryLog,
        static fn (array $q): bool => str_contains($q['query'], 'widgets'),
    );

    // 4 per-item queries (2 orders × 2 items) — NOT a single batched whereIn.
    // The day nested batching lands, this assertion flips and a guard lands too.
    expect(count($widgetQueries))->toBeGreaterThan(1);
});

it('ItemValidator disables batching when presence conditionals are present', function (): void {
    setupGuardsDatabase();

    // required_without triggers hasPresenceConditionals → batch verifier is
    // not built; per-item DB queries still run as today. This test pins the
    // gating behaviour, NOT that this path is DoS-safe.
    $items = [
        ['id' => 1],
        ['id' => 2],
    ];

    DB::connection('testing')->enableQueryLog();

    RuleSet::from([
        'items' => FluentRule::array()->required()->each([
            'id' => FluentRule::integer()->exists('testing.widgets', 'id')
                ->requiredWithout('other'),
        ]),
    ])->validate(['items' => $items]);

    $queryLog = DB::connection('testing')->getQueryLog();
    DB::connection('testing')->disableQueryLog();

    $widgetQueries = array_filter(
        $queryLog,
        static fn (array $q): bool => str_contains($q['query'], 'widgets'),
    );

    // Per-item queries (2 items = 2 queries). Would be 1 if batching fired.
    expect(count($widgetQueries))->toBeGreaterThan(1);
});

// =========================================================================
// Phase 4: cross-phase integration — all three guards working together
// =========================================================================

it('FormRequest path: Phase 1 + 2 + 3 guards co-operate on a single request', function (): void {
    setupGuardsDatabase();

    // 20 items, mix of valid ints, bad types, and more than parent max:50.
    // Parent max is 50, so Phase 3 (parent-max) does NOT fire — but Phase 2
    // (hard cap, override to 5) does, applied to filter+dedup'd values.
    $items = [];
    for ($i = 1; $i <= 10; ++$i) {
        $items[] = ['id' => $i];
    }

    // Six bad-type values; after Phase 1 filter these drop out.
    $items[] = ['id' => 'abc'];
    $items[] = ['id' => 'def'];
    $items[] = ['id' => '3.5'];
    $items[] = ['id' => null];
    $items[] = ['id' => ''];
    $items[] = ['id' => []]; // data_get pulls nested array — filtered or dropped

    $formRequest = createGuardsFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->max(50)->each([
                'id' => FluentRule::integer()->required()->exists('testing.widgets', 'id'),
            ]),
        ],
        data: ['items' => $items],
    );

    $previous = BatchDatabaseChecker::$maxValuesPerGroup;
    BatchDatabaseChecker::$maxValuesPerGroup = 5;

    try {
        $caught = null;
        try {
            $factory = resolve(Factory::class);
            (fn () => $this->createDefaultValidator($factory))->call($formRequest);
        } catch (ValidationException $e) {
            $caught = $e;
        }

        // After Phase 1 filter: 10 ints pass; bad-types dropped. Count 10 > cap 5.
        // Phase 2 hard-cap trips, remaps to ValidationException.
        expect($caught)->not->toBeNull();
    } finally {
        BatchDatabaseChecker::$maxValuesPerGroup = $previous;
    }
});

it('RuleSet check(): Phase 1 + 2 guards co-operate via the full engine', function (): void {
    setupGuardsDatabase();

    $items = [];
    for ($i = 1; $i <= 10; ++$i) {
        $items[] = ['id' => $i];
    }

    $items[] = ['id' => 'abc'];  // Phase 1 filter drops this.

    $previous = BatchDatabaseChecker::$maxValuesPerGroup;
    BatchDatabaseChecker::$maxValuesPerGroup = 5;

    try {
        $result = RuleSet::from([
            'items' => FluentRule::array()->required()->each([
                'id' => FluentRule::integer()->required()->exists('testing.widgets', 'id'),
            ]),
        ])->check(['items' => $items]);

        // 10 valid ints filter through Phase 1; 10 > cap 5 trips Phase 2.
        // check() never throws — returns failed Validated.
        expect($result->fails())->toBeTrue();
    } finally {
        BatchDatabaseChecker::$maxValuesPerGroup = $previous;
    }
});

it('parent-max short-circuit also fires for unique rules, carrying ruleType=unique', function (): void {
    setupGuardsDatabase();

    $items = [];
    for ($i = 1; $i <= 10; ++$i) {
        $items[] = ['uuid' => sprintf('%08d-0000-0000-0000-000000000000', $i)];
    }

    // Bypass trait remap to inspect payload.
    $preparedRules = [
        'items' => ['required', 'array', 'max:3'],
    ];
    $wildcardAttributes = [];
    for ($i = 0; $i < 10; ++$i) {
        $key = "items.{$i}.uuid";
        $preparedRules[$key] = ['required', 'uuid', Rule::unique('testing.widgets', 'uuid')];
        $wildcardAttributes[] = $key;
    }

    $formRequest = createGuardsFormRequest(rules: [], data: []);

    $caught = null;
    try {
        (fn () => $this->buildFormRequestBatchVerifier(
            $preparedRules,
            ['items' => $items],
            $wildcardAttributes,
        ))->call($formRequest);
    } catch (BatchLimitExceededException $batchLimitExceededException) {
        $caught = $batchLimitExceededException;
    }

    expect($caught)->not->toBeNull()
        ->and($caught->ruleType)->toBe('unique')
        ->and($caught->reason)->toBe(BatchLimitExceededException::REASON_PARENT_MAX)
        ->and($caught->attribute)->toBe('items')
        ->and($caught->limit)->toBe(3)
        ->and($caught->valueCount)->toBe(10);
});

it('RuleSet withBag() stamps the error bag onto remapped hard-cap ValidationException', function (): void {
    setupGuardsDatabase();

    $items = [];
    for ($i = 1; $i <= 11; ++$i) {
        $items[] = ['id' => $i];
    }

    $previous = BatchDatabaseChecker::$maxValuesPerGroup;
    BatchDatabaseChecker::$maxValuesPerGroup = 10;

    try {
        $caught = null;
        try {
            RuleSet::from([
                'items' => FluentRule::array()->required()->each([
                    'id' => FluentRule::integer()->required()->exists('testing.widgets', 'id'),
                ]),
            ])->withBag('importForm')->validate(['items' => $items]);
        } catch (ValidationException $validationException) {
            $caught = $validationException;
        }

        expect($caught)->not->toBeNull()
            ->and($caught->errorBag)->toBe('importForm');
    } finally {
        BatchDatabaseChecker::$maxValuesPerGroup = $previous;
    }
});

it('filter drops bad types before cap check, so per-item integer error surfaces (not hard-cap)', function (): void {
    setupGuardsDatabase();

    // 2 valid ints + 9 bad-type. Cap 10. After filter: 2 values ≤ cap, batch
    // proceeds. Per-item integer rule rejects the 9 bad items. The surfaced
    // exception must be the per-item error — NOT a hard-cap remap.
    $items = [
        ['id' => 1],
        ['id' => 'bad1'], ['id' => 'bad2'], ['id' => 'bad3'], ['id' => 'bad4'],
        ['id' => 'bad5'], ['id' => 'bad6'], ['id' => 'bad7'], ['id' => 'bad8'],
        ['id' => 'bad9'],
        ['id' => 2],
    ];

    $previous = BatchDatabaseChecker::$maxValuesPerGroup;
    BatchDatabaseChecker::$maxValuesPerGroup = 10;

    try {
        $caught = null;
        try {
            RuleSet::from([
                'items' => FluentRule::array()->required()->each([
                    'id' => FluentRule::integer()->required()->exists('testing.widgets', 'id'),
                ]),
            ])->validate(['items' => $items]);
        } catch (ValidationException $validationException) {
            $caught = $validationException;
        }

        expect($caught)->not->toBeNull();

        // Per-item error keys like items.1.id, items.2.id — NOT a remapped
        // top-level 'items' hard-cap error. This pins the filter→cap ordering.
        $keys = $caught->validator->errors()->keys();
        $hasPerItemError = false;
        foreach ($keys as $key) {
            if (str_starts_with($key, 'items.') && str_ends_with($key, '.id')) {
                $hasPerItemError = true;
                break;
            }
        }

        expect($hasPerItemError)->toBeTrue();
    } finally {
        BatchDatabaseChecker::$maxValuesPerGroup = $previous;
    }
});

// =========================================================================
// Scale — adversarial payload at production size
// =========================================================================

it('hard-cap trips fast and cheaply on a realistic 50k-item hostile payload', function (): void {
    setupGuardsDatabase();

    // 50_000 unique integers — the kind of payload an attacker would send to
    // exercise the batching pipeline at scale. Default cap is 10_000. The
    // filter → dedup → cap check chain should trip well before any whereIn
    // fires, and it must not exhaust memory or blow past a sensible wall-clock
    // budget.
    $items = [];
    for ($i = 1; $i <= 50_000; ++$i) {
        $items[] = ['id' => $i];
    }

    DB::connection('testing')->enableQueryLog();

    $memBefore = memory_get_usage();
    $start = microtime(true);

    $caught = null;
    try {
        RuleSet::from([
            'items' => FluentRule::array()->required()->each([
                'id' => FluentRule::integer()->required()->exists('testing.widgets', 'id'),
            ]),
        ])->validate(['items' => $items]);
    } catch (ValidationException $validationException) {
        $caught = $validationException;
    }

    $elapsed = microtime(true) - $start;
    $memDelta = memory_get_usage() - $memBefore;

    $queryLog = DB::connection('testing')->getQueryLog();
    DB::connection('testing')->disableQueryLog();

    $widgetQueries = array_filter(
        $queryLog,
        static fn (array $q): bool => str_contains($q['query'], 'widgets'),
    );

    expect($caught)->not->toBeNull()
        // Zero DB queries — hard cap must refuse to query.
        ->and($widgetQueries)->toBeEmpty()
        // Wall-clock budget: 2s is ~100x what a healthy run needs and still
        // catches a quadratic regression loud and clear.
        ->and($elapsed)->toBeLessThan(2.0)
        // Memory delta budget: 50k ints + per-item rule compilation stays well
        // under 64MB even with Laravel's expansion overhead; a runaway would
        // blow past this.
        ->and($memDelta)->toBeLessThan(64 * 1024 * 1024);
});

it('overridden $maxValuesPerGroup value is respected', function (): void {
    $rule = Rule::exists('widgets', 'id');

    $groups = [
        'widgets:id:exists' => ['rule' => $rule, 'values' => ['1', '2', '3', '4', '5']],
    ];

    $previous = BatchDatabaseChecker::$maxValuesPerGroup;
    BatchDatabaseChecker::$maxValuesPerGroup = 3;

    try {
        $caught = null;
        try {
            BatchDatabaseChecker::buildVerifier($groups);
        } catch (BatchLimitExceededException $e) {
            $caught = $e;
        }

        expect($caught)->not->toBeNull()
            ->and($caught->limit)->toBe(3);
    } finally {
        BatchDatabaseChecker::$maxValuesPerGroup = $previous;
    }
});
