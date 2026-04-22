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

class StringRule implements DataAwareRule, FluentRuleContract, ValidationRule, ValidatorAwareRule
{
    use Conditionable;
    use HasEmbeddedRules;
    use HasFieldModifiers;
    use Macroable;
    use SelfValidates;

    /** @var list<string> */
    protected array $constraints = ['string'];

    public function __construct()
    {
        $this->seedLastConstraint('string');
    }

    public function alpha(bool $ascii = false, ?string $message = null): static
    {
        return $this->addRule($ascii ? 'alpha:ascii' : 'alpha', $message);
    }

    public function alphaDash(bool $ascii = false, ?string $message = null): static
    {
        return $this->addRule($ascii ? 'alpha_dash:ascii' : 'alpha_dash', $message);
    }

    public function alphaNumeric(bool $ascii = false, ?string $message = null): static
    {
        return $this->addRule($ascii ? 'alpha_num:ascii' : 'alpha_num', $message);
    }

    public function ascii(?string $message = null): static
    {
        return $this->addRule('ascii', $message);
    }

    public function encoding(string $encoding, ?string $message = null): static
    {
        return $this->addRule('encoding:' . $encoding, $message);
    }

    public function between(int $min, int $max, ?string $message = null): static
    {
        return $this->addRule('between:' . $min . ',' . $max, $message);
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

    public function exactly(int $value, ?string $message = null): static
    {
        return $this->addRule('size:' . $value, $message);
    }

    public function lowercase(?string $message = null): static
    {
        return $this->addRule('lowercase', $message);
    }

    public function max(int $value, ?string $message = null): static
    {
        return $this->addRule('max:' . $value, $message);
    }

    public function min(int $value, ?string $message = null): static
    {
        return $this->addRule('min:' . $value, $message);
    }

    public function startsWith(string ...$values): static
    {
        return $this->addRule('starts_with:' . implode(',', $values));
    }

    public function uppercase(?string $message = null): static
    {
        return $this->addRule('uppercase', $message);
    }

    public function url(?string $message = null): static
    {
        return $this->addRule('url', $message);
    }

    public function activeUrl(?string $message = null): static
    {
        return $this->addRule('active_url', $message);
    }

    public function uuid(?string $message = null): static
    {
        return $this->addRule('uuid', $message);
    }

    public function ulid(?string $message = null): static
    {
        return $this->addRule('ulid', $message);
    }

    public function json(?string $message = null): static
    {
        return $this->addRule('json', $message);
    }

    public function ip(?string $message = null): static
    {
        return $this->addRule('ip', $message);
    }

    public function ipv4(?string $message = null): static
    {
        return $this->addRule('ipv4', $message);
    }

    public function ipv6(?string $message = null): static
    {
        return $this->addRule('ipv6', $message);
    }

    public function macAddress(?string $message = null): static
    {
        return $this->addRule('mac_address', $message);
    }

    public function regex(string $pattern, ?string $message = null): static
    {
        return $this->addRule('regex:' . $pattern, $message);
    }

    public function notRegex(string $pattern, ?string $message = null): static
    {
        return $this->addRule('not_regex:' . $pattern, $message);
    }

    public function timezone(?string $message = null): static
    {
        return $this->addRule('timezone', $message);
    }

    public function hexColor(?string $message = null): static
    {
        return $this->addRule('hex_color', $message);
    }

    public function date(?string $message = null): static
    {
        return $this->addRule('date', $message);
    }

    public function email(string ...$modes): static
    {
        return $this->addRule($modes === [] ? 'email' : 'email:' . implode(',', $modes));
    }

    public function dateFormat(string $format, ?string $message = null): static
    {
        return $this->addRule('date_format:' . $format, $message);
    }

    public function confirmed(?string $message = null): static
    {
        return $this->addRule('confirmed', $message);
    }

    public function currentPassword(?string $guard = null, ?string $message = null): static
    {
        return $this->addRule($guard ? 'current_password:' . $guard : 'current_password', $message);
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
