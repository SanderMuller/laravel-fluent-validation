<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Rules;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use SanderMuller\FluentValidation\Contracts\FluentRuleContract;
use SanderMuller\FluentValidation\Rules\Concerns\HasFieldModifiers;
use SanderMuller\FluentValidation\Rules\Concerns\SelfValidates;

class ArrayRule implements DataAwareRule, FluentRuleContract, ValidationRule, ValidatorAwareRule
{
    use Conditionable;
    use HasFieldModifiers;
    use Macroable;
    use SelfValidates;

    /** @var list<string|\BackedEnum> */
    protected array $keys;

    /** @var list<string> */
    protected array $constraints = [];

    /** @var ValidationRule|array<string, ValidationRule>|null */
    protected ValidationRule|array|null $eachRules = null;

    /** @var array<string, ValidationRule>|null */
    protected ?array $childRules = null;

    /** @param  Arrayable<array-key, string|\BackedEnum>|list<string|\BackedEnum>|null  $keys */
    public function __construct(Arrayable|array|null $keys = null)
    {
        if (is_null($keys)) {
            $this->keys = [];

            return;
        }

        /** @var list<string|\BackedEnum> $resolved */
        $resolved = $keys instanceof Arrayable ? array_values($keys->toArray()) : array_values($keys);
        $this->keys = $resolved;
    }

    /** @param  ValidationRule|array<string, ValidationRule>  $rules */
    public function each(ValidationRule|array $rules): static
    {
        $this->eachRules = $rules;

        return $this;
    }

    /** @return ValidationRule|array<string, ValidationRule>|null */
    public function getEachRules(): ValidationRule|array|null
    {
        return $this->eachRules;
    }

    /**
     * Define rules for fixed-key children of this array/object.
     *
     * Unlike each() which produces wildcard paths (items.*.name),
     * children() produces fixed paths (search.value, search.regex).
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

    public function withoutEachRules(): static
    {
        $clone = clone $this;
        $clone->eachRules = null;
        $clone->childRules = null;

        return $clone;
    }

    public function min(int $value): static
    {
        return $this->addRule('min:' . $value);
    }

    public function max(int $value): static
    {
        return $this->addRule('max:' . $value);
    }

    public function between(int $min, int $max): static
    {
        return $this->addRule('between:' . $min . ',' . $max);
    }

    public function exactly(int $value): static
    {
        return $this->addRule('size:' . $value);
    }

    public function list(): static
    {
        return $this->addRule('list');
    }

    public function requiredArrayKeys(string ...$keys): static
    {
        return $this->addRule('required_array_keys:' . implode(',', $keys));
    }

    public function distinct(?string $mode = null): static
    {
        return $this->addRule($mode ? 'distinct:' . $mode : 'distinct');
    }

    public function contains(string|int ...$values): static
    {
        return $this->addRule('contains:' . implode(',', $values));
    }

    public function doesntContain(string|int ...$values): static
    {
        return $this->addRule('doesnt_contain:' . implode(',', $values));
    }

    protected function buildArrayRule(): string
    {
        if ($this->keys === []) {
            return 'array';
        }

        $keys = array_map(
            static fn (string|\BackedEnum $key): string|int => $key instanceof \BackedEnum ? $key->value : $key,
            $this->keys,
        );

        return 'array:' . implode(',', $keys);
    }

    /** @return array<string, mixed> */
    public function buildNestedRules(string $attribute): array
    {
        $rules = $this->buildEachNestedRules($attribute);

        return array_merge($rules, $this->buildChildNestedRules($attribute));
    }

    /** @return array<string, mixed> */
    private function buildEachNestedRules(string $attribute): array
    {
        $eachRules = $this->getEachRules();

        if ($eachRules instanceof ValidationRule) {
            $key = $attribute . '.*';
            $rules = [$key => $eachRules];

            return $eachRules instanceof self
                ? array_merge($rules, $eachRules->buildNestedRules($key))
                : $rules;
        }

        if (! is_array($eachRules)) {
            return [];
        }

        $rules = [];

        foreach ($eachRules as $field => $rule) {
            $key = $attribute . '.*.' . $field;
            $rules[$key] = $rule;

            if ($rule instanceof self) {
                $rules = array_merge($rules, $rule->buildNestedRules($key));
            }
        }

        return $rules;
    }

    /** @return array<string, mixed> */
    private function buildChildNestedRules(string $attribute): array
    {
        $rules = [];

        foreach ($this->childRules ?? [] as $field => $rule) {
            $key = $attribute . '.' . $field;
            $rules[$key] = $rule;

            if ($rule instanceof self) {
                $rules = array_merge($rules, $rule->buildNestedRules($key));
            }
        }

        return $rules;
    }

    /** @return list<string|object> */
    protected function buildValidationRules(): array
    {
        return [
            ...$this->rules,
            ...$this->reorderConstraints([$this->buildArrayRule(), ...$this->constraints]),
        ];
    }
}
