<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Rules;

use DateTimeInterface;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use SanderMuller\FluentValidation\Contracts\FluentRuleContract;
use SanderMuller\FluentValidation\Rules\Concerns\HasEmbeddedRules;
use SanderMuller\FluentValidation\Rules\Concerns\HasFieldModifiers;
use SanderMuller\FluentValidation\Rules\Concerns\SelfValidates;

class DateRule implements DataAwareRule, FluentRuleContract, ValidatorAwareRule
{
    use Conditionable;
    use HasEmbeddedRules;
    use HasFieldModifiers;
    use Macroable;
    use SelfValidates;

    protected ?string $format = null;

    /** @var list<string> */
    protected array $constraints = [];

    public function format(string $format): static
    {
        $this->format = $format;

        return $this;
    }

    public function beforeToday(?string $message = null): static
    {
        return $this->before('today', $message);
    }

    public function afterToday(?string $message = null): static
    {
        return $this->after('today', $message);
    }

    public function todayOrBefore(?string $message = null): static
    {
        return $this->beforeOrEqual('today', $message);
    }

    public function todayOrAfter(?string $message = null): static
    {
        return $this->afterOrEqual('today', $message);
    }

    public function past(?string $message = null): static
    {
        return $this->before('now', $message);
    }

    public function future(?string $message = null): static
    {
        return $this->after('now', $message);
    }

    public function nowOrPast(?string $message = null): static
    {
        return $this->beforeOrEqual('now', $message);
    }

    public function nowOrFuture(?string $message = null): static
    {
        return $this->afterOrEqual('now', $message);
    }

    public function before(DateTimeInterface|string $date, ?string $message = null): static
    {
        return $this->addRule('before:' . $this->formatDate($date), $message);
    }

    public function after(DateTimeInterface|string $date, ?string $message = null): static
    {
        return $this->addRule('after:' . $this->formatDate($date), $message);
    }

    public function beforeOrEqual(DateTimeInterface|string $date, ?string $message = null): static
    {
        return $this->addRule('before_or_equal:' . $this->formatDate($date), $message);
    }

    public function afterOrEqual(DateTimeInterface|string $date, ?string $message = null): static
    {
        return $this->addRule('after_or_equal:' . $this->formatDate($date), $message);
    }

    /**
     * Composite method — adds `after:$from` then `before:$to`.
     * `message:` binds to the `before` sub-rule (semantic last). Target the
     * `after` sub-rule via `messageFor('after', '...')`.
     */
    public function between(DateTimeInterface|string $from, DateTimeInterface|string $to, ?string $message = null): static
    {
        return $this->after($from)->before($to, $message);
    }

    /**
     * Composite method — adds `after_or_equal:$from` then `before_or_equal:$to`.
     * `message:` binds to the `before_or_equal` sub-rule.
     */
    public function betweenOrEqual(DateTimeInterface|string $from, DateTimeInterface|string $to, ?string $message = null): static
    {
        return $this->afterOrEqual($from)->beforeOrEqual($to, $message);
    }

    public function dateEquals(DateTimeInterface|string $date, ?string $message = null): static
    {
        return $this->addRule('date_equals:' . $this->formatDate($date), $message);
    }

    public function same(string $field, ?string $message = null): static
    {
        return $this->addRule('same:' . $field, $message);
    }

    public function different(string $field, ?string $message = null): static
    {
        return $this->addRule('different:' . $field, $message);
    }

    protected function formatDate(DateTimeInterface|string $date): string
    {
        return $date instanceof DateTimeInterface
            ? $date->format($this->format ?? 'Y-m-d')
            : $date;
    }

    /** @return list<string|object> */
    protected function buildValidationRules(): array
    {
        return [
            ...$this->reorderConstraints([$this->format === null ? 'date' : 'date_format:' . $this->format, ...$this->constraints]),
            ...$this->rules,
        ];
    }
}
