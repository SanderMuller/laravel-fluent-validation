<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Rules\Concerns;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\ExcludeIf;
use Illuminate\Validation\Rules\ExcludeUnless;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\NotIn;
use Illuminate\Validation\Rules\ProhibitedIf;
use Illuminate\Validation\Rules\ProhibitedUnless;
use Illuminate\Validation\Rules\RequiredIf;
use Illuminate\Validation\Rules\RequiredUnless;

trait SelfValidates
{
    public bool $implicit = true;

    protected \Illuminate\Validation\Validator $validator;

    /** @var array<array-key, mixed> */
    protected array $data = [];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->hasPresenceModifier() && ! Arr::has($this->data, $attribute)) {
            return;
        }

        if ($this->shouldExclude($attribute)) {
            return;
        }

        if ($this->isNullable($value)) {
            return;
        }

        $rules = $this->buildRulesForAttribute($attribute);

        $innerValidator = Validator::make(
            $this->data,
            $rules,
            $this->buildMessages($attribute),
            $this->buildAttributes($attribute),
        );

        if ($innerValidator->passes()) {
            return;
        }

        $this->forwardErrors($innerValidator, $attribute, count($rules) > 1, $fail);
    }

    /** @return array<string, mixed> */
    private function buildRulesForAttribute(string $attribute): array
    {
        $rules = [$attribute => $this->buildValidationRules()];

        foreach ($this->buildNestedRules($attribute) as $nestedAttribute => $nestedRule) {
            $rules[$nestedAttribute] = $nestedRule;
        }

        return $rules;
    }

    /** @return array<string, string> */
    private function buildMessages(string $attribute): array
    {
        /** @var array<string, string> $messages */
        $messages = $this->validator->customMessages ?? [];

        foreach ($this->getCustomMessages() as $ruleName => $message) {
            $messages[$ruleName === '' ? $attribute : $attribute . '.' . $ruleName] = $message;
        }

        return $messages;
    }

    /** @return array<string, string> */
    private function buildAttributes(string $attribute): array
    {
        /** @var array<string, string> $attributes */
        $attributes = $this->validator->customAttributes ?? [];

        if ($this->getLabel() !== null) {
            $attributes[$attribute] = $this->getLabel();
        }

        return $attributes;
    }

    private function forwardErrors(
        \Illuminate\Validation\Validator $innerValidator,
        string $attribute,
        bool $hasNestedRules,
        Closure $fail,
    ): void {
        /** @var array<string, list<string>> $errors */
        $errors = $innerValidator->errors()->toArray();

        foreach ($errors as $errorAttribute => $errorMessages) {
            foreach ($errorMessages as $errorMessage) {
                if ($hasNestedRules && $errorAttribute !== $attribute) {
                    $this->validator->errors()->add($errorAttribute, $errorMessage);
                } else {
                    $fail($errorMessage);
                }
            }
        }

        $this->forwardFailedRuleIdentifiers($innerValidator);
    }

    /**
     * Copy failed rule identifiers from the inner validator to the outer
     * so assertHasErrors(['field' => 'rule']) and $validator->failed() work.
     */
    private function forwardFailedRuleIdentifiers(\Illuminate\Validation\Validator $innerValidator): void
    {
        /** @var array<string, array<string, list<mixed>>> $failedRules */
        $failedRules = $innerValidator->failed();

        if ($failedRules === []) {
            return;
        }

        $prop = new \ReflectionProperty($this->validator, 'failedRules');

        /** @var array<string, array<string, list<mixed>>> $existing */
        $existing = $prop->getValue($this->validator);

        foreach ($failedRules as $failedAttribute => $rules) {
            /** @var array<string, list<mixed>> $rules */
            foreach ($rules as $rule => $params) {
                $existing[$failedAttribute][$rule] = $params;
            }
        }

        $prop->setValue($this->validator, $existing);
    }

    private function isNullable(mixed $value): bool
    {
        return is_null($value)
            && ! in_array('required', $this->constraints, true)
            && in_array('nullable', $this->constraints, true);
    }

    protected function hasPresenceModifier(): bool
    {
        if (array_intersect($this->constraints, ['required', 'sometimes', 'nullable', 'present']) !== []) {
            return true;
        }

        if ($this->hasPresenceConstraint()) {
            return true;
        }

        return $this->hasPresenceRule();
    }

    private function hasPresenceConstraint(): bool
    {
        foreach ($this->constraints as $constraint) {
            if (str_starts_with((string) $constraint, 'required_')
                || str_starts_with((string) $constraint, 'present_')
                || str_starts_with((string) $constraint, 'exclude')
                || str_starts_with((string) $constraint, 'prohibited')
                || str_starts_with((string) $constraint, 'missing')
            ) {
                return true;
            }
        }

        return false;
    }

    private function hasPresenceRule(): bool
    {
        foreach ($this->rules as $rule) {
            if ($rule instanceof RequiredIf
                || $rule instanceof RequiredUnless
                || $rule instanceof ProhibitedIf
                || $rule instanceof ProhibitedUnless
                || $rule instanceof ExcludeIf
                || $rule instanceof ExcludeUnless
            ) {
                return true;
            }
        }

        return false;
    }

    private function shouldExclude(string $attribute): bool
    {
        // Check ExcludeIf/ExcludeUnless rule objects (closure/bool variant)
        foreach ($this->rules as $rule) {
            if ($rule instanceof ExcludeIf || $rule instanceof ExcludeUnless) {
                $resolved = (string) $rule;

                if ($resolved === 'exclude') {
                    $this->validator->addFailure($attribute, 'Exclude');

                    return true;
                }
            }
        }

        // Check string-based exclude constraints
        foreach ($this->constraints as $constraint) {
            if ($constraint === 'exclude') {
                $this->validator->addFailure($attribute, 'Exclude');

                return true;
            }
        }

        return false;
    }

    public function canCompile(): bool
    {
        return $this->rules === [];
    }

    /** @var string|list<string|object>|null */
    protected string|array|null $compiledCache = null;

    public function __clone(): void
    {
        $this->compiledCache = null;
    }

    /**
     * Compile to native Laravel format. Returns a pipe-joined string
     * when possible (faster for Laravel to parse), or an array when
     * non-stringable object rules are present. Memoized since rule
     * objects are immutable after construction.
     *
     * @return string|list<string|object>
     */
    public function compiledRules(): string|array
    {
        return $this->compiledCache ??= $this->buildCompiledRules();
    }

    /**
     * Get the compiled rules as an array. Useful for debugging and testing.
     *
     * @return list<string|object>
     */
    public function toArray(): array
    {
        $compiled = $this->compiledRules();

        if (is_array($compiled)) {
            return $compiled;
        }

        return $compiled === '' ? [] : explode('|', $compiled);
    }

    /**
     * Dump the compiled rules and terminate execution.
     */
    public function dd(mixed ...$args): never
    {
        dd($this->toArray(), ...$args);
    }

    /**
     * Dump the compiled rules.
     *
     * @return $this
     */
    public function dump(mixed ...$args): static
    {
        dump($this->toArray(), ...$args);

        return $this;
    }

    /** @return string|list<string|object> */
    private function buildCompiledRules(): string|array
    {
        $rules = $this->buildValidationRules();

        // Try to produce a pipe-joined string for fastest Laravel parsing.
        // Only In and NotIn are safe to stringify — Exists/Unique/Dimensions
        // can have closure-based wheres that are silently dropped by __toString().
        /** @var list<string> $stringified */
        $stringified = [];
        $allStringifiable = true;

        foreach ($rules as $rule) {
            if (is_string($rule)) {
                $stringified[] = $rule;
            } elseif ($rule instanceof \Stringable && $this->isSafeToStringify($rule)) {
                $stringified[] = (string) $rule;
            } else {
                $allStringifiable = false;
                break;
            }
        }

        if ($allStringifiable) {
            /** @var list<string> $stringified — all elements are strings (either originally or via __toString) */
            return implode('|', $stringified);
        }

        // Presence modifiers (ExcludeIf, RequiredIf, etc.) must run before
        // type constraints. Other object rules (closures, custom ValidationRule)
        // run after constraints so bail/type checks can stop validation first.
        $presenceRules = [];
        $strings = [];
        $otherRules = [];

        foreach ($rules as $rule) {
            if (is_string($rule)) {
                $strings[] = $rule;
            } elseif ($this->isPresenceModifier($rule)) {
                $presenceRules[] = $rule;
            } else {
                $otherRules[] = $rule;
            }
        }

        return [...$presenceRules, ...$strings, ...$otherRules];
    }

    /**
     * Only In and NotIn are safe to stringify — they hold scalar value lists.
     * Exists, Unique, and Dimensions can have closure-based wheres or callbacks
     * that are silently dropped by __toString().
     */
    private function isSafeToStringify(object $rule): bool
    {
        return $rule instanceof In
            || $rule instanceof NotIn;
    }

    private function isPresenceModifier(object $rule): bool
    {
        return $rule instanceof ExcludeIf || $rule instanceof ExcludeUnless
            || $rule instanceof RequiredIf || $rule instanceof RequiredUnless
            || $rule instanceof ProhibitedIf || $rule instanceof ProhibitedUnless;
    }

    /** @return list<string|object> */
    protected function buildValidationRules(): array
    {
        return [...$this->rules, ...$this->constraints];
    }

    /** @return array<string, mixed> */
    public function buildNestedRules(string $attribute): array
    {
        return [];
    }

    public function setValidator(\Illuminate\Contracts\Validation\Validator $validator): static
    {
        $this->validator = $validator;

        return $this;
    }

    /** @param  array<array-key, mixed>  $data */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }
}
