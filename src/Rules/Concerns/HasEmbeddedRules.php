<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Rules\Concerns;

use Closure;
use Illuminate\Validation\Rule;

trait HasEmbeddedRules
{
    public function unique(string $table, ?string $column = null, ?Closure $callback = null): static
    {
        $rule = Rule::unique($table, $column ?? 'NULL');

        if ($callback instanceof Closure) {
            $callback($rule);
        }

        return $this->addRule($rule);
    }

    public function exists(string $table, ?string $column = null, ?Closure $callback = null): static
    {
        $rule = Rule::exists($table, $column ?? 'NULL');

        if ($callback instanceof Closure) {
            $callback($rule);
        }

        return $this->addRule($rule);
    }

    /** @param  class-string  $type */
    public function enum(string $type, ?Closure $callback = null): static
    {
        $enum = Rule::enum($type);

        if ($callback instanceof Closure) {
            $callback($enum);
        }

        return $this->addRule($enum);
    }

    /** @param  array<int, mixed>|class-string<\BackedEnum>  $values */
    public function in(array|string $values): static
    {
        if (is_string($values) && enum_exists($values)) {
            $values = $values::cases();
        }

        return $this->addRule(Rule::in($values));
    }

    /** @param  array<int, mixed>|class-string<\BackedEnum>  $values */
    public function notIn(array|string $values): static
    {
        if (is_string($values) && enum_exists($values)) {
            $values = $values::cases();
        }

        return $this->addRule(Rule::notIn($values));
    }
}
