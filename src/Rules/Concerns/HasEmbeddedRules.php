<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Rules\Concerns;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Validation\Rule;

trait HasEmbeddedRules
{
    public function unique(string $table, ?string $column = null, ?Closure $callback = null, ?string $message = null): static
    {
        $rule = Rule::unique($table, $column ?? 'NULL');

        if ($callback instanceof Closure) {
            $callback($rule);
        }

        return $this->addRule($rule, $message);
    }

    public function exists(string $table, ?string $column = null, ?Closure $callback = null, ?string $message = null): static
    {
        $rule = Rule::exists($table, $column ?? 'NULL');

        if ($callback instanceof Closure) {
            $callback($rule);
        }

        return $this->addRule($rule, $message);
    }

    /** @param  class-string  $type */
    public function enum(string $type, ?Closure $callback = null, ?string $message = null): static
    {
        $enum = Rule::enum($type);

        if ($callback instanceof Closure) {
            $callback($enum);
        }

        return $this->addRule($enum, $message);
    }

    /** @param Arrayable<array-key, mixed>|array<int, mixed>|class-string<BackedEnum> $values */
    public function in(Arrayable|array|string $values, ?string $message = null): static
    {
        if (is_string($values) && enum_exists($values)) {
            $values = $values::cases();
        }

        return $this->addRule(Rule::in($values instanceof Arrayable ? $values->toArray() : $values), $message);
    }

    /** @param Arrayable<array-key, mixed>|array<int, mixed>|class-string<BackedEnum>|string|int $values */
    public function notIn(Arrayable|array|string|int $values, ?string $message = null): static
    {
        if (is_string($values) && enum_exists($values)) {
            $values = $values::cases();
        } elseif (is_string($values) || is_int($values)) {
            $values = [$values];
        }

        return $this->addRule(Rule::notIn($values instanceof Arrayable ? $values->toArray() : $values), $message);
    }
}
