<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Rules;

use DateTimeInterface;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use SanderMuller\FluentValidation\Contracts\FluentRuleContract;
use SanderMuller\FluentValidation\Rules\Concerns\HasEmbeddedRules;
use SanderMuller\FluentValidation\Rules\Concerns\HasFieldModifiers;
use SanderMuller\FluentValidation\Rules\Concerns\SelfValidates;

class DateRule implements DataAwareRule, FluentRuleContract, ValidationRule, ValidatorAwareRule
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

    public function beforeToday(): static
    {
        return $this->before('today');
    }

    public function afterToday(): static
    {
        return $this->after('today');
    }

    public function todayOrBefore(): static
    {
        return $this->beforeOrEqual('today');
    }

    public function todayOrAfter(): static
    {
        return $this->afterOrEqual('today');
    }

    public function past(): static
    {
        return $this->before('now');
    }

    public function future(): static
    {
        return $this->after('now');
    }

    public function nowOrPast(): static
    {
        return $this->beforeOrEqual('now');
    }

    public function nowOrFuture(): static
    {
        return $this->afterOrEqual('now');
    }

    public function before(DateTimeInterface|string $date): static
    {
        return $this->addRule('before:' . $this->formatDate($date));
    }

    public function after(DateTimeInterface|string $date): static
    {
        return $this->addRule('after:' . $this->formatDate($date));
    }

    public function beforeOrEqual(DateTimeInterface|string $date): static
    {
        return $this->addRule('before_or_equal:' . $this->formatDate($date));
    }

    public function afterOrEqual(DateTimeInterface|string $date): static
    {
        return $this->addRule('after_or_equal:' . $this->formatDate($date));
    }

    public function between(DateTimeInterface|string $from, DateTimeInterface|string $to): static
    {
        return $this->after($from)->before($to);
    }

    public function betweenOrEqual(DateTimeInterface|string $from, DateTimeInterface|string $to): static
    {
        return $this->afterOrEqual($from)->beforeOrEqual($to);
    }

    public function dateEquals(DateTimeInterface|string $date): static
    {
        return $this->addRule('date_equals:' . $this->formatDate($date));
    }

    public function same(string $field): static
    {
        return $this->addRule('same:' . $field);
    }

    public function different(string $field): static
    {
        return $this->addRule('different:' . $field);
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
