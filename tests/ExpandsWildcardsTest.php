<?php

declare(strict_types=1);

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use SanderMuller\FluentValidation\HasFluentRules;
use SanderMuller\FluentValidation\FluentRule;

// =========================================================================
// Unit: createDefaultValidator() directly
// =========================================================================

it('expands wildcards via createDefaultValidator', function (): void {
    $formRequest = createFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->min(2),
            ]),
        ],
        data: [
            'items' => [
                ['name' => 'John'],
                ['name' => 'Jane'],
            ],
        ],
    );

    $factory = app(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    expect($validator->passes())->toBeTrue();
    expect($validator->validated())->toHaveKeys(['items']);
});

it('reports errors with correct paths via createDefaultValidator', function (): void {
    $formRequest = createFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->min(5),
            ]),
        ],
        data: [
            'items' => [
                ['name' => 'Jo'],
            ],
        ],
    );

    $factory = app(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->keys())->toContain('items.0.name');
});

// =========================================================================
// Integration: full HTTP request through the framework
// =========================================================================

it('validates a real POST request through a FormRequest', function (): void {
    Route::post('/test-expands-wildcards', function (Request $request) {
        $formRequest = createFormRequest(
            rules: [
                'items' => FluentRule::array()->required()->each([
                    'name' => FluentRule::string()->required()->min(2),
                    'email' => FluentRule::string()->required()->rule('email'),
                ]),
            ],
            data: $request->all(),
        );

        $factory = app(Factory::class);
        $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

        return response()->json($validator->validated());
    });

    $response = $this->postJson('/test-expands-wildcards', [
        'items' => [
            ['name' => 'John', 'email' => 'john@example.com'],
            ['name' => 'Jane', 'email' => 'jane@example.com'],
        ],
    ]);

    $response->assertOk();
    $response->assertJsonCount(2, 'items');
});

it('returns 422 with errors for invalid data through a FormRequest', function (): void {
    Route::post('/test-expands-wildcards-fail', function (Request $request) {
        $formRequest = createFormRequest(
            rules: [
                'items' => FluentRule::array()->required()->each([
                    'name' => FluentRule::string()->required()->min(5),
                ]),
            ],
            data: $request->all(),
        );

        $factory = app(Factory::class);
        $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return response()->json($validator->validated());
    });

    $response = $this->postJson('/test-expands-wildcards-fail', [
        'items' => [
            ['name' => 'Jo'],
        ],
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['items.0.name']);
});

it('works with mixed fluent and string rules', function (): void {
    $formRequest = createFormRequest(
        rules: [
            'title' => 'required|string|max:255',
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->min(2),
            ]),
        ],
        data: [
            'title' => 'My Import',
            'items' => [
                ['name' => 'John'],
            ],
        ],
    );

    $factory = app(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    expect($validator->passes())->toBeTrue();
    expect($validator->validated())->toHaveKeys(['title', 'items']);
});

it('works with nested each() rules', function (): void {
    $formRequest = createFormRequest(
        rules: [
            'orders' => FluentRule::array()->required()->each([
                'items' => FluentRule::array()->required()->each([
                    'qty' => FluentRule::numeric()->required()->integer()->min(1),
                ]),
            ]),
        ],
        data: [
            'orders' => [
                ['items' => [['qty' => 2], ['qty' => 5]]],
                ['items' => [['qty' => 1]]],
            ],
        ],
    );

    $factory = app(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    expect($validator->passes())->toBeTrue();
});

// =========================================================================
// Helper
// =========================================================================

function createFormRequest(array $rules, array $data): FormRequest
{
    $formRequest = new class extends FormRequest {
        use HasFluentRules;

        public static array $testRules = [];

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
    $anonymousClassffb6530a01d09c039cba4cf2f03e5fc7 = $formRequest::createFrom($request);
    $anonymousClassffb6530a01d09c039cba4cf2f03e5fc7->setContainer(app());
    $anonymousClassffb6530a01d09c039cba4cf2f03e5fc7->setRedirector(app('redirect'));

    return $anonymousClassffb6530a01d09c039cba4cf2f03e5fc7;
}
