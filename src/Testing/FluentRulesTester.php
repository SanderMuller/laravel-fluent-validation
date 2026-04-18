<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Testing;

use Illuminate\Auth\Access\AuthorizationException;
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

    /**
     * @param  class-string<FormRequest>|class-string<FluentValidator>|RuleSet|ValidationRule|array<string, mixed>  $target
     * @param  list<mixed>  $constructorArgs
     */
    private function __construct(
        private readonly mixed $target,
        private readonly array $constructorArgs,
    ) {}

    /**
     * @param  class-string<FormRequest>|class-string<FluentValidator>|RuleSet|ValidationRule|array<string, mixed>  $target
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
        if ($this->data === null) {
            throw new LogicException(
                'FluentRulesTester::with() must be called before any assertion or escape hatch.',
            );
        }

        return $this->result ??= $this->run($this->data);
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

        throw new LogicException(
            'Unsupported target. Pass an array of rules, a RuleSet, a ValidationRule, a FormRequest class-string, or a FluentValidator class-string.',
        );
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

        if (function_exists('app') && app()->bound('auth')) {
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
