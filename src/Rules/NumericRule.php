<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Rules;

use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use SanderMuller\FluentValidation\Contracts\FluentRuleContract;
use SanderMuller\FluentValidation\Rules\Concerns\HasEmbeddedRules;
use SanderMuller\FluentValidation\Rules\Concerns\HasFieldModifiers;
use SanderMuller\FluentValidation\Rules\Concerns\SelfValidates;

class NumericRule implements DataAwareRule, FluentRuleContract, ValidationRule, ValidatorAwareRule
{
    use Conditionable;
    use HasEmbeddedRules;
    use HasFieldModifiers;
    use Macroable;
    use SelfValidates;

    /** @var list<string> */
    protected array $constraints = ['numeric'];

    public function __construct()
    {
        $this->seedLastConstraint('numeric');
    }

    public function between(int|float $min, int|float $max, ?string $message = null): static
    {
        return $this->addRule('between:' . $min . ',' . $max, $message);
    }

    public function decimal(int $min, ?int $max = null, ?string $message = null): static
    {
        $r = 'decimal:' . $min;
        if ($max !== null) {
            $r .= ',' . $max;
        }

        return $this->addRule($r, $message);
    }

    public function different(string $field, ?string $message = null): static
    {
        return $this->addRule('different:' . $field, $message);
    }

    /**
     * Composite method — adds `integer` then `digits:N`. `message:` binds to
     * `digits` (the semantically meaningful sub-rule). To message `integer`,
     * use `->messageFor('integer', '...')`.
     */
    public function digits(int $length, ?string $message = null): static
    {
        return $this->integer()->addRule('digits:' . $length, $message);
    }

    /**
     * Composite method — adds `integer` then `digits_between:min,max`.
     * `message:` binds to `digits_between`. Target `integer` via `messageFor()`.
     */
    public function digitsBetween(int $min, int $max, ?string $message = null): static
    {
        return $this->integer()->addRule('digits_between:' . $min . ',' . $max, $message);
    }

    public function greaterThan(string $field, ?string $message = null): static
    {
        return $this->addRule('gt:' . $field, $message);
    }

    public function greaterThanOrEqualTo(string $field, ?string $message = null): static
    {
        return $this->addRule('gte:' . $field, $message);
    }

    public function integer(bool $strict = false, ?string $message = null): static
    {
        return $this->addRule($strict ? 'integer:strict' : 'integer', $message);
    }

    public function lessThan(string $field, ?string $message = null): static
    {
        return $this->addRule('lt:' . $field, $message);
    }

    public function lessThanOrEqualTo(string $field, ?string $message = null): static
    {
        return $this->addRule('lte:' . $field, $message);
    }

    public function max(int|float $value, ?string $message = null): static
    {
        return $this->addRule('max:' . $value, $message);
    }

    public function maxDigits(int $value, ?string $message = null): static
    {
        return $this->addRule('max_digits:' . $value, $message);
    }

    public function min(int|float $value, ?string $message = null): static
    {
        return $this->addRule('min:' . $value, $message);
    }

    public function minDigits(int $value, ?string $message = null): static
    {
        return $this->addRule('min_digits:' . $value, $message);
    }

    public function multipleOf(int|float $value, ?string $message = null): static
    {
        return $this->addRule('multiple_of:' . $value, $message);
    }

    public function positive(?string $message = null): static
    {
        return $this->addRule('gt:0', $message);
    }

    public function negative(?string $message = null): static
    {
        return $this->addRule('lt:0', $message);
    }

    public function nonNegative(?string $message = null): static
    {
        return $this->addRule('gte:0', $message);
    }

    public function nonPositive(?string $message = null): static
    {
        return $this->addRule('lte:0', $message);
    }

    public function same(string $field, ?string $message = null): static
    {
        return $this->addRule('same:' . $field, $message);
    }

    /**
     * Composite method — adds `integer` then `size:N`. `message:` binds to
     * `size`. Target `integer` via `messageFor()`.
     */
    public function exactly(int $value, ?string $message = null): static
    {
        return $this->integer()->addRule('size:' . $value, $message);
    }

    public function confirmed(?string $message = null): static
    {
        return $this->addRule('confirmed', $message);
    }

    public function inArray(string $field, ?string $message = null): static
    {
        return $this->addRule('in_array:' . $field, $message);
    }

    public function inArrayKeys(string $field, ?string $message = null): static
    {
        return $this->addRule('in_array_keys:' . $field, $message);
    }

    public function distinct(?string $mode = null, ?string $message = null): static
    {
        return $this->addRule($mode ? 'distinct:' . $mode : 'distinct', $message);
    }

    /** @return list<string|object> */
    protected function buildValidationRules(): array
    {
        return [...$this->reorderConstraints($this->constraints), ...$this->rules];
    }
}
