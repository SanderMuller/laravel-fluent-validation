<?php declare(strict_types=1);

use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\HasFluentValidation;

// =========================================================================
// Test doubles
// =========================================================================

/**
 * Simulates the minimal Livewire Component parent.
 * Methods are typed as mixed to match what HasFluentValidation overrides.
 */
class FakeLivewireBase
{
    public function validate(mixed $rules = null, mixed $messages = [], mixed $attributes = []): mixed
    {
        return null;
    }

    public function validateOnly(mixed $field, mixed $rules = null, mixed $messages = [], mixed $attributes = [], mixed $dataOverrides = []): mixed
    {
        return null;
    }
}

/**
 * Exposes compileFluentRules() for unit testing.
 * Livewire's validate()/validateOnly() are not called here — we test
 * the compilation step in isolation.
 */
class TestableComponent extends FakeLivewireBase
{
    use HasFluentValidation;

    /**
     * @param  array<string, mixed>  $data
     * @param array<string, mixed> $fluentRules
     */
    public function __construct(public array $data = [], private array $fluentRules = [])
    {
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->fluentRules;
    }

    /**
     * Simulates Livewire's data resolution.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    public function getDataForValidation(array $rules): array
    {
        return $this->data;
    }

    /**
     * Expose the protected compilation step for testing.
     *
     * @param  array<string, mixed>|null  $rules
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     * @return array{0: array<string, mixed>|null, 1: array<string, string>, 2: array<string, string>}
     */
    public function compile(?array $rules = null, array $messages = [], array $attributes = []): array
    {
        return $this->compileFluentRules($rules, $messages, $attributes);
    }
}

/**
 * Component without a rules() method — simulates a Livewire component
 * that passes rules inline to each validate() call.
 */
class TestableComponentNoRulesMethod extends FakeLivewireBase
{
    use HasFluentValidation;

    /** @param  array<string, mixed>  $data */
    public function __construct(public array $data = [])
    {
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * @param  array<string, mixed>|null  $rules
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     * @return array{0: array<string, mixed>|null, 1: array<string, string>, 2: array<string, string>}
     */
    public function compile(?array $rules = null, array $messages = [], array $attributes = []): array
    {
        return $this->compileFluentRules($rules, $messages, $attributes);
    }
}

/**
 * Simulates a Livewire component that implements unwrapDataForValidation(),
 * which some Livewire versions use to unwrap Eloquent models before wildcard expansion.
 */
class TestableComponentWithUnwrap extends FakeLivewireBase
{
    use HasFluentValidation;

    /** @var array<string, mixed> */
    public array $rawData;

    /** @var array<string, mixed> */
    public array $unwrappedData;

    /**
     * @param  array<string, mixed>  $rawData
     * @param  array<string, mixed>  $unwrappedData
     * @param  array<string, mixed>  $rules
     */
    public function __construct(array $rawData, array $unwrappedData, private array $rules = [])
    {
        $this->rawData = $rawData;
        $this->unwrappedData = $unwrappedData;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->rules;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    public function getDataForValidation(array $rules): array
    {
        return $this->rawData;
    }

    /** @return array<string, mixed> */
    public function unwrapDataForValidation(mixed $data): array
    {
        return $this->unwrappedData;
    }

    /**
     * @param  array<string, mixed>|null  $rules
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     * @return array{0: array<string, mixed>|null, 1: array<string, string>, 2: array<string, string>}
     */
    public function compile(?array $rules = null, array $messages = [], array $attributes = []): array
    {
        return $this->compileFluentRules($rules, $messages, $attributes);
    }
}

// =========================================================================
// Basic compilation
// =========================================================================

it('compiles FluentRule objects to native string rules', function (): void {
    $component = new TestableComponent(
        data: ['name' => 'John'],
        rules: ['name' => FluentRule::string()->required()->max(255)],
    );

    [$compiled] = $component->compile();

    assert(is_array($compiled));
    expect($compiled)->toHaveKey('name')
        ->and($compiled['name'])->toBeString()
        ->and($compiled['name'])->toContain('required')
        ->and($compiled['name'])->toContain('string')
        ->and($compiled['name'])->toContain('max:255');
});

it('extracts labels into the attributes array', function (): void {
    $component = new TestableComponent(
        data: ['name' => 'John'],
        rules: ['name' => FluentRule::string('Full Name')->required()],
    );

    [, , $attributes] = $component->compile();

    expect($attributes)->toHaveKey('name')
        ->and($attributes['name'])->toBe('Full Name');
});

it('extracts per-rule messages into the messages array', function (): void {
    $component = new TestableComponent(
        data: ['name' => ''],
        rules: ['name' => FluentRule::string()->required()->message('Name is required!')],
    );

    [, $messages] = $component->compile();

    expect($messages)->toHaveKey('name.required')
        ->and($messages['name.required'])->toBe('Name is required!');
});

// =========================================================================
// Short-circuit paths
// =========================================================================

it('passes rules through unchanged when no FluentRule objects are present', function (): void {
    $component = new TestableComponent(
        data: ['name' => 'John'],
        rules: ['name' => 'required|string|max:255'],
    );

    [$compiled] = $component->compile();

    // No FluentRule objects — rules() returns non-FluentRule strings, so no compilation
    expect($compiled)->toBeNull();
});

it('returns null rules when no rules() method and no inline rules passed', function (): void {
    $component = new TestableComponentNoRulesMethod(data: ['name' => 'John']);

    [$compiled] = $component->compile();

    expect($compiled)->toBeNull();
});

it('returns null rules when rules() returns empty array', function (): void {
    $component = new TestableComponent(data: [], rules: []);

    [$compiled] = $component->compile();

    expect($compiled)->toBeNull();
});

// =========================================================================
// Inline rules override
// =========================================================================

it('uses inline rules passed to compile() instead of rules()', function (): void {
    $component = new TestableComponent(
        data: ['email' => 'test@example.com'],
        rules: ['name' => FluentRule::string()->required()],
    );

    [$compiled] = $component->compile(['email' => FluentRule::email()->required()]);

    assert(is_array($compiled));
    expect($compiled)->toHaveKey('email')
        ->and($compiled)->not->toHaveKey('name');
});

// =========================================================================
// Messages and attributes merging
// =========================================================================

it('merges compiled messages with caller-provided messages', function (): void {
    $component = new TestableComponent(
        data: ['name' => ''],
        rules: ['name' => FluentRule::string()->required()->message('Name is required!')],
    );

    [, $messages] = $component->compile(messages: ['name.min' => 'Too short!']);

    expect($messages)
        ->toHaveKeys(['name.required', 'name.min']);
});

it('caller-provided messages override compiled messages for the same key', function (): void {
    $component = new TestableComponent(
        data: ['name' => ''],
        rules: ['name' => FluentRule::string()->required()->message('Compiled message')],
    );

    [, $messages] = $component->compile(messages: ['name.required' => 'Caller message']);

    expect($messages['name.required'])->toBe('Caller message');
});

it('merges compiled attributes with caller-provided attributes', function (): void {
    $component = new TestableComponent(
        data: ['name' => ''],
        rules: ['name' => FluentRule::string('Full Name')->required()],
    );

    [, , $attributes] = $component->compile(attributes: ['email' => 'e-mail']);

    expect($attributes)
        ->toHaveKey('name', 'Full Name')
        ->toHaveKey('email', 'e-mail');
});

it('caller-provided attributes override compiled attributes for the same key', function (): void {
    $component = new TestableComponent(
        data: ['name' => ''],
        rules: ['name' => FluentRule::string('Compiled Label')->required()],
    );

    [, , $attributes] = $component->compile(attributes: ['name' => 'Caller Label']);

    expect($attributes['name'])->toBe('Caller Label');
});

// =========================================================================
// Wildcard expansion
// =========================================================================

it('expands wildcard rules into concrete keys', function (): void {
    $component = new TestableComponent(
        data: ['items' => [['name' => 'Foo'], ['name' => 'Bar']]],
        rules: [
            'items' => FluentRule::array()->required(),
            'items.*' => FluentRule::array()->required(),
            'items.*.name' => FluentRule::string()->required()->max(255),
        ],
    );

    [$compiled] = $component->compile();

    // WildcardExpander expands items.*.name → items.0.name, items.1.name
    expect($compiled)
        ->toHaveKeys(['items.0.name', 'items.1.name']);
});

// =========================================================================
// Data resolution fallback
// =========================================================================

it('falls back to all() for data when getDataForValidation() is absent', function (): void {
    $component = new TestableComponentNoRulesMethod(data: ['tag' => 'php']);

    [$compiled] = $component->compile(['tag' => FluentRule::string()->required()]);

    assert(is_array($compiled));
    expect($compiled)->toHaveKey('tag')
        ->and($compiled['tag'])->toContain('required');
});

it('uses unwrapDataForValidation() output when the method exists', function (): void {
    // rawData simulates what getDataForValidation() returns (e.g. Eloquent models);
    // unwrappedData is what unwrapDataForValidation() converts it to (plain arrays).
    // WildcardExpander must use the unwrapped data to expand wildcards correctly.
    $component = new TestableComponentWithUnwrap(
        rawData: ['items' => 'model-object'],
        unwrappedData: ['items' => [['name' => 'Foo'], ['name' => 'Bar']]],
        rules: [
            'items' => FluentRule::array()->required(),
            'items.*' => FluentRule::array()->required(),
            'items.*.name' => FluentRule::string()->required(),
        ],
    );

    [$compiled] = $component->compile();

    // Wildcard expansion must use the unwrapped array, not the raw model-object
    expect($compiled)
        ->toHaveKey('items.0.name')
        ->toHaveKey('items.1.name');
});
