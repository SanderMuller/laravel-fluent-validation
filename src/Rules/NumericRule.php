<?php

declare(strict_types=1);

namespace SanderMuller\FluentValidation\Rules;

use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use SanderMuller\FluentValidation\Rules\Concerns\HasEmbeddedRules;
use SanderMuller\FluentValidation\Rules\Concerns\HasFieldModifiers;
use SanderMuller\FluentValidation\Rules\Concerns\SelfValidates;

class NumericRule implements DataAwareRule, ValidationRule, ValidatorAwareRule
{
    use Conditionable;
    use HasEmbeddedRules;
    use HasFieldModifiers;
    use Macroable;
    use SelfValidates;

    /** @var list<string> */
    protected array $constraints = ['numeric'];

    public function between(int|float $min, int|float $max): static
    {
        return $this->addRule('between:' . $min . ',' . $max);
    }

    public function decimal(int $min, ?int $max = null): static
    {
        $r = 'decimal:' . $min;
        if ($max !== null) {
            $r .= ',' . $max;
        }

        return $this->addRule($r);
    }

    public function different(string $field): static
    {
        return $this->addRule('different:' . $field);
    }

    public function digits(int $length): static
    {
        return $this->integer()->addRule('digits:' . $length);
    }

    public function digitsBetween(int $min, int $max): static
    {
        return $this->integer()->addRule('digits_between:' . $min . ',' . $max);
    }

    public function greaterThan(string $field): static
    {
        return $this->addRule('gt:' . $field);
    }

    public function greaterThanOrEqualTo(string $field): static
    {
        return $this->addRule('gte:' . $field);
    }

    public function integer(bool $strict = false): static
    {
        return $this->addRule($strict ? 'integer:strict' : 'integer');
    }

    public function lessThan(string $field): static
    {
        return $this->addRule('lt:' . $field);
    }

    public function lessThanOrEqualTo(string $field): static
    {
        return $this->addRule('lte:' . $field);
    }

    public function max(int|float $value): static
    {
        return $this->addRule('max:' . $value);
    }

    public function maxDigits(int $value): static
    {
        return $this->addRule('max_digits:' . $value);
    }

    public function min(int|float $value): static
    {
        return $this->addRule('min:' . $value);
    }

    public function minDigits(int $value): static
    {
        return $this->addRule('min_digits:' . $value);
    }

    public function multipleOf(int|float $value): static
    {
        return $this->addRule('multiple_of:' . $value);
    }

    public function same(string $field): static
    {
        return $this->addRule('same:' . $field);
    }

    public function exactly(int $value): static
    {
        return $this->integer()->addRule('size:' . $value);
    }

    public function confirmed(): static
    {
        return $this->addRule('confirmed');
    }

    public function inArray(string $field): static
    {
        return $this->addRule('in_array:' . $field);
    }

    public function inArrayKeys(string $field): static
    {
        return $this->addRule('in_array_keys:' . $field);
    }

    public function distinct(?string $mode = null): static
    {
        return $this->addRule($mode ? 'distinct:' . $mode : 'distinct');
    }

    /** @return list<string|object> */
    protected function buildValidationRules(): array
    {
        return [...$this->constraints, ...$this->rules];
    }
}
