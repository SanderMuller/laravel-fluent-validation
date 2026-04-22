<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Rules;

use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use SanderMuller\FluentValidation\Contracts\FluentRuleContract;
use SanderMuller\FluentValidation\Rules\Concerns\HasFieldModifiers;
use SanderMuller\FluentValidation\Rules\Concerns\SelfValidates;

class DeclinedRule implements DataAwareRule, FluentRuleContract, ValidationRule, ValidatorAwareRule
{
    use Conditionable;
    use HasFieldModifiers;
    use Macroable;
    use SelfValidates;

    /** @var list<string> */
    protected array $constraints = ['declined'];

    public function __construct()
    {
        $this->seedLastConstraint('declined');
    }

    public function declinedIf(string $field, string|int|bool ...$values): static
    {
        // Variadic-trailing signature — `message:` unavailable here.
        $this->constraints = array_values(array_filter(
            $this->constraints,
            static fn (string $rule): bool => $rule !== 'declined',
        ));

        return $this->addRule('declined_if:' . $field . ',' . self::serializeValues($values));
    }

    /** @return list<string|object> */
    protected function buildValidationRules(): array
    {
        return [...$this->reorderConstraints($this->constraints), ...$this->rules];
    }
}
