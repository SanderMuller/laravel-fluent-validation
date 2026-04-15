<?php declare(strict_types=1);

use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\HasFluentValidationForFilament;

// =========================================================================
// Test doubles
// =========================================================================

/**
 * Simulates a Livewire+Filament component base.
 * validate() records what it receives so we can assert the compiled output.
 */
class FakeFilamentBase
{
    /** @var array{rules: mixed, messages: array<string, string>, attributes: array<string, string>} */
    public array $lastValidateCall = ['rules' => null, 'messages' => [], 'attributes' => []];

    /**
     * @param  array<string, mixed>|null  $rules
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     * @return array<string, mixed>
     */
    public function validate(mixed $rules = null, array $messages = [], array $attributes = []): array
    {
        $this->lastValidateCall = [
            'rules' => $rules,
            'messages' => $messages,
            'attributes' => $attributes,
        ];

        return is_array($rules) ? $rules : [];
    }
}

class TestableFilamentComponent extends FakeFilamentBase
{
    use HasFluentValidationForFilament;

    /** @param  array<string, mixed>  $fluentRules */
    public function __construct(private array $fluentRules = []) {}

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->fluentRules;
    }
}

// =========================================================================
// validateFluent() — basic compilation
// =========================================================================

it('validateFluent() compiles FluentRule objects and delegates to validate()', function (): void {
    $component = new TestableFilamentComponent(
        fluentRules: [
            'name' => FluentRule::string('Full Name')->required()->max(255),
            'email' => FluentRule::email()->required(),
        ],
    );

    $component->validateFluent();

    $rules = $component->lastValidateCall['rules'];
    expect($rules)->toBeArray()
        ->toHaveKeys(['name', 'email']);

    // Labels extracted
    $attributes = $component->lastValidateCall['attributes'];
    expect($attributes)->toHaveKey('name', 'Full Name');

    // Compiled to strings
    expect($rules['name'])->toBeString()->toContain('required')->toContain('max:255');
});

it('validateFluent() extracts messages from FluentRule objects', function (): void {
    $component = new TestableFilamentComponent(
        fluentRules: [
            'name' => FluentRule::string()->required()->message('Name is required!'),
        ],
    );

    $component->validateFluent();

    $messages = $component->lastValidateCall['messages'];
    expect($messages)->toHaveKey('name.required', 'Name is required!');
});

it('validateFluent() expands each() into wildcard keys', function (): void {
    $component = new TestableFilamentComponent(
        fluentRules: [
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string('Item Name')->required(),
            ]),
        ],
    );

    $component->validateFluent();

    $rules = $component->lastValidateCall['rules'];
    expect($rules)
        ->toHaveKeys(['items', 'items.*.name']);

    $attributes = $component->lastValidateCall['attributes'];
    expect($attributes)->toHaveKey('items.*.name', 'Item Name');
});

it('validateFluent() expands children() into fixed paths', function (): void {
    $component = new TestableFilamentComponent(
        fluentRules: [
            'credentials' => FluentRule::array()->children([
                'base_uri' => FluentRule::string('Base URI')->nullable()->url(),
                'client_id' => FluentRule::string()->required()->uuid(),
            ]),
        ],
    );

    $component->validateFluent();

    $rules = $component->lastValidateCall['rules'];
    expect($rules)
        ->toHaveKeys(['credentials', 'credentials.base_uri', 'credentials.client_id']);

    $attributes = $component->lastValidateCall['attributes'];
    expect($attributes)->toHaveKey('credentials.base_uri', 'Base URI');
});

// =========================================================================
// validateFluent() — pass-through for plain rules
// =========================================================================

it('validateFluent() passes plain string rules through to validate()', function (): void {
    $component = new TestableFilamentComponent(
        fluentRules: [
            'name' => 'required|string|max:255',
        ],
    );

    $component->validateFluent();

    // Plain strings go straight to validate() without compilation
    expect($component->lastValidateCall['rules'])->toBeNull();
    expect($component->lastValidateCall['messages'])->toBeEmpty();
});

it('validateFluent() with empty rules delegates to validate()', function (): void {
    $component = new TestableFilamentComponent(fluentRules: []);

    $component->validateFluent();

    expect($component->lastValidateCall['rules'])->toBeNull();
});

// =========================================================================
// validateFluent() — inline rules override
// =========================================================================

it('validateFluent() accepts inline rules instead of rules()', function (): void {
    $component = new TestableFilamentComponent(
        fluentRules: ['name' => FluentRule::string()->required()],
    );

    // Pass different rules inline
    $component->validateFluent(
        rules: ['email' => FluentRule::email('Email Address')->required()],
    );

    $rules = $component->lastValidateCall['rules'];
    expect($rules)
        ->toHaveKey('email')
        ->not->toHaveKey('name');

    $attributes = $component->lastValidateCall['attributes'];
    expect($attributes)->toHaveKey('email', 'Email Address');
});

// =========================================================================
// validateFluent() — merges caller messages/attributes
// =========================================================================

it('validateFluent() merges caller-provided messages with FluentRule messages', function (): void {
    $component = new TestableFilamentComponent(
        fluentRules: [
            'name' => FluentRule::string()->required()->message('Name is required!'),
        ],
    );

    $component->validateFluent(messages: ['name.max' => 'Too long!']);

    $messages = $component->lastValidateCall['messages'];
    expect($messages)
        ->toHaveKey('name.required', 'Name is required!')
        ->toHaveKey('name.max', 'Too long!');
});
