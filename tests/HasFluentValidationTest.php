<?php declare(strict_types=1);

use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\HasFluentValidation;
use SanderMuller\FluentValidation\RuleSet;

// =========================================================================
// Test doubles
// =========================================================================

/**
 * Simulates the minimal Livewire Component parent.
 * Methods are typed as mixed to match what HasFluentValidation overrides.
 */
class FakeLivewireBase
{
    /** @var list<mixed> */
    public array $messagesFromOutside = [];

    /** @var list<mixed> */
    public array $validationAttributesFromOutside = [];

    public function validate(mixed $rules = null, mixed $messages = [], mixed $attributes = []): mixed
    {
        return null;
    }

    public function validateOnly(mixed $field, mixed $rules = null, mixed $messages = [], mixed $attributes = [], mixed $dataOverrides = []): mixed
    {
        return null;
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
    public function __construct(public array $data = [], private array $fluentRules = []) {}

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
    public function __construct(public array $data = []) {}

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

    /**
     * @param  array<string, mixed>  $rawData
     * @param  array<string, mixed>  $unwrappedData
     * @param  array<string, mixed>|RuleSet  $rules
     */
    public function __construct(public array $rawData, public array $unwrappedData, private array|RuleSet $rules = []) {}

    /** @return array<string, mixed>|RuleSet */
    public function rules(): array|RuleSet
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
        fluentRules: ['name' => FluentRule::string()->required()->max(255)],
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
        fluentRules: ['name' => FluentRule::string('Full Name')->required()],
    );

    [, , $attributes] = $component->compile();

    expect($attributes)->toHaveKey('name')
        ->and($attributes['name'])->toBe('Full Name');
});

it('extracts per-rule messages into the messages array', function (): void {
    $component = new TestableComponent(
        data: ['name' => ''],
        fluentRules: ['name' => FluentRule::string()->required()->message('Name is required!')],
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
        fluentRules: ['name' => 'required|string|max:255'],
    );

    [$compiled] = $component->compile();

    // No FluentRule objects — null signals Livewire to use getRules()
    expect($compiled)->toBeNull();
});

it('returns null rules when no rules() method and no inline rules passed', function (): void {
    $component = new TestableComponentNoRulesMethod(data: ['name' => 'John']);

    [$compiled] = $component->compile();

    expect($compiled)->toBeNull();
});

it('explicitly passed empty rules array does NOT fall back to rules() method', function (): void {
    // Regression: $this->validate([]) or validateOnly(..., []) on a Livewire
    // component must honor the explicit empty override, not silently reuse
    // the component's rules() default. A falsy-empty check would treat [] as
    // "no rules passed" — this test pins the null-vs-[] distinction.
    $component = new TestableComponent(
        data: ['name' => 'John'],
        fluentRules: ['name' => FluentRule::string()->required()->max(255)],
    );

    [$compiled, $messages, $attributes] = $component->compile([]);

    // Empty inline rules → passed through as-is; rules() default (which has
    // a FluentRule object) must NOT be compiled.
    expect($compiled)->toBeEmpty();
    expect($messages)->toBeEmpty()
        ->and($attributes)->toBeEmpty();
});

it('returns null rules when rules() returns empty array', function (): void {
    $component = new TestableComponent(data: [], fluentRules: []);

    [$compiled] = $component->compile();

    expect($compiled)->toBeNull();
});

// =========================================================================
// Inline rules override
// =========================================================================

it('uses inline rules passed to compile() instead of rules()', function (): void {
    $component = new TestableComponent(
        data: ['email' => 'test@example.com'],
        fluentRules: ['name' => FluentRule::string()->required()],
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
        fluentRules: ['name' => FluentRule::string()->required()->message('Name is required!')],
    );

    [, $messages] = $component->compile(messages: ['name.min' => 'Too short!']);

    expect($messages)
        ->toHaveKeys(['name.required', 'name.min']);
});

it('caller-provided messages override compiled messages for the same key', function (): void {
    $component = new TestableComponent(
        data: ['name' => ''],
        fluentRules: ['name' => FluentRule::string()->required()->message('Compiled message')],
    );

    [, $messages] = $component->compile(messages: ['name.required' => 'Caller message']);

    expect($messages['name.required'])->toBe('Caller message');
});

it('merges compiled attributes with caller-provided attributes', function (): void {
    $component = new TestableComponent(
        data: ['name' => ''],
        fluentRules: ['name' => FluentRule::string('Full Name')->required()],
    );

    [, , $attributes] = $component->compile(attributes: ['email' => 'e-mail']);

    expect($attributes)
        ->toHaveKey('name', 'Full Name')
        ->toHaveKey('email', 'e-mail');
});

it('caller-provided attributes override compiled attributes for the same key', function (): void {
    $component = new TestableComponent(
        data: ['name' => ''],
        fluentRules: ['name' => FluentRule::string('Compiled Label')->required()],
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
        fluentRules: [
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
        ->toHaveKeys(['items.0.name', 'items.1.name']);
});

// =========================================================================
// HasFluentValidation — auto-unwrap RuleSet returned from rules()
// =========================================================================

it('HasFluentValidation auto-unwraps a RuleSet returned from rules()', function (): void {
    $ruleSet = RuleSet::from([
        'name' => FluentRule::string()->required()->min(2),
    ]);

    $component = new TestableComponentWithUnwrap(
        rawData: ['name' => 'Ada'],
        unwrappedData: ['name' => 'Ada'],
        rules: $ruleSet,
    );

    $rules = $component->getRules();

    expect($rules)->toHaveKey('name')
        ->and($rules['name'])->toContain('required')
        ->and($rules['name'])->toContain('min:2');
});

// =========================================================================
// Livewire + each() support via getRules()
// =========================================================================

it('getRules() expands each() into wildcard keys', function (): void {
    $component = new TestableComponent(
        data: ['items' => [['name' => 'Foo'], ['name' => 'Bar']]],
        fluentRules: [
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required(),
            ]),
        ],
    );

    $rules = $component->getRules();

    // getRules() returns wildcard keys (items.*.name) not concrete keys (items.0.name)
    // so Livewire's hasRuleFor() and validateOnly() can match them
    expect($rules)
        ->toHaveKeys(['items', 'items.*.name']);
});

it('getRules() preserves flat wildcard keys', function (): void {
    $component = new TestableComponent(
        data: ['items' => [['name' => 'Foo']]],
        fluentRules: [
            'items' => FluentRule::array()->required(),
            'items.*.name' => FluentRule::string()->required(),
        ],
    );

    $rules = $component->getRules();

    expect($rules)->toHaveKey('items.*.name');
});

it('getRules() returns plain string rules unchanged', function (): void {
    $component = new TestableComponent(
        data: ['name' => 'John'],
        fluentRules: [
            'name' => 'required|string|max:255',
        ],
    );

    $rules = $component->getRules();

    expect($rules)->toBe(['name' => 'required|string|max:255']);
});

it('validate() expands each() into concrete keys for validation', function (): void {
    $component = new TestableComponent(
        data: ['items' => [['name' => 'Foo'], ['name' => 'Bar']]],
        fluentRules: [
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required(),
            ]),
        ],
    );

    // compile() uses compileFluentRules which is used by validate() for inline rules
    [$compiled] = $component->compile();

    expect($compiled)
        ->toHaveKeys(['items', 'items.0.name', 'items.1.name']);
});

// =========================================================================
// getMessages() and getValidationAttributes() from FluentRule metadata
// =========================================================================

it('getMessages() extracts messages from FluentRule objects', function (): void {
    $component = new TestableComponent(
        data: ['name' => 'John'],
        fluentRules: [
            'name' => FluentRule::string()->required()->message('Name is required!'),
        ],
    );

    $messages = $component->getMessages();

    expect($messages)->toHaveKey('name.required')
        ->and($messages['name.required'])->toBe('Name is required!');
});

it('getValidationAttributes() extracts labels from FluentRule objects', function (): void {
    $component = new TestableComponent(
        data: ['name' => 'John'],
        fluentRules: [
            'name' => FluentRule::string('Full Name')->required(),
        ],
    );

    $attributes = $component->getValidationAttributes();

    expect($attributes)->toHaveKey('name')
        ->and($attributes['name'])->toBe('Full Name');
});

it('getMessages() extracts messages from each() rules', function (): void {
    $component = new TestableComponent(
        data: ['items' => [['name' => 'Foo']]],
        fluentRules: [
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string('Item Name')->required()->message('Each item needs a name'),
            ]),
        ],
    );

    $messages = $component->getMessages();
    $attributes = $component->getValidationAttributes();

    expect($messages)->toHaveKey('items.*.name.required')
        ->and($attributes)->toHaveKey('items.*.name')
        ->and($attributes['items.*.name'])->toBe('Item Name');
});

it('getRules() expands children() into fixed paths', function (): void {
    $component = new TestableComponent(
        data: ['credentials' => ['base_uri' => 'https://example.com', 'client_id' => '123']],
        fluentRules: [
            'credentials' => FluentRule::array()->children([
                'base_uri' => FluentRule::string()->nullable()->url(),
                'client_id' => FluentRule::string()->required()->uuid(),
            ]),
        ],
    );

    $rules = $component->getRules();

    expect($rules)
        ->toHaveKeys(['credentials', 'credentials.base_uri', 'credentials.client_id']);
});

it('getValidationAttributes() extracts labels from children() rules', function (): void {
    $component = new TestableComponent(
        data: ['credentials' => ['base_uri' => 'https://example.com']],
        fluentRules: [
            'credentials' => FluentRule::array()->children([
                'base_uri' => FluentRule::string('Base URI')->nullable()->url(),
            ]),
        ],
    );

    $attributes = $component->getValidationAttributes();

    expect($attributes)->toHaveKey('credentials.base_uri')
        ->and($attributes['credentials.base_uri'])->toBe('Base URI');
});

it('getMessages() returns empty array when no FluentRules', function (): void {
    $component = new TestableComponent(
        data: ['name' => 'John'],
        fluentRules: ['name' => 'required|string'],
    );

    expect($component->getMessages())->toBeEmpty()
        ->and($component->getValidationAttributes())->toBeEmpty();
});

it('getMessages() merges messagesFromOutside with FluentRule messages', function (): void {
    $component = new TestableComponent(
        data: ['name' => 'John'],
        fluentRules: [
            'name' => FluentRule::string()->required()->message('Name is required!'),
        ],
    );

    $component->messagesFromOutside[] = ['name.max' => 'Too long!'];

    $messages = $component->getMessages();

    expect($messages)
        ->toHaveKey('name.required', 'Name is required!')
        ->toHaveKey('name.max', 'Too long!');
});

it('getValidationAttributes() merges validationAttributesFromOutside with FluentRule labels', function (): void {
    $component = new TestableComponent(
        data: ['name' => 'John', 'email' => 'john@example.com'],
        fluentRules: [
            'name' => FluentRule::string('Full Name')->required(),
            'email' => FluentRule::email()->required(),
        ],
    );

    $component->validationAttributesFromOutside[] = ['email' => 'E-mail Address'];

    $attributes = $component->getValidationAttributes();

    expect($attributes)
        ->toHaveKey('name', 'Full Name')
        ->toHaveKey('email', 'E-mail Address');
});
