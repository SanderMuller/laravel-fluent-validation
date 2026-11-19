<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Rules;

use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use Illuminate\Validation\Rules\Email;
use SanderMuller\FluentValidation\Contracts\FluentRuleContract;
use SanderMuller\FluentValidation\Rules\Concerns\HasEmbeddedRules;
use SanderMuller\FluentValidation\Rules\Concerns\HasFieldModifiers;
use SanderMuller\FluentValidation\Rules\Concerns\SelfValidates;
use Stringable;

class EmailRule implements DataAwareRule, FluentRuleContract, ValidatorAwareRule
{
    use Conditionable;
    use HasEmbeddedRules;
    use HasFieldModifiers;
    use Macroable;
    use SelfValidates;

    /** @var list<string> */
    protected array $constraints = ['string'];

    /** @var list<string> */
    protected array $modes = [];

    public function __construct(protected bool $useDefaults = true)
    {
        $this->seedLastConstraint('email');
    }

    public function rfcCompliant(bool $strict = false): static
    {
        $this->modes[] = $strict ? 'strict' : 'rfc';

        return $this;
    }

    public function strict(): static
    {
        return $this->rfcCompliant(true);
    }

    public function validateMxRecord(): static
    {
        $this->modes[] = 'dns';

        return $this;
    }

    public function preventSpoofing(): static
    {
        $this->modes[] = 'spoof';

        return $this;
    }

    public function withNativeValidation(bool $allowUnicode = false): static
    {
        $this->modes[] = $allowUnicode ? 'filter_unicode' : 'filter';

        return $this;
    }

    // -- String-like constraints that make sense on email fields --

    public function max(int $value, ?string $message = null): static
    {
        return $this->addRule('max:' . $value, $message);
    }

    public function confirmed(?string $message = null): static
    {
        return $this->addRule('confirmed', $message);
    }

    public function same(string $field, ?string $message = null): static
    {
        return $this->addRule('same:' . $field, $message);
    }

    public function different(string $field, ?string $message = null): static
    {
        return $this->addRule('different:' . $field, $message);
    }

    public function inArray(string $field, ?string $message = null): static
    {
        return $this->addRule('in_array:' . $field, $message);
    }

    public function distinct(?string $mode = null, ?string $message = null): static
    {
        return $this->addRule($mode ? 'distinct:' . $mode : 'distinct', $message);
    }

    /** @return string|list<string|object> */
    public function compiledRules(): string|array
    {
        $allRules = $this->buildValidationRules();

        foreach ($allRules as $allRule) {
            if (is_object($allRule) && ! $allRule instanceof Stringable) {
                return $allRules;
            }
        }

        /** @var list<string|Stringable> $allRules */
        return implode('|', array_map(static fn (Stringable|string $r): string => (string) $r, $allRules));
    }

    /** @return list<string|object> */
    protected function buildValidationRules(): array
    {
        $constraints = $this->reorderConstraints($this->constraints);

        // The format check goes right after `string` and before `max` and the
        // other constraints, as in `required|string|email|max:255`, so the
        // errors come in the same order as Laravel's own rules.
        $position = array_search('string', $constraints, true);
        $position = $position === false ? 0 : $position + 1;

        return [
            ...array_slice($constraints, 0, $position),
            $this->emailRule(),
            ...array_slice($constraints, $position),
            ...$this->rules,
        ];
    }

    private function emailRule(): string|Email
    {
        // Explicit modes always take precedence.
        if ($this->modes !== []) {
            return 'email:' . implode(',', $this->modes);
        }

        // Use Email::default() when defaults are enabled and the app has configured them.
        if ($this->useDefaults && Email::$defaultCallback !== null) {
            return Email::default();
        }

        return 'email';
    }
}
