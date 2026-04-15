<?php declare(strict_types=1);

use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\HasFluentValidationForFilament;

// =========================================================================
// Test doubles
// =========================================================================

/**
 * Simulates a Livewire+Filament component base.
 * Records validate/validateOnly calls so we can assert the compiled output.
 */
class FakeFilamentBase
{
    /** @var array{rules: mixed, messages: array<string, string>, attributes: array<string, string>} */
    public array $lastValidateCall = ['rules' => null, 'messages' => [], 'attributes' => []];

    /** @var array{field: string, rules: mixed, messages: array<string, string>, attributes: array<string, string>} */
    public array $lastValidateOnlyCall = ['field' => '', 'rules' => null, 'messages' => [], 'attributes' => []];

    public function validate(mixed $rules = null, mixed $messages = [], mixed $attributes = []): mixed
    {
        $this->lastValidateCall = [
            'rules' => $rules,
            'messages' => is_array($messages) ? $messages : [],
            'attributes' => is_array($attributes) ? $attributes : [],
        ];

        return is_array($rules) ? $rules : [];
    }

    public function validateOnly(mixed $field, mixed $rules = null, mixed $messages = [], mixed $attributes = [], mixed $dataOverrides = []): mixed
    {
        $this->lastValidateOnlyCall = [
            'field' => is_string($field) ? $field : '',
            'rules' => $rules,
            'messages' => is_array($messages) ? $messages : [],
            'attributes' => is_array($attributes) ? $attributes : [],
        ];

        return is_array($rules) ? $rules : [];
    }

    /** @return array<string, string> */
    public function getMessages(): array
    {
        return [];
    }

    /** @return array<string, string> */
    public function getValidationAttributes(): array
    {
        return [];
    }
}

class TestableFilamentComponent extends FakeFilamentBase
{
    use HasFluentValidationForFilament;

    /**
     * @param  array<string, mixed>  $fluentRules
     * @param  array<string, mixed>  $data
     */
    public function __construct(private array $fluentRules = [], public array $data = []) {}

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->fluentRules;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    public function getDataForValidation(array $rules): array
    {
        return $this->data;
    }
}

// =========================================================================
// validate() — compiles FluentRules
// =========================================================================

it('Filament: validate() compiles FluentRule objects', function (): void {
    $component = new TestableFilamentComponent(
        fluentRules: [
            'name' => FluentRule::string('Full Name')->required()->max(255),
            'email' => FluentRule::email()->required(),
        ],
    );

    $component->validate();

    $rules = $component->lastValidateCall['rules'];
    expect($rules)->toBeArray()
        ->toHaveKeys(['name', 'email']);

    $attributes = $component->lastValidateCall['attributes'];
    expect($attributes)->toHaveKey('name', 'Full Name')
        ->and($rules['name'])->toBeString()
        ->toContain('required')
        ->toContain('max:255');
});

it('Filament: validate() extracts messages', function (): void {
    $component = new TestableFilamentComponent(
        fluentRules: [
            'name' => FluentRule::string()->required()->message('Name is required!'),
        ],
    );

    $component->validate();

    expect($component->lastValidateCall['messages'])
        ->toHaveKey('name.required', 'Name is required!');
});

it('Filament: validate() expands each() against data', function (): void {
    $component = new TestableFilamentComponent(
        fluentRules: [
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string('Item Name')->required(),
            ]),
        ],
        data: ['items' => [['name' => 'Foo'], ['name' => 'Bar']]],
    );

    $component->validate();

    $rules = $component->lastValidateCall['rules'];
    expect($rules)->toHaveKeys(['items', 'items.0.name', 'items.1.name']);
});

it('Filament: validate() expands children() into fixed paths', function (): void {
    $component = new TestableFilamentComponent(
        fluentRules: [
            'credentials' => FluentRule::array()->children([
                'base_uri' => FluentRule::string('Base URI')->nullable()->url(),
                'client_id' => FluentRule::string()->required()->uuid(),
            ]),
        ],
    );

    $component->validate();

    $rules = $component->lastValidateCall['rules'];
    expect($rules)->toHaveKeys(['credentials', 'credentials.base_uri', 'credentials.client_id'])
        ->and($component->lastValidateCall['attributes'])->toHaveKey('credentials.base_uri', 'Base URI');
});

it('Filament: validate() passes plain string rules through', function (): void {
    $component = new TestableFilamentComponent(
        fluentRules: ['name' => 'required|string|max:255'],
    );

    $component->validate();

    expect($component->lastValidateCall['rules'])->toBeNull();
});

// =========================================================================
// validateOnly() — single-field validation
// =========================================================================

it('Filament: validateOnly() compiles FluentRules', function (): void {
    $component = new TestableFilamentComponent(
        fluentRules: [
            'name' => FluentRule::string('Full Name')->required()->max(255),
            'email' => FluentRule::email()->required(),
        ],
    );

    $component->validateOnly('name');

    expect($component->lastValidateOnlyCall['field'])->toBe('name');

    $rules = $component->lastValidateOnlyCall['rules'];
    expect($rules)->toBeArray()->toHaveKey('name')
        ->and($component->lastValidateOnlyCall['attributes'])->toHaveKey('name', 'Full Name');
});

it('Filament: validateOnly() extracts messages', function (): void {
    $component = new TestableFilamentComponent(
        fluentRules: [
            'name' => FluentRule::string()->required()->message('Name is required!'),
        ],
    );

    $component->validateOnly('name');

    expect($component->lastValidateOnlyCall['messages'])
        ->toHaveKey('name.required', 'Name is required!');
});

// =========================================================================
// Merging caller-provided messages/attributes
// =========================================================================

it('Filament: validate() merges caller messages with FluentRule messages', function (): void {
    $component = new TestableFilamentComponent(
        fluentRules: [
            'name' => FluentRule::string()->required()->message('Name is required!'),
        ],
    );

    $component->validate(messages: ['name.max' => 'Too long!']);

    expect($component->lastValidateCall['messages'])
        ->toHaveKey('name.required', 'Name is required!')
        ->toHaveKey('name.max', 'Too long!');
});
