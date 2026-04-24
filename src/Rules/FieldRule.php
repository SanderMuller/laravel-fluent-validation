<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use SanderMuller\FluentValidation\Contracts\FluentRuleContract;
use SanderMuller\FluentValidation\Exceptions\UnknownFluentRuleMethod;
use SanderMuller\FluentValidation\Rules\Concerns\HasEmbeddedRules;
use SanderMuller\FluentValidation\Rules\Concerns\HasFieldModifiers;
use SanderMuller\FluentValidation\Rules\Concerns\SelfValidates;

/**
 * An untyped rule builder — adds no base type constraint.
 *
 * Use for fields that need modifiers (present, required, etc.)
 * without a specific type like string, numeric, or boolean.
 * Supports children() for fixed-key child rules.
 *
 *     FluentRule::field()->present()
 *     FluentRule::field()->present()->children([
 *         'email' => FluentRule::email()->required(),
 *     ])
 */
class FieldRule implements DataAwareRule, FluentRuleContract, ValidatorAwareRule
{
    use Conditionable;
    use HasEmbeddedRules;
    use HasFieldModifiers;
    use Macroable;
    use SelfValidates;

    /** @var list<string> */
    protected array $constraints = [];

    /** @var array<string, ValidationRule>|null */
    protected ?array $childRules = null;

    /**
     * Define rules for fixed-key children of this field.
     *
     * Produces fixed paths (answer.email_address) without wildcards.
     *
     * @param  array<string, ValidationRule>  $rules
     */
    public function children(array $rules): static
    {
        $this->childRules = $rules;

        return $this;
    }

    /** @return array<string, ValidationRule>|null */
    public function getChildRules(): ?array
    {
        return $this->childRules;
    }

    /**
     * Add one keyed child rule. Intended for the subclass-extends-parent
     * pattern where the parent defines a children([...]) shape and the
     * child adds a new field.
     *
     * Mutates `childRules` only — base constraints on this FieldRule
     * survive untouched.
     *
     * @throws \LogicException when $key already exists — silent override
     *                         would hide the "parent already defines this"
     *                         mistake. Use mergeChildRules() for
     *                         intentional replacement.
     */
    public function addChildRule(string $key, ValidationRule $rule): static
    {
        if ($key === '') {
            throw new \InvalidArgumentException(
                'addChildRule() requires a non-empty key — empty keys expand to malformed dotted paths (parent.).'
            );
        }

        $existing = $this->childRules ?? [];

        if (array_key_exists($key, $existing)) {
            throw new \LogicException(sprintf(
                "addChildRule('%s'): key '%s' already exists in children(). "
                . 'Use mergeChildRules() if replacement is intentional.',
                $key,
                $key,
            ));
        }

        $existing[$key] = $rule;
        $this->childRules = $existing;

        return $this;
    }

    /**
     * Merge multiple keyed child rules, later-wins on collision.
     *
     * @param  array<string, ValidationRule>  $rules
     */
    public function mergeChildRules(array $rules): static
    {
        if (array_key_exists('', $rules)) {
            throw new \InvalidArgumentException(
                'mergeChildRules() requires non-empty keys — empty keys expand to malformed dotted paths (parent.).'
            );
        }

        $existing = $this->childRules ?? [];
        $this->childRules = array_merge($existing, $rules);

        return $this;
    }

    public function same(string $field, ?string $message = null): static
    {
        return $this->addRule('same:' . $field, $message);
    }

    public function different(string $field, ?string $message = null): static
    {
        return $this->addRule('different:' . $field, $message);
    }

    public function confirmed(?string $message = null): static
    {
        return $this->addRule('confirmed', $message);
    }

    /** @return array<string, mixed> */
    public function buildNestedRules(string $attribute): array
    {
        $rules = [];
        /** @var list<array<string, mixed>> $nested */
        $nested = [];

        foreach ($this->childRules ?? [] as $field => $rule) {
            $key = $attribute . '.' . $field;
            $rules[$key] = $rule;

            if ($rule instanceof ArrayRule
                && ($rule->getEachListRule() instanceof ValidationRule || $rule->getEachKeyedRules() !== null)) {
                $nested[] = $rule->buildNestedRules($key);
            }
        }

        return $nested === [] ? $rules : array_merge($rules, ...$nested);
    }

    /** @return list<string|object> */
    protected function buildValidationRules(): array
    {
        return [...$this->reorderConstraints($this->constraints), ...$this->rules];
    }

    /**
     * Mirrors `Macroable::__call` dispatch exactly except for the
     * "no macro registered" branch, which throws a typed exception
     * pointing at the correct typed builder instead of a bare
     * `BadMethodCallException`. See `UnknownFluentRuleMethod`.
     *
     * @param  array<int, mixed>  $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (! static::hasMacro($method)) {
            throw UnknownFluentRuleMethod::on($method);
        }

        $macro = static::$macros[$method];

        if ($macro instanceof Closure) {
            $macro = $macro->bindTo($this, static::class);
        }

        if (! is_callable($macro)) {
            throw UnknownFluentRuleMethod::on($method);
        }

        return $macro(...$parameters);
    }

    /** @param  array<int, mixed>  $parameters */
    public static function __callStatic(string $method, array $parameters): mixed
    {
        if (! static::hasMacro($method)) {
            throw UnknownFluentRuleMethod::on($method);
        }

        $macro = static::$macros[$method];

        if ($macro instanceof Closure) {
            $macro = $macro->bindTo(null, static::class);
        }

        if (! is_callable($macro)) {
            throw UnknownFluentRuleMethod::on($method);
        }

        return $macro(...$parameters);
    }
}
