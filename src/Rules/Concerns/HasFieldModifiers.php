<?php

declare(strict_types=1);

namespace SanderMuller\FluentValidation\Rules\Concerns;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\NotIn;
use Illuminate\Validation\Rules\ProhibitedIf;
use Illuminate\Validation\Rules\ProhibitedUnless;
use Illuminate\Validation\Rules\RequiredIf;
use Illuminate\Validation\Rules\RequiredUnless;
use Illuminate\Validation\Rules\Unique;

trait HasFieldModifiers
{
    /** @var list<object> */
    protected array $rules = [];

    protected ?string $label = null;

    private ?string $lastConstraint = null;

    /** @var array<string, string> */
    private array $customMessages = [];

    /**
     * Set the human-readable label for this field.
     * Used as the :attribute replacement in error messages.
     */
    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    /**
     * Set a custom error message for the most recently added rule.
     *
     *     FluentRule::string()->required()->message('We need your name.')
     *     FluentRule::string()->min(2)->message('Too short!')
     */
    public function message(string $message): static
    {
        if ($this->lastConstraint === null) {
            throw new \LogicException("message() must be called after a rule method, e.g. ->required()->message('...')");
        }

        $this->customMessages[$this->lastConstraint] = $message;

        return $this;
    }

    /** @return array<string, string> */
    public function getCustomMessages(): array
    {
        return $this->customMessages;
    }

    /**
     * Strings are appended to $constraints. Objects are appended to $rules.
     *
     * @param  array<int, string>|string|object  $rules
     */
    protected function addRule(array|string|object $rules): static
    {
        if (is_object($rules)) {
            $this->rules[] = $rules;
            $this->lastConstraint = match (true) {
                $rules instanceof RequiredIf => 'required',
                $rules instanceof RequiredUnless => 'required',
                $rules instanceof ProhibitedIf => 'prohibited',
                $rules instanceof ProhibitedUnless => 'prohibited',
                $rules instanceof In => 'in',
                $rules instanceof NotIn => 'not_in',
                $rules instanceof Unique => 'unique',
                $rules instanceof Exists => 'exists',
                default => null,
            };
        } else {
            $this->constraints = array_merge($this->constraints, Arr::wrap($rules));
            // Track last constraint name: 'min:2' → 'min', 'required' → 'required'
            $last = is_array($rules) ? end($rules) : $rules;
            $this->lastConstraint = is_string($last) ? explode(':', $last, 2)[0] : null;
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
