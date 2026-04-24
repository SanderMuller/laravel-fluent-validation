<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Rules\Concerns;

use BackedEnum;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Fluent;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Contains;
use Illuminate\Validation\Rules\DoesntContain;
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
use LogicException;

trait HasFieldModifiers
{
    /** @var list<object> */
    protected array $rules = [];

    protected ?string $label = null;

    private ?string $lastConstraint = null;

    /** @var array<string, string> */
    private array $customMessages = [];

    /**
     * Seed $lastConstraint for rule classes whose defining constraint
     * is property-initialised (e.g. `protected array $constraints = ['string']`)
     * rather than added via addRule(). Called from factory constructors so
     * `FluentRule::string()->message('...')` binds to 'string' without the
     * caller knowing the initialisation internals.
     */
    protected function seedLastConstraint(string $name): void
    {
        $this->lastConstraint = $name;
    }

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
            throw new LogicException("message() must be called after a rule method, e.g. ->required()->message('...')");
        }

        $this->customMessages[$this->lastConstraint] = $message;

        return $this;
    }

    /**
     * Set a custom error message for a specific rule by name.
     * Unlike message() which attaches to the preceding rule,
     * this can be called at any point in the chain.
     *
     *     ->required()->min(2)->messageFor('required', 'We need your name!')
     */
    public function messageFor(string $rule, string $message): static
    {
        $this->customMessages[$rule] = $message;

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
     * When $message is non-null, writes it to customMessages under the
     * resolved $lastConstraint key. Throws LogicException if $message is
     * set but no rule was added (e.g. an early-return branch in a caller).
     *
     * @param  array<int, string>|string|object  $rules
     */
    protected function addRule(array|string|object $rules, ?string $message = null): static
    {
        $this->compiledCache = null;

        if (is_object($rules)) {
            $this->rules[] = $rules;
            // Compared by string class-name to avoid autoload-fatal on
            // Laravel versions that lack a given rule class (Contains /
            // DoesntContain are L12+; instanceof would trigger autoload
            // on L11 and throw "class not found").
            $rulesClass = $rules::class;

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
                $rulesClass === Contains::class => 'contains',
                $rulesClass === DoesntContain::class => 'doesnt_contain',
                default => lcfirst(class_basename($rules)),
            };
        } else {
            $this->constraints = array_merge($this->constraints, Arr::wrap($rules));
            // Track last constraint name: 'min:2' → 'min', 'required' → 'required'
            $last = is_array($rules) ? end($rules) : $rules;
            $this->lastConstraint = is_string($last) ? explode(':', $last, 2)[0] : null;
        }

        if ($message !== null) {
            if ($this->lastConstraint === null) {
                throw new LogicException('message parameter has no rule to bind to');
            }

            $this->customMessages[$this->lastConstraint] = $message;
        }

        return $this;
    }

    /** @param array<int|string, string|int|bool|BackedEnum> $values */
    private static function serializeValues(array $values): string
    {
        return implode(',', array_map(
            static fn (string|int|bool|BackedEnum $v): string|int => match (true) {
                $v instanceof BackedEnum => $v->value,
                is_bool($v) => $v ? '1' : '0',
                default => $v,
            },
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

    public function required(?string $message = null): static
    {
        return $this->addRule('required', $message);
    }

    public function sometimes(?string $message = null): static
    {
        return $this->addRule('sometimes', $message);
    }

    public function filled(?string $message = null): static
    {
        return $this->addRule('filled', $message);
    }

    public function present(?string $message = null): static
    {
        return $this->addRule('present', $message);
    }

    public function presentIf(string $field, string|int|bool|BackedEnum ...$values): static
    {
        return $this->addRule('present_if:' . $field . ',' . self::serializeValues($values));
    }

    public function presentUnless(string $field, string|int|bool|BackedEnum ...$values): static
    {
        return $this->addRule('present_unless:' . $field . ',' . self::serializeValues($values));
    }

    public function presentWith(string ...$fields): static
    {
        return $this->addRule('present_with:' . implode(',', $fields));
    }

    public function presentWithAll(string ...$fields): static
    {
        return $this->addRule('present_with_all:' . implode(',', $fields));
    }

    public function prohibited(?string $message = null): static
    {
        return $this->addRule('prohibited', $message);
    }

    public function exclude(): static
    {
        return $this->addRule('exclude');
    }

    public function missing(?string $message = null): static
    {
        return $this->addRule('missing', $message);
    }

    public function missingIf(string $field, string|int|bool|BackedEnum ...$values): static
    {
        return $this->addRule('missing_if:' . $field . ',' . self::serializeValues($values));
    }

    public function missingUnless(string $field, string|int|bool|BackedEnum ...$values): static
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

    public function requiredIf(Closure|bool|string $field, string|int|bool|BackedEnum ...$values): static
    {
        if ($field instanceof Closure || is_bool($field)) {
            return $this->addRule(new RequiredIf($field));
        }

        return $this->addRule('required_if:' . $field . ',' . self::serializeValues($values));
    }

    public function requiredUnless(Closure|bool|string $field, string|int|bool|BackedEnum ...$values): static
    {
        if ($field instanceof Closure || is_bool($field)) {
            // RequiredUnless class only exists in Laravel 12+. Invert to RequiredIf.
            $inverted = $field instanceof Closure ? static fn (): bool => ! $field() : ! $field;

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

    public function requiredIfAccepted(string $field, ?string $message = null): static
    {
        return $this->addRule('required_if_accepted:' . $field, $message);
    }

    public function requiredIfDeclined(string $field, ?string $message = null): static
    {
        return $this->addRule('required_if_declined:' . $field, $message);
    }

    public function excludeIf(Closure|bool|string $field, string|int|bool|BackedEnum ...$values): static
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

    public function excludeUnless(Closure|bool|string $field, string|int|bool|BackedEnum ...$values): static
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

    public function prohibitedIf(Closure|bool|string $field, string|int|bool|BackedEnum ...$values): static
    {
        if ($field instanceof Closure || is_bool($field)) {
            return $this->addRule(new ProhibitedIf($field));
        }

        return $this->addRule('prohibited_if:' . $field . ',' . self::serializeValues($values));
    }

    public function prohibitedUnless(Closure|bool|string $field, string|int|bool|BackedEnum ...$values): static
    {
        if ($field instanceof Closure || is_bool($field)) {
            // ProhibitedUnless only exists in Laravel 12+. Invert to ProhibitedIf.
            $inverted = $field instanceof Closure ? static fn (): bool => ! $field() : ! $field;

            return $this->addRule(new ProhibitedIf($inverted));
        }

        return $this->addRule('prohibited_unless:' . $field . ',' . self::serializeValues($values));
    }

    public function prohibits(string ...$fields): static
    {
        return $this->addRule('prohibits:' . implode(',', $fields));
    }

    public function prohibitedIfAccepted(string $field, ?string $message = null): static
    {
        return $this->addRule('prohibited_if_accepted:' . $field, $message);
    }

    public function prohibitedIfDeclined(string $field, ?string $message = null): static
    {
        return $this->addRule('prohibited_if_declined:' . $field, $message);
    }

    /**
     * Reorder constraints so that presence modifiers (required, nullable, bail, etc.)
     * appear before type and size constraints ("required" must come
     * before "must be a string").
     *
     * @param  list<string>  $constraints
     * @return list<string>
     */
    protected function reorderConstraints(array $constraints): array
    {
        $presence = [];
        $rest = [];

        foreach ($constraints as $constraint) {
            if ($this->isPresenceConstraint($constraint)) {
                $presence[] = $constraint;
            } else {
                $rest[] = $constraint;
            }
        }

        return [...$presence, ...$rest];
    }

    private function isPresenceConstraint(string $constraint): bool
    {
        return in_array($constraint, ['required', 'nullable', 'sometimes', 'filled', 'present', 'missing', 'bail', 'exclude', 'prohibited'], true)
            || str_starts_with($constraint, 'required_')
            || str_starts_with($constraint, 'missing_')
            || str_starts_with($constraint, 'exclude_')
            || str_starts_with($constraint, 'prohibited_');
    }

    /**
     * Add any Laravel validation rule — string, object, or array tuple.
     *
     * Array tuples like ['mimetypes', 'image/jpeg', 'application/pdf'] are
     * converted to string format ('mimetypes:image/jpeg,application/pdf').
     * This is useful when rule parameters are dynamic.
     *
     * **Mutates the receiver and returns it.** When chaining off a rule
     * pulled via `RuleSet::get()`, the appended rule persists on the stored
     * instance — there is no defensive copy. Clone first if you need
     * isolation: `(clone $ruleSet->get($field))->rule($extra)`.
     *
     * @param  object|string|array<int, string>  $rule
     */
    public function rule(object|string|array $rule, ?string $message = null): static
    {
        if (is_array($rule)) {
            $params = array_slice($rule, 1);

            return $this->addRule($params === [] ? $rule[0] : $rule[0] . ':' . implode(',', $params), $message);
        }

        return $this->addRule($rule, $message);
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
     * @param  Closure(Fluent<string, mixed>): bool  $condition
     * @param  Closure(static): static|string|list<string>  $rules
     * @param  Closure(static): static|string|list<string>  $defaultRules
     */
    public function whenInput(Closure $condition, Closure|string|array $rules, Closure|string|array $defaultRules = []): static
    {
        return $this->addRule(Rule::when(
            $condition,
            $this->compileConditionalBranch($rules),
            $this->compileConditionalBranch($defaultRules),
        ));
    }

    /**
     * @param  Closure(static): static|string|list<string>  $rules
     * @return string|list<string|object>
     */
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
