<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Rules;

use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use Illuminate\Validation\Rules\Email;
use SanderMuller\FluentValidation\Contracts\FluentRuleContract;
use SanderMuller\FluentValidation\Rules\Concerns\HasEmbeddedRules;
use SanderMuller\FluentValidation\Rules\Concerns\HasFieldModifiers;
use SanderMuller\FluentValidation\Rules\Concerns\SelfValidates;

class EmailRule implements DataAwareRule, FluentRuleContract, ValidationRule, ValidatorAwareRule
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

    /** @return string|list<string|object> */
    public function compiledRules(): string|array
    {
        $allRules = $this->buildValidationRules();

        foreach ($allRules as $allRule) {
            if (is_object($allRule) && ! $allRule instanceof \Stringable) {
                return $allRules;
            }
        }

        /** @var list<string|\Stringable> $allRules */
        return implode('|', array_map(static fn (\Stringable|string $r): string => (string) $r, $allRules));
    }

    /** @return list<string|object> */
    protected function buildValidationRules(): array
    {
        // Explicit modes always take precedence.
        if ($this->modes !== []) {
            return [...$this->reorderConstraints($this->constraints), 'email:' . implode(',', $this->modes), ...$this->rules];
        }

        // Use Email::default() when defaults are enabled and the app has configured them.
        if ($this->useDefaults && Email::$defaultCallback !== null) {
            return [...$this->reorderConstraints($this->constraints), Email::default(), ...$this->rules];
        }

        return [...$this->reorderConstraints($this->constraints), 'email', ...$this->rules];
    }
}
