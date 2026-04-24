<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Rules;

use BackedEnum;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use Illuminate\Validation\Rules\Contains;
use Illuminate\Validation\Rules\DoesntContain;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use SanderMuller\FluentValidation\Contracts\FluentRuleContract;
use SanderMuller\FluentValidation\Exceptions\CannotExtendListShapedEach;
use SanderMuller\FluentValidation\Rules\Concerns\HasFieldModifiers;
use SanderMuller\FluentValidation\Rules\Concerns\SelfValidates;
use UnitEnum;

class ArrayRule implements DataAwareRule, FluentRuleContract, ValidatorAwareRule
{
    use Conditionable;
    use HasFieldModifiers;
    use Macroable;
    use SelfValidates;

    /** @var list<string|BackedEnum> */
    protected array $keys;

    /** @var list<string> */
    protected array $constraints = [];

    /**
     * Keyed per-item rules — the `each(['name' => …])` shape. Always a
     * keyed map or null; never a bare `ValidationRule`. The list form
     * (`each(FluentRule::string())`) is stored separately on
     * `$eachListRule` below, so internal code paths that walk keyed
     * rules never have to branch on a union type.
     *
     * @var array<string, ValidationRule>|null
     */
    protected ?array $eachRules = null;

    /**
     * List-shape per-item rule — the `each(FluentRule::string())` shape
     * where every item is validated as a scalar against the same rule.
     * Mutually exclusive with `$eachRules`; setting one clears the other.
     */
    protected ?ValidationRule $eachListRule = null;

    /** @var array<string, ValidationRule>|null */
    protected ?array $childRules = null;

    /** @param  Arrayable<array-key, string|BackedEnum>|list<string|BackedEnum>|null  $keys */
    public function __construct(Arrayable|array|null $keys = null)
    {
        $this->seedLastConstraint('array');

        if (is_null($keys)) {
            $this->keys = [];

            return;
        }

        /** @var list<string|BackedEnum> $resolved */
        $resolved = $keys instanceof Arrayable ? array_values($keys->toArray()) : array_values($keys);
        $this->keys = $resolved;
    }

    /** @param  ValidationRule|array<string, ValidationRule>  $rules */
    public function each(ValidationRule|array $rules): static
    {
        if ($rules instanceof ValidationRule) {
            $this->eachListRule = $rules;
            $this->eachRules = null;
        } else {
            $this->eachListRule = null;
            $this->eachRules = $rules;
        }

        return $this;
    }

    /**
     * @return ValidationRule|array<string, ValidationRule>|null
     *
     * @deprecated 1.24.0 The list-form branch of the return union is deprecated.
     *             Use `getEachKeyedRules()` for the keyed `each([...])` case
     *             and `getEachListRule()` for the list-form `each(VR)` case.
     *             In 1.25.0 this method's return type will narrow to
     *             `?array<string, ValidationRule>` — the list-form rule will
     *             no longer be retrievable through this getter.
     */
    public function getEachRules(): ValidationRule|array|null
    {
        return $this->eachListRule ?? $this->eachRules;
    }

    /**
     * Keyed sub-rule map set via `each(['key' => $rule, ...])`.
     *
     * Returns null when `each()` was never called OR the current state is
     * list-shaped (set via `each(VR)`). Narrow-typed replacement for
     * `getEachRules()` in the keyed-form case.
     *
     * @return array<string, ValidationRule>|null
     */
    public function getEachKeyedRules(): ?array
    {
        return $this->eachRules;
    }

    /**
     * List-shape per-item rule set via `each(FluentRule::string())` — every
     * item is validated as a scalar against this rule.
     *
     * Returns null when `each()` was never called OR the current state is
     * keyed (set via `each([...])`). Narrow-typed replacement for the
     * `ValidationRule` branch of `getEachRules()`.
     */
    public function getEachListRule(): ?ValidationRule
    {
        return $this->eachListRule;
    }

    /**
     * Add one keyed sub-rule to the current each() shape. Intended for the
     * subclass-extends-parent pattern where the parent defines a keyed
     * each([...]) shape and the child adds a new field.
     *
     * Mutates `eachRules` only — base constraints (`nullable`, `max`, etc.)
     * on this ArrayRule survive untouched.
     *
     * @throws CannotExtendListShapedEach when `eachRules` is list-shaped
     *                                    (single ValidationRule input to each()).
     * @throws LogicException when $key already exists — silent override
     *                         would hide the "parent already defines this"
     *                         mistake. Use mergeEachRules() for intentional
     *                         replacement.
     */
    public function addEachRule(string $key, ValidationRule $rule): static
    {
        if ($key === '') {
            throw new InvalidArgumentException(
                'addEachRule() requires a non-empty key — empty keys expand to malformed wildcard paths (items.*.).'
            );
        }

        if ($this->eachListRule instanceof ValidationRule) {
            throw CannotExtendListShapedEach::on('addEachRule');
        }

        $existing = $this->eachRules ?? [];

        if (array_key_exists($key, $existing)) {
            throw new LogicException(sprintf(
                "addEachRule('%s'): key '%s' already exists in each(). "
                . 'Use mergeEachRules() if replacement is intentional.',
                $key,
                $key,
            ));
        }

        $existing[$key] = $rule;
        $this->eachRules = $existing;

        return $this;
    }

    /**
     * Merge multiple keyed sub-rules into the current each() shape,
     * later-wins on collision.
     *
     * @param  array<string, ValidationRule>  $rules
     *
     * @throws CannotExtendListShapedEach when `eachRules` is list-shaped.
     */
    public function mergeEachRules(array $rules): static
    {
        if ($this->eachListRule instanceof ValidationRule) {
            throw CannotExtendListShapedEach::on('mergeEachRules');
        }

        if (array_key_exists('', $rules)) {
            throw new InvalidArgumentException(
                'mergeEachRules() requires non-empty keys — empty keys expand to malformed wildcard paths (items.*.).'
            );
        }

        $existing = $this->eachRules ?? [];
        $this->eachRules = array_merge($existing, $rules);

        return $this;
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
        $clone->eachListRule = null;
        $clone->childRules = null;

        return $clone;
    }

    public function min(int $value, ?string $message = null): static
    {
        return $this->addRule('min:' . $value, $message);
    }

    public function max(int $value, ?string $message = null): static
    {
        return $this->addRule('max:' . $value, $message);
    }

    public function between(int $min, int $max, ?string $message = null): static
    {
        return $this->addRule('between:' . $min . ',' . $max, $message);
    }

    public function exactly(int $value, ?string $message = null): static
    {
        return $this->addRule('size:' . $value, $message);
    }

    public function list(?string $message = null): static
    {
        return $this->addRule('list', $message);
    }

    public function requiredArrayKeys(string ...$keys): static
    {
        return $this->addRule('required_array_keys:' . implode(',', $keys));
    }

    public function distinct(?string $mode = null, ?string $message = null): static
    {
        return $this->addRule($mode ? 'distinct:' . $mode : 'distinct', $message);
    }

    /**
     * @param Arrayable<array-key, mixed>|UnitEnum|array<int, mixed>|string|int ...$values
     *
     * Note: `Arrayable` is template-invariant, so concrete types like
     * `Collection<int, string>` don't satisfy `Arrayable<array-key, mixed>`.
     * Consumers passing a typed Collection at a PHPStan-strict analysis level
     * can unwrap via `->contains($collection->all())`.
     */
    public function contains(Arrayable|UnitEnum|array|string|int ...$values): static
    {
        $resolved = $this->flattenContainsValues($values);

        if (class_exists(Contains::class)) {
            return $this->addRule(new Contains($resolved));
        }

        // Laravel 11: `Rules\Contains` class shipped in L12. Fall back to
        // the pipe-string form with CSV-quoting that mirrors Contains::__toString.
        return $this->addRule('contains:' . $this->serializeContainsValues($resolved));
    }

    /** @param Arrayable<array-key, mixed>|UnitEnum|array<int, mixed>|string|int ...$values */
    public function doesntContain(Arrayable|UnitEnum|array|string|int ...$values): static
    {
        if (! class_exists(DoesntContain::class)) {
            throw new RuntimeException('doesntContain() requires Laravel 12+.');
        }

        return $this->addRule(new DoesntContain($this->flattenContainsValues($values)));
    }

    /**
     * CSV-quote + escape each value. Mirrors upstream `Rules\Contains::__toString()`
     * for the Laravel 11 fallback path.
     *
     * @param  array<int, mixed>  $values
     */
    private function serializeContainsValues(array $values): string
    {
        $serialized = array_map(static function (mixed $value): string {
            // Mirror Laravel's enum_value(): BackedEnum → value, UnitEnum → name.
            if ($value instanceof BackedEnum) {
                $value = $value->value;
            } elseif ($value instanceof UnitEnum) {
                $value = $value->name;
            }

            /** @var scalar $value */
            return '"' . str_replace('"', '""', (string) $value) . '"';
        }, $values);

        return implode(',', $serialized);
    }

    /**
     * Unwrap a single `Arrayable` or `array` varargs entry so `->contains([...])`
     * and `->contains(...$iter)` behave identically. Matches Laravel's
     * `Contains::__construct` input shape.
     *
     * Mixed multi-arg calls like `->contains(['a'], ['b'])` are rejected —
     * Laravel's Rule::contains silently ignores extras, and leaving nested
     * arrays/Arrayables in the value list would crash Contains::__toString.
     *
     * @param  array<int|string, mixed>  $values
     * @return array<int, mixed>
     */
    private function flattenContainsValues(array $values): array
    {
        if (count($values) === 1) {
            $only = reset($values);

            if ($only instanceof Arrayable) {
                return array_values($only->toArray());
            }

            if (is_array($only)) {
                return array_values($only);
            }
        }

        foreach ($values as $value) {
            if (is_array($value) || $value instanceof Arrayable) {
                throw new InvalidArgumentException(
                    'contains()/doesntContain() does not accept multiple array or Arrayable arguments. '
                    . 'Pass either a single iterable (->contains($values)) or variadic scalars (->contains($a, $b, $c)).'
                );
            }
        }

        return array_values($values);
    }

    protected function buildArrayRule(): string
    {
        if ($this->keys === []) {
            return 'array';
        }

        $keys = array_map(
            static fn (string|BackedEnum $key): string|int => $key instanceof BackedEnum ? $key->value : $key,
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
        if ($this->eachListRule instanceof ValidationRule) {
            $key = $attribute . '.*';
            $rules = [$key => $this->eachListRule];

            return $this->eachListRule instanceof self
                ? array_merge($rules, $this->eachListRule->buildNestedRules($key))
                : $rules;
        }

        if ($this->eachRules === null) {
            return [];
        }

        $rules = [];
        /** @var list<array<string, mixed>> $nested */
        $nested = [];

        foreach ($this->eachRules as $field => $rule) {
            $key = $attribute . '.*.' . $field;
            $rules[$key] = $rule;

            if ($rule instanceof self) {
                $nested[] = $rule->buildNestedRules($key);
            }
        }

        return $nested === [] ? $rules : array_merge($rules, ...$nested);
    }

    /** @return array<string, mixed> */
    private function buildChildNestedRules(string $attribute): array
    {
        $rules = [];
        /** @var list<array<string, mixed>> $nested */
        $nested = [];

        foreach ($this->childRules ?? [] as $field => $rule) {
            $key = $attribute . '.' . $field;
            $rules[$key] = $rule;

            if ($rule instanceof self) {
                $nested[] = $rule->buildNestedRules($key);
            }
        }

        return $nested === [] ? $rules : array_merge($rules, ...$nested);
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
