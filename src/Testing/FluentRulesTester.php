<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Testing;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Livewire\Component;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Assert;
use ReflectionProperty;
use SanderMuller\FluentValidation\FluentValidator;
use SanderMuller\FluentValidation\RuleSet;
use SanderMuller\FluentValidation\Validated;

/**
 * Fluent tester for FluentRule chains, RuleSets, FormRequests, and FluentValidator
 * subclasses. Lets package consumers write direct unit tests without standing up
 * the HTTP kernel or Livewire harness.
 *
 *     FluentRulesTester::for($rules)->with($data)->passes();
 *     FluentRulesTester::for(StorePostRequest::class)->with($payload)->failsWith('email', 'email');
 *
 * `with()` is required before any assertion or escape hatch. Calling `passes()`,
 * `fails()`, `failsWith()`, `errors()`, or `validated()` without first calling
 * `with()` raises a LogicException.
 */
final class FluentRulesTester
{
    private const UNAUTHORIZED_ERROR_KEY = '_authorization';

    /** @var array<string, mixed>|null */
    private ?array $data = null;

    private ?Validated $result = null;

    /** @var array<string, mixed> */
    private array $routeParameters = [];

    private ?Authenticatable $actingAs = null;

    private ?string $actingAsGuard = null;

    /** @var array<string, mixed> */
    private array $mountParameters = [];

    /** @var list<array{0: string, 1: mixed}> Ordered set() applications; later overrides earlier. */
    private array $pendingSets = [];

    /** @var list<array{method: string, args: list<mixed>}> Ordered action queue; dispatched in append order against one Testable. */
    private array $callQueue = [];

    /**
     * @param  class-string<FormRequest>|class-string<FluentValidator>|class-string<Component>|RuleSet|ValidationRule|array<string, mixed>  $target
     * @param  list<mixed>  $constructorArgs
     */
    private function __construct(
        private readonly mixed $target,
        private readonly array $constructorArgs,
    ) {}

    /**
     * @param  class-string<FormRequest>|class-string<FluentValidator>|class-string<Component>|RuleSet|ValidationRule|array<string, mixed>  $target
     * @param  mixed  ...$constructorArgs  Forwarded to FluentValidator subclass constructors after `$data`. Ignored for non-class targets.
     */
    public static function for(mixed $target, mixed ...$constructorArgs): self
    {
        return new self($target, array_values($constructorArgs));
    }

    /** @param  array<string, mixed>  $data */
    public function with(array $data): self
    {
        $this->data = $data;
        $this->pendingSets = [];
        $this->callQueue = [];
        $this->result = null;

        return $this;
    }

    /**
     * Bind route parameters that the FormRequest can read via `$this->route(name)`.
     * Re-callable; later calls fully replace earlier ones (matches `with()`).
     * Only meaningful for FormRequest class-string targets.
     *
     *     ->withRoute(['video' => $video])
     *     // inside the FormRequest:
     *     // $this->route('video')          → $video
     *     // $this->route('video', $other)  → $video (default ignored when present)
     *     // $this->route('missing', $alt)  → $alt   (default returned)
     *
     * @param  array<string, mixed>  $parameters
     */
    public function withRoute(array $parameters): self
    {
        $this->routeParameters = $parameters;
        $this->result = null;

        return $this;
    }

    /**
     * Set the authenticated user that `$this->user()` returns inside the
     * FormRequest's `authorize()` and `rules()` methods. Mirrors Laravel's
     * `actingAs()` test helper. Only meaningful for FormRequest class-string
     * targets.
     */
    public function actingAs(Authenticatable $user, ?string $guard = null): self
    {
        $this->actingAs = $user;
        $this->actingAsGuard = $guard;
        $this->result = null;

        return $this;
    }

    /**
     * Set a Livewire component property. Proxies to `Testable::set()`. Accepts
     * either two-arg (`set('name', 'value')`) or single-array form
     * (`set(['name' => 'value', 'role' => 'admin'])`) — matches Livewire's API.
     * Re-callable; later calls override earlier values for the same key.
     * Only meaningful for Livewire component class-string targets.
     *
     * @param  string|array<string, mixed>  $key
     */
    public function set(string|array $key, mixed $value = null): self
    {
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $this->pendingSets[] = [$k, $v];
            }
        } else {
            $this->pendingSets[] = [$key, $value];
        }

        $this->result = null;

        return $this;
    }

    /**
     * Queue a Livewire component method to dispatch. At least one `call()`
     * is required before any assertion — Livewire validation only runs on
     * action dispatch.
     *
     * Multi-action sequences are supported via repeated `call()` (or the
     * `andCall()` alias for readability) — all queued actions dispatch in
     * order against ONE `Livewire::test()` instance, so state mutations
     * from action 1 persist into action 2:
     *
     *     ->call('selectVideo', $uuid)
     *     ->andCall('import')
     *     ->failsWith('selectedInteractionIds', 'required');
     *
     * The accumulated queue clears after each chain dispatches, so reused
     * testers don't leak prior cycles into subsequent ones.
     */
    public function call(string $method, mixed ...$args): self
    {
        $this->callQueue[] = ['method' => $method, 'args' => array_values($args)];
        $this->result = null;

        return $this;
    }

    /**
     * Readability alias for `call()`. Reads as "and then call" at the call
     * site:
     *
     *     ->call('openModal')
     *     ->andCall('submit')
     *
     * Functionally identical to `call()` — both append to the action queue.
     */
    public function andCall(string $method, mixed ...$args): self
    {
        return $this->call($method, ...$args);
    }

    /**
     * Mount the Livewire component with the given parameters. Skip when the
     * component takes no `mount()` args. Re-callable.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function mount(array $parameters): self
    {
        $this->mountParameters = $parameters;
        $this->result = null;

        return $this;
    }

    /**
     * Assert that the FormRequest's `authorize()` gate returned false.
     * Surfaces the recorded AuthorizationException without rethrowing.
     * Only meaningful for FormRequest class-string targets.
     */
    public function assertUnauthorized(): self
    {
        Assert::assertTrue(
            $this->resolve()->errors()->has(self::UNAUTHORIZED_ERROR_KEY),
            'Failed asserting that validation was blocked by authorize(). No AuthorizationException was raised.',
        );

        return $this;
    }

    public function passes(): self
    {
        $result = $this->resolve();

        Assert::assertTrue(
            $result->passes(),
            'Failed asserting that validation passes. Errors: '
                . $this->formatFirstErrors($result->errors()),
        );

        return $this;
    }

    public function fails(): self
    {
        $result = $this->resolve();

        Assert::assertTrue(
            $result->fails(),
            'Failed asserting that validation fails. Input data: '
                . $this->shortJson($this->data ?? []),
        );

        return $this;
    }

    /**
     * Assert that `$field` failed validation. When `$rule` is given, assert
     * that the field failed *that specific* Laravel rule key (case-insensitive,
     * normalized via Str::studly so callers may pass `required` or `Required`).
     *
     * Note: the lookup is against Laravel's actual rule-bag keys, which are
     * not always 1:1 with the `FluentRule` method name. Most notably,
     * `FluentRule::integer()` compiles to `numeric|integer` and a non-numeric
     * input fails as `Numeric` (Laravel evaluates `numeric` first), not as
     * `Integer`. When in doubt, inspect `errors()->keys()` or pass null.
     */
    public function failsWith(string $field, ?string $rule = null): self
    {
        $result = $this->resolve();

        Assert::assertTrue($result->fails(), "Expected validation to fail for [{$field}] but it passed.");

        Assert::assertTrue(
            $result->errors()->has($field),
            "Expected error on field [{$field}]. Got errors on: ["
                . implode(', ', $result->errors()->keys()) . '].',
        );

        if ($rule !== null) {
            $expected = Str::studly($rule);
            /** @var array<string, array<string, mixed>> $failed */
            $failed = $result->validator()->failed();
            $rulesForField = $failed[$field] ?? [];

            Assert::assertArrayHasKey(
                $expected,
                $rulesForField,
                "Expected field [{$field}] to fail rule [{$expected}]. Failed rules: ["
                    . implode(', ', array_keys($rulesForField)) . '].',
            );
        }

        return $this;
    }

    /**
     * Assert that exactly one field failed — `$field` is the only key in the
     * error bag. When `$rule` is given, also assert that field failed *that*
     * Laravel rule (Studly-normalized like `failsWith`).
     *
     * Surgical alternative to `fails()` for tests that should fail loudly when
     * an unrelated field also breaks (regression detection):
     *
     *     ->failsOnly('email', 'required')
     *
     * Wildcards: error keys are fully expanded (e.g. `items.0.name`). A
     * test triggering failures across multiple wildcard items will fail
     * `failsOnly` — that's intended strictness. Use `failsWithAny('items')`
     * for "any item failed."
     */
    public function failsOnly(string $field, ?string $rule = null): self
    {
        $result = $this->resolve();

        Assert::assertTrue(
            $result->fails(),
            "Expected validation to fail only for [{$field}] but it passed.",
        );

        $keys = $result->errors()->keys();

        Assert::assertSame(
            [$field],
            $keys,
            "Expected exactly [{$field}] to fail. Got errors on: ["
                . implode(', ', $keys) . '].',
        );

        if ($rule !== null) {
            $expected = Str::studly($rule);
            /** @var array<string, array<string, mixed>> $failed */
            $failed = $result->validator()->failed();
            $rulesForField = $failed[$field] ?? [];

            Assert::assertArrayHasKey(
                $expected,
                $rulesForField,
                "Expected field [{$field}] to fail rule [{$expected}]. Failed rules: ["
                    . implode(', ', array_keys($rulesForField)) . '].',
            );
        }

        return $this;
    }

    /**
     * Assert that none of the named fields appear in the error bag. Other
     * fields may have failed; this is a *negative* assertion only on the
     * listed fields.
     *
     * Use when a test needs to express "this field should pass even though
     * others fail" without enumerating the expected failures:
     *
     *     ->fails()
     *     ->doesNotFailOn('email', 'name')   // these passed, even if other fields failed
     */
    public function doesNotFailOn(string ...$fields): self
    {
        $result = $this->resolve();
        $errors = $result->errors();

        foreach ($fields as $field) {
            Assert::assertFalse(
                $errors->has($field),
                "Expected field [{$field}] to NOT have an error. Got errors on: ["
                    . implode(', ', $errors->keys()) . '].',
            );
        }

        return $this;
    }

    /**
     * Assert that *some* error key matches `$prefix` exactly OR is a dotted
     * descendant of it (`$prefix.*`). Useful for "did this subtree fail?"
     * assertions where you don't care about the specific child key:
     *
     *     ->failsWithAny('actions.0.payload')   // matches actions.0.payload OR actions.0.payload.stars OR …
     *     ->failsWithAny('amount')              // matches amount OR amount.currency OR amount.value
     *
     * Inclusive prefix-match only — does NOT match free-floating substrings
     * (`failsWithAny('payload')` will not match `actions.0.payload.stars`).
     * For substring/regex matching, use `errors()` directly.
     */
    public function failsWithAny(string $prefix): self
    {
        $result = $this->resolve();

        Assert::assertTrue(
            $result->fails(),
            "Expected validation to fail with at least one error matching [{$prefix}] or [{$prefix}.*] but validation passed.",
        );

        if ($result->errors()->has($prefix)) {
            return $this;
        }

        $needle = $prefix . '.';
        foreach ($result->errors()->keys() as $key) {
            if (str_starts_with($key, $needle)) {
                return $this;
            }
        }

        Assert::fail(
            "Expected an error matching [{$prefix}] or [{$prefix}.*]. Got errors on: ["
                . implode(', ', $result->errors()->keys()) . '].',
        );
    }

    /**
     * Assert that `$field` failed with the rendered translation of `$translationKey`.
     * Replacements are forwarded to the translator verbatim — pass `:attribute`
     * explicitly when your rules use labels (the validator pre-substitutes labels
     * into the message before the bag stores it, so the comparison value must
     * already match the labeled output).
     *
     *     ->failsWithMessage('email', 'validation.required', ['attribute' => 'Email'])
     *
     * @param  array<string, mixed>  $replacements
     */
    public function failsWithMessage(string $field, string $translationKey, array $replacements = []): self
    {
        $result = $this->resolve();

        Assert::assertTrue($result->fails(), "Expected validation to fail for [{$field}] but it passed.");

        Assert::assertTrue(
            $result->errors()->has($field),
            "Expected error on field [{$field}]. Got errors on: ["
                . implode(', ', $result->errors()->keys()) . '].',
        );

        $expected = $this->renderTranslation($translationKey, $replacements);
        $actual = $result->errors()->first($field);

        Assert::assertSame(
            $expected,
            $actual,
            "Expected message [{$expected}] on field [{$field}], got [{$actual}].",
        );

        return $this;
    }

    public function errors(): MessageBag
    {
        return $this->resolve()->errors();
    }

    /** @return array<string, mixed> */
    public function validated(): array
    {
        return $this->resolve()->validated();
    }

    private function resolve(): Validated
    {
        // Cache short-circuit: chained assertions on the same dispatch read
        // the previously-resolved result. Setup-completeness only matters
        // when no dispatch has happened yet (or fresh inputs invalidated
        // the cache via with/set/call/etc.).
        if ($this->result instanceof Validated) {
            return $this->result;
        }

        if ($this->isLivewireTarget()) {
            if ($this->callQueue === []) {
                throw new LogicException(
                    'FluentRulesTester::call(...) must be invoked before any assertion or escape hatch — Livewire targets only run validation on action dispatch.',
                );
            }
        } elseif ($this->data === null) {
            throw new LogicException(
                'FluentRulesTester::with() must be called before any assertion or escape hatch.',
            );
        }

        return $this->result = $this->run($this->data ?? []);
    }

    /** @param  array<string, mixed>  $data */
    private function run(array $data): Validated
    {
        if ($this->target instanceof RuleSet) {
            return $this->target->check($data);
        }

        if (is_array($this->target)) {
            return RuleSet::from($this->target)->check($data);
        }

        if ($this->target instanceof ValidationRule) {
            return RuleSet::from(['value' => $this->target])->check($data);
        }

        if (is_string($this->target) && is_subclass_of($this->target, FormRequest::class)) {
            return $this->runFormRequest($this->target, $data);
        }

        if (is_string($this->target) && is_subclass_of($this->target, FluentValidator::class)) {
            return $this->runFluentValidator($this->target, $data);
        }

        if ($this->isLivewireTarget()) {
            return $this->runLivewire($data);
        }

        throw new LogicException(
            'Unsupported target. Pass an array of rules, a RuleSet, a ValidationRule, a FormRequest class-string, a FluentValidator class-string, or a Livewire Component class-string.',
        );
    }

    /**
     * @phpstan-assert-if-true class-string<Component> $this->target
     */
    private function isLivewireTarget(): bool
    {
        return is_string($this->target)
            && class_exists(Component::class)
            && is_subclass_of($this->target, Component::class);
    }

    /**
     * Mirrors Laravel's own form-request resolver: instantiate via createFrom,
     * set container + redirector + user resolver, call validateResolved().
     * Internals shift subtly across Laravel majors; CI matrix exercises it.
     *
     * @param  class-string<FormRequest>  $class
     * @param  array<string, mixed>  $data
     */
    private function runFormRequest(string $class, array $data): Validated
    {
        $request = Request::create('/', 'POST', $data);

        if ($this->routeParameters !== []) {
            $request->setRouteResolver(fn (): object => $this->makeRouteShim($this->routeParameters));
        }

        if (function_exists('app') && app()->bound('auth')) {
            if ($this->actingAs instanceof Authenticatable) {
                resolve(Factory::class)->guard($this->actingAsGuard)->setUser($this->actingAs);
            }

            $request->setUserResolver(static fn (?string $guard = null): mixed => resolve(Factory::class)->guard($guard)->user());
        }

        /** @var FormRequest $instance */
        $instance = $class::createFrom($request);
        $instance->setContainer(app());
        $instance->setRedirector(resolve(Redirector::class));

        try {
            $instance->validateResolved();
        } catch (ValidationException $validationException) {
            return $this->wrap($validationException->validator);
        } catch (AuthorizationException) {
            return $this->wrap($this->buildUnauthorizedValidator());
        }

        return $this->wrap($this->extractValidator($instance));
    }

    /**
     * Build a minimal route stand-in for `$request->route(name, default)`.
     * Laravel's Route::parameter() reads from a parameters map; this shim
     * exposes the same surface (`parameter()` + `parameters()`) so FormRequests
     * that introspect routes during `authorize()` or `rules()` Just Work.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function makeRouteShim(array $parameters): object
    {
        return new class ($parameters) {
            /** @param  array<string, mixed>  $parameters */
            public function __construct(private readonly array $parameters) {}

            public function parameter(string $name, mixed $default = null): mixed
            {
                return $this->parameters[$name] ?? $default;
            }

            /** @return array<string, mixed> */
            public function parameters(): array
            {
                return $this->parameters;
            }
        };
    }

    /**
     * FormRequest exposes no public getter for its validator; the property
     * location is stable across Laravel 11/12/13, so reflection is the
     * least-coupled option.
     */
    private function extractValidator(FormRequest $request): ValidatorContract
    {
        $property = new ReflectionProperty(FormRequest::class, 'validator');
        /** @var ValidatorContract $validator */
        $validator = $property->getValue($request);

        return $validator;
    }

    /**
     * @param  class-string<FluentValidator>  $class
     * @param  array<string, mixed>  $data
     */
    private function runFluentValidator(string $class, array $data): Validated
    {
        /** @var FluentValidator $validator */
        $validator = new $class($data, ...$this->constructorArgs);

        return $this->wrap($validator);
    }

    /**
     * Resolve a Livewire component class-string through `Livewire::test()`,
     * apply accumulated state via `set()`, dispatch the action via `call()`,
     * and harvest errors via `Testable::errors()` (Livewire 3+ public API).
     *
     * The underlying Validator (when present in the component's testing
     * store) is used directly so `failsWith($field, $rule)` reads the real
     * `Validator::failed()` payload. When no validator is stored (action ran
     * without invoking `$this->validate()`), a synthetic empty Validator is
     * built so the `Validated` DTO contract holds.
     *
     * Pre-condition: callers must guard with `$this->isLivewireTarget()` so
     * `$this->target` is a `class-string<\Livewire\Component>` at this point.
     * Asserted at runtime to satisfy PHPStan without inline `@var` overrides.
     *
     * @param  array<string, mixed>  $data
     */
    private function runLivewire(array $data): Validated
    {
        $class = $this->target;

        if (! is_string($class)) {
            throw new LogicException('runLivewire called without a class-string target.');
        }

        $component = Livewire::test($class, $this->mountParameters);

        foreach ($data as $key => $value) {
            $component->set($key, $value);
        }

        foreach ($this->pendingSets as [$key, $value]) {
            $component->set($key, $value);
        }

        // Dispatch every queued action in order against the same Testable
        // instance. State mutations from earlier actions persist for later
        // ones — that's the whole point of multi-action support.
        foreach ($this->callQueue as $call) {
            $component->call($call['method'], ...$call['args']);
        }

        // Consume the dispatch inputs. After the Validated DTO is cached the
        // tester can be reused for another cycle; the next set()/with()/call()
        // builds against a clean slate so prior cycles don't bleed into the
        // new dispatch's component state.
        $this->data = null;
        $this->pendingSets = [];
        $this->callQueue = [];

        $rawErrors = $component->errors();
        $errors = $rawErrors instanceof MessageBag ? $rawErrors : new MessageBag();

        $validator = $this->extractLivewireValidator($component);

        if ($validator instanceof ValidatorContract) {
            return $this->wrap($validator);
        }

        return new Validated(
            passes: $errors->isEmpty(),
            validated: [],
            errors: $errors,
            validator: ValidatorFacade::make([], []),
        );
    }

    /**
     * Pull the underlying Validator from Livewire's per-component test store
     * if present. Returns null when no `validate()` call ran during the action
     * — in that case the caller falls back to a synthetic Validator so the
     * `Validated` DTO contract still holds.
     *
     * @param  Testable<Component>  $component
     */
    private function extractLivewireValidator(Testable $component): ?ValidatorContract
    {
        if (! function_exists('Livewire\\store')) {
            return null;
        }

        $store = \Livewire\store($component->instance());

        if (! is_object($store) || ! method_exists($store, 'get')) {
            return null;
        }

        $validator = $store->get('testing.validator');

        return $validator instanceof ValidatorContract ? $validator : null;
    }

    /**
     * Wrap any Validator into a Validated DTO. Branches on `errors()` rather
     * than `fails()` so the synthetic auth-failure validator (with manually
     * pushed errors but empty rules) is reported as failing too.
     */
    private function wrap(ValidatorContract $validator): Validated
    {
        $errors = $validator->errors();

        if ($errors->isNotEmpty() || $validator->fails()) {
            return new Validated(
                passes: false,
                validated: [],
                errors: $validator->errors(),
                validator: $validator,
            );
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();

        return new Validated(
            passes: true,
            validated: $validated,
            errors: new MessageBag(),
            validator: $validator,
        );
    }

    private function buildUnauthorizedValidator(): Validator
    {
        /** @var Validator $validator */
        $validator = ValidatorFacade::make([], []);
        $validator->errors()->add(self::UNAUTHORIZED_ERROR_KEY, 'This action is unauthorized.');

        return $validator;
    }

    /** @param  array<string, mixed>  $replacements */
    private function renderTranslation(string $key, array $replacements): string
    {
        $rendered = resolve(Translator::class)->get($key, $replacements);

        return is_string($rendered) ? $rendered : $key;
    }

    private function formatFirstErrors(MessageBag $messageBag): string
    {
        $first = array_slice($messageBag->toArray(), 0, 3, preserve_keys: true);

        return $first === [] ? '(none)' : $this->shortJson($first);
    }

    /** @param  array<array-key, mixed>  $value */
    private function shortJson(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
