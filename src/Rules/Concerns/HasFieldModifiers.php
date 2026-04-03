<?php

declare(strict_types=1);

namespace SanderMuller\FluentValidation\Rules\Concerns;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;
use Illuminate\Support\Fluent;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\ExcludeIf;
use Illuminate\Validation\Rules\ExcludeUnless;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\NotIn;
use Illuminate\Validation\Rules\ProhibitedIf;
use Illuminate\Validation\Rules\ProhibitedUnless;
use Illuminate\Validation\Rules\RequiredIf;
use Illuminate\Validation\Rules\RequiredUnless;
use Illuminate\Validation\Rules\Unique;

trait HasFieldModifiers
{
    /** @var list<object> */
    protected array $rules = [];

    protected ?string $label = null;

    private ?string $lastConstraint = null;

    /** @var array<string, string> */
    private array $customMessages = [];

    /**
     * Set the human-readable label for this field.
     * Used as the :attribute replacement in error messages.
     */
    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    /**
     * Set a custom error message for the most recently added rule.
     *
     *     FluentRule::string()->required()->message('We need your name.')
     *     FluentRule::string()->min(2)->message('Too short!')
     */
    public function message(string $message): static
    {
        if ($this->lastConstraint === null) {
            throw new \LogicException("message() must be called after a rule method, e.g. ->required()->message('...')");
        }

        $this->customMessages[$this->lastConstraint] = $message;

        return $this;
    }

    /**
     * Set a fallback error message for this field, used when no
     * rule-specific message matches. Equivalent to 'field' => 'message'
     * in Laravel's messages array (without a .rule suffix).
     */
    public function fieldMessage(string $message): static
    {
        $this->customMessages[''] = $message;

        return $this;
    }

    /** @return array<string, string> */
    public function getCustomMessages(): array
    {
        return $this->customMessages;
    }

    /**
     * Strings are appended to $constraints. Objects are appended to $rules.
     *
     * @param  array<int, string>|string|object  $rules
     */
    protected function addRule(array|string|object $rules): static
    {
        if (is_object($rules)) {
            $this->rules[] = $rules;
            $this->lastConstraint = match (true) {
                $rules instanceof RequiredIf => 'required',
                $rules instanceof RequiredUnless => 'required',
                $rules instanceof ProhibitedIf => 'prohibited',
                $rules instanceof ProhibitedUnless => 'prohibited',
                $rules instanceof ExcludeIf => 'exclude',
                $rules instanceof ExcludeUnless => 'exclude',
                $rules instanceof In => 'in',
                $rules instanceof NotIn => 'not_in',
                $rules instanceof Unique => 'unique',
                $rules instanceof Exists => 'exists',
                default => lcfirst(class_basename($rules)),
            };
        } else {
            $this->constraints = array_merge($this->constraints, Arr::wrap($rules));
            // Track last constraint name: 'min:2' → 'min', 'required' → 'required'
            $last = is_array($rules) ? end($rules) : $rules;
            $this->lastConstraint = is_string($last) ? explode(':', $last, 2)[0] : null;
        }

        return $this;
    }

    /** @param  array<int|string, string|int|bool>  $values */
    private static function serializeValues(array $values): string
    {
        return implode(',', array_map(
            static fn (string|int|bool $v): string|int => is_bool($v) ? ($v ? '1' : '0') : $v,
            $values,
        ));
    }

    public function bail(): static
    {
        return $this->addRule('bail');
    }

    public function nullable(): static
    {
        return $this->addRule('nullable');
    }

    public function required(): static
    {
        return $this->addRule('required');
    }

    public function sometimes(): static
    {
        return $this->addRule('sometimes');
    }

    public function filled(): static
    {
        return $this->addRule('filled');
    }

    public function present(): static
    {
        return $this->addRule('present');
    }

    public function prohibited(): static
    {
        return $this->addRule('prohibited');
    }

    public function exclude(): static
    {
        return $this->addRule('exclude');
    }

    public function missing(): static
    {
        return $this->addRule('missing');
    }

    public function missingIf(string $field, string|int|bool ...$values): static
    {
        return $this->addRule('missing_if:' . $field . ',' . self::serializeValues($values));
    }

    public function missingUnless(string $field, string|int|bool ...$values): static
    {
        return $this->addRule('missing_unless:' . $field . ',' . self::serializeValues($values));
    }

    public function missingWith(string ...$fields): static
    {
        return $this->addRule('missing_with:' . implode(',', $fields));
    }

    public function missingWithAll(string ...$fields): static
    {
        return $this->addRule('missing_with_all:' . implode(',', $fields));
    }

    public function requiredIf(Closure|bool|string $field, string|int|bool ...$values): static
    {
        if ($field instanceof Closure || is_bool($field)) {
            return $this->addRule(new RequiredIf($field));
        }

        return $this->addRule('required_if:' . $field . ',' . self::serializeValues($values));
    }

    public function requiredUnless(Closure|bool|string $field, string|int|bool ...$values): static
    {
        if ($field instanceof Closure || is_bool($field)) {
            // RequiredUnless class only exists in Laravel 12+. Invert to RequiredIf.
            $inverted = $field instanceof Closure ? fn (): bool => ! $field() : ! $field;

            return $this->addRule(new RequiredIf($inverted));
        }

        return $this->addRule('required_unless:' . $field . ',' . self::serializeValues($values));
    }

    public function requiredWith(string ...$fields): static
    {
        return $this->addRule('required_with:' . implode(',', $fields));
    }

    public function requiredWithAll(string ...$fields): static
    {
        return $this->addRule('required_with_all:' . implode(',', $fields));
    }

    public function requiredWithout(string ...$fields): static
    {
        return $this->addRule('required_without:' . implode(',', $fields));
    }

    public function requiredWithoutAll(string ...$fields): static
    {
        return $this->addRule('required_without_all:' . implode(',', $fields));
    }

    public function excludeIf(Closure|bool|string $field, string|int|bool ...$values): static
    {
        if ($field instanceof Closure || is_bool($field)) {
            if (class_exists(ExcludeIf::class)) {
                return $this->addRule(new ExcludeIf($field));
            }

            // Laravel 11: evaluate eagerly
            $shouldExclude = $field instanceof Closure ? $field() : $field;

            return $shouldExclude ? $this->addRule('exclude') : $this;
        }

        return $this->addRule('exclude_if:' . $field . ',' . self::serializeValues($values));
    }

    public function excludeUnless(Closure|bool|string $field, string|int|bool ...$values): static
    {
        if ($field instanceof Closure || is_bool($field)) {
            if (class_exists(ExcludeUnless::class)) {
                return $this->addRule(new ExcludeUnless($field));
            }

            // Laravel 11: evaluate eagerly (inverted excludeIf)
            $shouldExclude = $field instanceof Closure ? ! $field() : ! $field;

            return $shouldExclude ? $this->addRule('exclude') : $this;
        }

        return $this->addRule('exclude_unless:' . $field . ',' . self::serializeValues($values));
    }

    public function excludeWith(string $field): static
    {
        return $this->addRule('exclude_with:' . $field);
    }

    public function excludeWithout(string $field): static
    {
        return $this->addRule('exclude_without:' . $field);
    }

    public function prohibitedIf(Closure|bool|string $field, string|int|bool ...$values): static
    {
        if ($field instanceof Closure || is_bool($field)) {
            return $this->addRule(new ProhibitedIf($field));
        }

        return $this->addRule('prohibited_if:' . $field . ',' . self::serializeValues($values));
    }

    public function prohibitedUnless(Closure|bool|string $field, string|int|bool ...$values): static
    {
        if ($field instanceof Closure || is_bool($field)) {
            // ProhibitedUnless only exists in Laravel 12+. Invert to ProhibitedIf.
            $inverted = $field instanceof Closure ? fn (): bool => ! $field() : ! $field;

            return $this->addRule(new ProhibitedIf($inverted));
        }

        return $this->addRule('prohibited_unless:' . $field . ',' . self::serializeValues($values));
    }

    public function prohibits(string ...$fields): static
    {
        return $this->addRule('prohibits:' . implode(',', $fields));
    }

    /**
     * Add any Laravel validation rule — string, object, or array tuple.
     *
     * Array tuples like ['mimetypes', 'image/jpeg', 'application/pdf'] are
     * converted to string format ('mimetypes:image/jpeg,application/pdf').
     * This is useful when rule parameters are dynamic.
     *
     * @param  ValidationRule|\Stringable|Closure(string, mixed, Closure): void|string|array<int, string>  $rule
     */
    public function rule(object|string|array $rule): static
    {
        if (is_array($rule)) {
            $params = array_slice($rule, 1);

            return $this->addRule($params === [] ? $rule[0] : $rule[0] . ':' . implode(',', $params));
        }

        return $this->addRule($rule);
    }

    /**
     * Conditionally apply rules based on the input data at validation time.
     *
     * Unlike when() (which evaluates at build time), this defers the condition
     * to validation time — the closure receives the full input as a Fluent object.
     *
     *     FluentRule::string()->whenInput(
     *         fn ($input) => $input->role === 'admin',
     *         fn ($r) => $r->required()->min(12),
     *         fn ($r) => $r->sometimes()->max(100),
     *     )
     *
     * @param  Closure(Fluent): bool  $condition
     * @param  Closure(static): static|string|array<int, string>  $rules
     * @param  Closure(static): static|string|array<int, string>  $defaultRules
     */
    public function whenInput(Closure $condition, Closure|string|array $rules, Closure|string|array $defaultRules = []): static
    {
        return $this->addRule(Rule::when(
            $condition,
            $this->compileConditionalBranch($rules),
            $this->compileConditionalBranch($defaultRules),
        ));
    }

    /** @return string|list<string|object> */
    private function compileConditionalBranch(Closure|string|array $rules): string|array
    {
        if (! $rules instanceof Closure) {
            return $rules;
        }

        $branch = clone $this;
        $branch->constraints = [];
        $branch->rules = [];
        $branch->customMessages = [];
        $branch->lastConstraint = null;
        $branch->compiledCache = null;
        $rules($branch);

        return $branch->compiledRules();
    }
}
