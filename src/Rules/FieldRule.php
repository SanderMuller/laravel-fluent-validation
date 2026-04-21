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
class FieldRule implements DataAwareRule, FluentRuleContract, ValidationRule, ValidatorAwareRule
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

    public function same(string $field): static
    {
        return $this->addRule('same:' . $field);
    }

    public function different(string $field): static
    {
        return $this->addRule('different:' . $field);
    }

    public function confirmed(): static
    {
        return $this->addRule('confirmed');
    }

    /** @return array<string, mixed> */
    public function buildNestedRules(string $attribute): array
    {
        $rules = [];

        foreach ($this->childRules ?? [] as $field => $rule) {
            $key = $attribute . '.' . $field;
            $rules[$key] = $rule;

            if ($rule instanceof ArrayRule && $rule->getEachRules() !== null) {
                $rules = array_merge($rules, $rule->buildNestedRules($key));
            }
        }

        return $rules;
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
