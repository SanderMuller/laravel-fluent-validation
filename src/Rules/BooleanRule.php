<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Rules;

use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use SanderMuller\FluentValidation\Contracts\FluentRuleContract;
use SanderMuller\FluentValidation\Rules\Concerns\HasFieldModifiers;
use SanderMuller\FluentValidation\Rules\Concerns\SelfValidates;

class BooleanRule implements DataAwareRule, FluentRuleContract, ValidatorAwareRule
{
    use Conditionable;
    use HasFieldModifiers;
    use Macroable;
    use SelfValidates;

    /** @var list<string> */
    protected array $constraints = ['boolean'];

    public function __construct()
    {
        $this->seedLastConstraint('boolean');
    }

    public function accepted(?string $message = null): static
    {
        return $this->addRule('accepted', $message);
    }

    public function acceptedIf(string $field, string|int|bool ...$values): static
    {
        return $this->addRule('accepted_if:' . $field . ',' . self::serializeValues($values));
    }

    public function declined(?string $message = null): static
    {
        return $this->addRule('declined', $message);
    }

    public function declinedIf(string $field, string|int|bool ...$values): static
    {
        return $this->addRule('declined_if:' . $field . ',' . self::serializeValues($values));
    }

    /** @return list<string|object> */
    protected function buildValidationRules(): array
    {
        return [...$this->reorderConstraints($this->constraints), ...$this->rules];
    }
}
