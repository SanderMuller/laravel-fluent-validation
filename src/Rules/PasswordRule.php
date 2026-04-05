<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Rules;

use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use Illuminate\Validation\Rules\Password;
use SanderMuller\FluentValidation\Rules\Concerns\HasFieldModifiers;
use SanderMuller\FluentValidation\Rules\Concerns\SelfValidates;

class PasswordRule implements DataAwareRule, ValidationRule, ValidatorAwareRule
{
    use Conditionable;
    use HasFieldModifiers;
    use Macroable;
    use SelfValidates;

    /** @var list<string> */
    protected array $constraints = ['string'];

    protected Password $password;

    public function __construct(?int $min = null)
    {
        $this->password = $min !== null ? Password::min($min) : Password::default();
    }

    public function max(int $size): static
    {
        $this->password->max($size);

        return $this;
    }

    public function letters(): static
    {
        $this->password->letters();

        return $this;
    }

    public function mixedCase(): static
    {
        $this->password->mixedCase();

        return $this;
    }

    public function numbers(): static
    {
        $this->password->numbers();

        return $this;
    }

    public function symbols(): static
    {
        $this->password->symbols();

        return $this;
    }

    public function uncompromised(int $threshold = 0): static
    {
        $this->password->uncompromised($threshold);

        return $this;
    }

    public function canCompile(): bool
    {
        return false;
    }

    /** @return list<string|object> */
    public function compiledRules(): string|array
    {
        return $this->buildValidationRules();
    }

    /** @return list<string|object> */
    protected function buildValidationRules(): array
    {
        return [...$this->constraints, $this->password, ...$this->rules];
    }
}
