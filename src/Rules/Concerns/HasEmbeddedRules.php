<?php

declare(strict_types=1);

namespace SanderMuller\FluentValidation\Rules\Concerns;

use Closure;
use Illuminate\Validation\Rule;

trait HasEmbeddedRules
{
    public function unique(string $table, ?string $column = null): static
    {
        return $this->addRule(Rule::unique($table, $column ?? 'NULL'));
    }

    public function exists(string $table, ?string $column = null): static
    {
        return $this->addRule(Rule::exists($table, $column ?? 'NULL'));
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
