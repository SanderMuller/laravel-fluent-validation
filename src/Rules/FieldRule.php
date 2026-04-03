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

/**
 * An untyped rule builder — adds no base type constraint.
 *
 * Use for fields that need modifiers (present, required, etc.)
 * without a specific type like string, numeric, or boolean.
 *
 *     FluentRule::field()->present()
 *     FluentRule::field()->requiredIf('type', 'special')
 */
class FieldRule implements DataAwareRule, ValidationRule, ValidatorAwareRule
{
    use Conditionable;
    use HasEmbeddedRules;
    use HasFieldModifiers;
    use Macroable;
    use SelfValidates;

    /** @var list<string> */
    protected array $constraints = [];

    /** @return list<string|object> */
    protected function buildValidationRules(): array
    {
        return [...$this->constraints, ...$this->rules];
    }
}
