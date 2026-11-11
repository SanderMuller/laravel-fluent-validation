<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Rules;

use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use SanderMuller\FluentValidation\Contracts\FluentRuleContract;
use SanderMuller\FluentValidation\Internal\Boolean;
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

    private bool $allowWords = false;

    public function __construct()
    {
        $this->seedLastConstraint('boolean');
    }

    /**
     * Also accept the words Laravel's `accepted` and `declined` rules accept:
     * 'true', 'false', 'yes', 'no', 'on' and 'off' (lowercase only). Use it
     * for query strings and form posts that send booleans as words. Read the
     * value back with `$request->safe()->boolean('field')`.
     */
    public function allowWords(): static
    {
        $this->compiledCache = null;
        $this->allowWords = true;

        return $this;
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

    public function canCompile(): bool
    {
        return ! $this->allowWords && $this->rules === [];
    }

    /**
     * With allowWords(), a message bound to `boolean` is keyed by the rule
     * class. Laravel checks that key before the bare field key, so
     * `->messageFor('boolean', ...)` still wins over `->fieldMessage(...)`.
     *
     * @return array<string, string>
     */
    public function getCustomMessages(): array
    {
        if (! $this->allowWords || ! isset($this->customMessages['boolean'])) {
            return $this->customMessages;
        }

        $messages = $this->customMessages;
        $messages[Boolean::class] = $messages['boolean'];
        unset($messages['boolean']);

        return $messages;
    }

    /** @return list<string|object> */
    protected function buildValidationRules(): array
    {
        $rules = [...$this->reorderConstraints($this->constraints), ...$this->rules];

        if (! $this->allowWords) {
            return $rules;
        }

        // Keep the boolean check before accepted/declined: Laravel stops after
        // a failed implicit rule, so a later check never reports its error.
        return array_map(
            static fn (string|object $rule): string|object => $rule === 'boolean' ? new Boolean() : $rule,
            $rules,
        );
    }
}
