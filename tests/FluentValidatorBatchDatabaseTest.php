<?php declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\FluentValidator;

// =========================================================================
// FluentValidator now wires the batched exists/unique presence verifier
// (the trait's fourth optimization) — these tests prove it issues a single
// whereIn instead of one query per wildcard item, and still reports the
// right per-item error keys.
// =========================================================================

function setupFluentValidatorDatabase(): void
{
    config(['database.default' => 'testing']);
    config(['database.connections.testing' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]]);

    Schema::connection('testing')->dropIfExists('users');
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

/** Concrete FluentValidator with a wildcard exists rule. */
final class BatchExistsValidator extends FluentValidator
{
    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        parent::__construct($data, [
            'items' => FluentRule::array()->required()->each([
                'email' => FluentRule::string()->required()->exists('testing.users', 'email'),
            ]),
        ]);
    }
}

it('FluentValidator batches exists queries for wildcard arrays', function (): void {
    setupFluentValidatorDatabase();

    DB::connection('testing')->flushQueryLog();
    DB::connection('testing')->enableQueryLog();

    $validated = (new BatchExistsValidator([
        'items' => [
            ['email' => 'alice@example.com'],
            ['email' => 'bob@example.com'],
            ['email' => 'carol@example.com'],
        ],
    ]))->validate();

    $queryLog = DB::connection('testing')->getQueryLog();
    DB::connection('testing')->disableQueryLog();

    // One batched whereIn (+ possibly a schema query), not one per item.
    $existsQueries = array_filter($queryLog, static fn (array $q): bool => str_contains($q['query'], 'users'));

    expect(count($existsQueries))->toBeLessThanOrEqual(2)
        ->and($validated['items'])->toHaveCount(3);
});

it('FluentValidator batched exists rejects invalid values with correct error keys', function (): void {
    setupFluentValidatorDatabase();

    $errors = [];

    try {
        (new BatchExistsValidator([
            'items' => [
                ['email' => 'alice@example.com'],
                ['email' => 'nonexistent@example.com'],
                ['email' => 'bob@example.com'],
            ],
        ]))->validate();
    } catch (ValidationException $validationException) {
        $errors = $validationException->errors();
    }

    expect($errors)->toHaveKey('items.1.email')
        ->and($errors)->not->toHaveKey('items.0.email')
        ->and($errors)->not->toHaveKey('items.2.email');
});
