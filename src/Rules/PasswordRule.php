<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Rules;

use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use Illuminate\Validation\Rules\Password;
use SanderMuller\FluentValidation\Contracts\FluentRuleContract;
use SanderMuller\FluentValidation\Rules\Concerns\HasFieldModifiers;
use SanderMuller\FluentValidation\Rules\Concerns\SelfValidates;

class PasswordRule implements DataAwareRule, FluentRuleContract, ValidatorAwareRule
{
    use Conditionable;
    use HasFieldModifiers;
    use Macroable;
    use SelfValidates;

    /** @var list<string> */
    protected array $constraints = ['string'];

    protected Password $password;

    public function __construct(?int $min = null, bool $defaults = true)
    {
        // No seedLastConstraint('password') — Laravel 11 (pre-v11.1) emits
        // Password failures under sub-keys (`password.mixed`, `password.letters`,
        // `password.numbers`, etc.) rather than the bare `password` key, so
        // binding `customMessages['password']` wouldn't surface on that matrix.
        // Users target specific Password strength failures via messageFor:
        //   ->messageFor('password.mixed', 'Must mix upper and lower case.')
        //   ->messageFor('password.letters', 'Must include letters.')
        $this->password = $min !== null
            ? Password::min($min)
            : ($defaults ? Password::default() : Password::min(8));
    }

    public function min(int $size): static
    {
        (new \ReflectionProperty($this->password, 'min'))->setValue($this->password, $size);

        return $this;
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

    public function confirmed(?string $message = null): static
    {
        return $this->addRule('confirmed', $message);
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
        return [...$this->reorderConstraints($this->constraints), $this->password, ...$this->rules];
    }
}
