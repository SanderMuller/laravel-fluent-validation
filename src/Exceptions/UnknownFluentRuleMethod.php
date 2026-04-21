<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Exceptions;

use BadMethodCallException;
use SanderMuller\FluentValidation\Rules\FieldRule;

/**
 * Thrown when a method is called on the untyped `FluentRule::field()` builder
 * that is neither a defined method on `FieldRule` nor a registered Macroable
 * macro. Extends `BadMethodCallException` so downstream code catching that
 * base type continues to work — only the message text changes.
 *
 * @see FieldRule
 */
final class UnknownFluentRuleMethod extends BadMethodCallException
{
    public static function on(string $method): self
    {
        $hint = TypedBuilderHint::for($method)
            ?? 'Use a typed builder (`FluentRule::string()`, `::numeric()`, `::date()`, etc.) and chain the rule there.';

        return new self(sprintf(
            'FluentRule::field() has no method %s(). %s',
            $method,
            $hint,
        ));
    }
}
