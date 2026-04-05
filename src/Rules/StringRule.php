<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Rules;

use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use SanderMuller\FluentValidation\Rules\Concerns\HasEmbeddedRules;
use SanderMuller\FluentValidation\Rules\Concerns\HasFieldModifiers;
use SanderMuller\FluentValidation\Rules\Concerns\SelfValidates;

class StringRule implements DataAwareRule, ValidationRule, ValidatorAwareRule
{
    use Conditionable;
    use HasEmbeddedRules;
    use HasFieldModifiers;
    use Macroable;
    use SelfValidates;

    /** @var list<string> */
    protected array $constraints = ['string'];

    public function alpha(bool $ascii = false): static
    {
        return $this->addRule($ascii ? 'alpha:ascii' : 'alpha');
    }

    public function alphaDash(bool $ascii = false): static
    {
        return $this->addRule($ascii ? 'alpha_dash:ascii' : 'alpha_dash');
    }

    public function alphaNumeric(bool $ascii = false): static
    {
        return $this->addRule($ascii ? 'alpha_num:ascii' : 'alpha_num');
    }

    public function ascii(): static
    {
        return $this->addRule('ascii');
    }

    public function between(int $min, int $max): static
    {
        return $this->addRule('between:' . $min . ',' . $max);
    }

    public function doesntEndWith(string ...$values): static
    {
        return $this->addRule('doesnt_end_with:' . implode(',', $values));
    }

    public function doesntStartWith(string ...$values): static
    {
        return $this->addRule('doesnt_start_with:' . implode(',', $values));
    }

    public function endsWith(string ...$values): static
    {
        return $this->addRule('ends_with:' . implode(',', $values));
    }

    public function exactly(int $value): static
    {
        return $this->addRule('size:' . $value);
    }

    public function lowercase(): static
    {
        return $this->addRule('lowercase');
    }

    public function max(int $value): static
    {
        return $this->addRule('max:' . $value);
    }

    public function min(int $value): static
    {
        return $this->addRule('min:' . $value);
    }

    public function startsWith(string ...$values): static
    {
        return $this->addRule('starts_with:' . implode(',', $values));
    }

    public function uppercase(): static
    {
        return $this->addRule('uppercase');
    }

    public function url(): static
    {
        return $this->addRule('url');
    }

    public function activeUrl(): static
    {
        return $this->addRule('active_url');
    }

    public function uuid(): static
    {
        return $this->addRule('uuid');
    }

    public function ulid(): static
    {
        return $this->addRule('ulid');
    }

    public function json(): static
    {
        return $this->addRule('json');
    }

    public function ip(): static
    {
        return $this->addRule('ip');
    }

    public function ipv4(): static
    {
        return $this->addRule('ipv4');
    }

    public function ipv6(): static
    {
        return $this->addRule('ipv6');
    }

    public function macAddress(): static
    {
        return $this->addRule('mac_address');
    }

    public function regex(string $pattern): static
    {
        return $this->addRule('regex:' . $pattern);
    }

    public function notRegex(string $pattern): static
    {
        return $this->addRule('not_regex:' . $pattern);
    }

    public function timezone(): static
    {
        return $this->addRule('timezone');
    }

    public function hexColor(): static
    {
        return $this->addRule('hex_color');
    }

    public function date(): static
    {
        return $this->addRule('date');
    }

    public function email(string ...$modes): static
    {
        return $this->addRule($modes === [] ? 'email' : 'email:' . implode(',', $modes));
    }

    public function dateFormat(string $format): static
    {
        return $this->addRule('date_format:' . $format);
    }

    public function confirmed(): static
    {
        return $this->addRule('confirmed');
    }

    public function currentPassword(?string $guard = null): static
    {
        return $this->addRule($guard ? 'current_password:' . $guard : 'current_password');
    }

    public function same(string $field): static
    {
        return $this->addRule('same:' . $field);
    }

    public function different(string $field): static
    {
        return $this->addRule('different:' . $field);
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
        return [...$this->reorderConstraints($this->constraints), ...$this->rules];
    }
}
