<?php

declare(strict_types=1);

namespace SanderMuller\FluentValidation\Rules\Concerns;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rules\ProhibitedIf;
use Illuminate\Validation\Rules\ProhibitedUnless;
use Illuminate\Validation\Rules\RequiredIf;
use Illuminate\Validation\Rules\RequiredUnless;

trait HasFieldModifiers
{
    /** @var list<object> */
    protected array $rules = [];

    /**
     * Strings are appended to $constraints. Objects are appended to $rules.
     *
     * @param  array<int, string>|string|object  $rules
     */
    protected function addRule(array|string|object $rules): static
    {
        if (is_object($rules)) {
            $this->rules[] = $rules;
        } else {
            $this->constraints = array_merge($this->constraints, Arr::wrap($rules));
        }

        return $this;
    }

    public function bail(): static
    {
        return $this->addRule('bail');
    }

    public function nullable(): static
    {
        return $this->addRule('nullable');
    }

    public function required(): static
    {
        return $this->addRule('required');
    }

    public function sometimes(): static
    {
        return $this->addRule('sometimes');
    }

    public function filled(): static
    {
        return $this->addRule('filled');
    }

    public function present(): static
    {
        return $this->addRule('present');
    }

    public function prohibited(): static
    {
        return $this->addRule('prohibited');
    }

    public function exclude(): static
    {
        return $this->addRule('exclude');
    }

    public function missing(): static
    {
        return $this->addRule('missing');
    }

    public function requiredIf(Closure|bool|string $field, string|int ...$values): static
    {
        if ($field instanceof Closure || is_bool($field)) {
            return $this->addRule(new RequiredIf($field));
        }

        return $this->addRule('required_if:' . $field . ',' . implode(',', $values));
    }

    public function requiredUnless(Closure|bool|string $field, string|int ...$values): static
    {
        if ($field instanceof Closure || is_bool($field)) {
            return $this->addRule(new RequiredUnless($field));
        }

        return $this->addRule('required_unless:' . $field . ',' . implode(',', $values));
    }

    public function requiredWith(string ...$fields): static
    {
        return $this->addRule('required_with:' . implode(',', $fields));
    }

    public function requiredWithAll(string ...$fields): static
    {
        return $this->addRule('required_with_all:' . implode(',', $fields));
    }

    public function requiredWithout(string ...$fields): static
    {
        return $this->addRule('required_without:' . implode(',', $fields));
    }

    public function requiredWithoutAll(string ...$fields): static
    {
        return $this->addRule('required_without_all:' . implode(',', $fields));
    }

    public function excludeIf(string $field, string|int ...$values): static
    {
        return $this->addRule('exclude_if:' . $field . ',' . implode(',', $values));
    }

    public function excludeUnless(string $field, string|int ...$values): static
    {
        return $this->addRule('exclude_unless:' . $field . ',' . implode(',', $values));
    }

    public function excludeWith(string $field): static
    {
        return $this->addRule('exclude_with:' . $field);
    }

    public function excludeWithout(string $field): static
    {
        return $this->addRule('exclude_without:' . $field);
    }

    public function prohibitedIf(Closure|bool|string $field, string|int ...$values): static
    {
        if ($field instanceof Closure || is_bool($field)) {
            return $this->addRule(new ProhibitedIf($field));
        }

        return $this->addRule('prohibited_if:' . $field . ',' . implode(',', $values));
    }

    public function prohibitedUnless(Closure|bool|string $field, string|int ...$values): static
    {
        if ($field instanceof Closure || is_bool($field)) {
            return $this->addRule(new ProhibitedUnless($field));
        }

        return $this->addRule('prohibited_unless:' . $field . ',' . implode(',', $values));
    }

    public function prohibits(string ...$fields): static
    {
        return $this->addRule('prohibits:' . implode(',', $fields));
    }

    /**
     * @param  ValidationRule|Closure(string, mixed, Closure): void|string  $rule
     */
    public function rule(object|string $rule): static
    {
        return $this->addRule($rule);
    }
}
