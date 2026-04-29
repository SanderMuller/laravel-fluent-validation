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

    public function __construct(?string $factoryMessage = null)
    {
        // Seed lastConstraint to 'date' ONLY when a factory `message:` arg was
        // supplied. This matches StringRule/NumericRule semantics for the
        // factory-message path (chained ->message() can re-target the same key)
        // while preserving the fail-fast guardrail for plain construction —
        // FluentRule::date()->message('…')->before(…) still throws because no
        // factory message means no seed.
        if ($factoryMessage !== null) {
            $this->customMessages['date'] = $factoryMessage;
            $this->seedLastConstraint('date');
        }
    }

    public function format(string $format): static
    {
        $this->format = $format;

        // Migrate any pinned message from 'date' to 'date_format' since the
        // type-check rule emitted at compile time changes from `date` to
        // `date_format:<format>`. customMessages keys use the bare rule name
        // (no `:<param>` suffix) — matching addRule()'s `explode(':', …)` logic.
        // An existing 'date_format' message wins (explicit messageFor() represents
        // user intent); the orphan 'date' message is dropped since `date` won't fire.
        // array_key_exists handles intentional empty-string messages, which isset would skip.
        if (array_key_exists('date', $this->customMessages)) {
            if (! array_key_exists('date_format', $this->customMessages)) {
                $this->customMessages['date_format'] = $this->customMessages['date'];
            }

            unset($this->customMessages['date']);
        }

        if ($this->lastConstraint === 'date') {
            $this->lastConstraint = 'date_format';
        }

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
