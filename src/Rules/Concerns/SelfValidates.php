<?php

declare(strict_types=1);

namespace SanderMuller\FluentValidation\Rules\Concerns;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\ExcludeIf;
use Illuminate\Validation\Rules\ExcludeUnless;
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

        $rules = [$attribute => $this->buildValidationRules()];

        foreach ($this->buildNestedRules($attribute) as $nestedAttribute => $nestedRule) {
            $rules[$nestedAttribute] = $nestedRule;
        }

        // Merge per-rule custom messages (from ->message()) with validator messages
        $messages = $this->validator->customMessages ?? [];
        foreach ($this->getCustomMessages() as $ruleName => $message) {
            $messages[$attribute . '.' . $ruleName] = $message;
        }

        // Merge label (from ->label()) with validator custom attributes
        $attributes = $this->validator->customAttributes ?? [];
        if ($this->getLabel() !== null) {
            $attributes[$attribute] = $this->getLabel();
        }

        $validator = Validator::make(
            $this->data,
            $rules,
            $messages,
            $attributes
        );

        foreach ($validator->errors()->all() as $message) {
            $fail($message);
        }
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
    private string|array|null $compiledCache = null;

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

    /** @return string|list<string|object> */
    private function buildCompiledRules(): string|array
    {
        if ($this->rules === []) {
            return implode('|', $this->constraints);
        }

        /** @var list<string> $stringRules */
        $stringRules = $this->constraints;

        foreach ($this->rules as $rule) {
            if (! $rule instanceof \Stringable) {
                return [...$this->constraints, ...$this->rules];
            }

            $stringRules[] = (string) $rule;
        }

        return implode('|', $stringRules);
    }

    /** @return list<string|object> */
    protected function buildValidationRules(): array
    {
        return [...$this->constraints, ...$this->rules];
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
