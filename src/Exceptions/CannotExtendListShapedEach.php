<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Exceptions;

use LogicException;
use SanderMuller\FluentValidation\Rules\ArrayRule;

/**
 * Thrown when `ArrayRule::addEachRule()` / `mergeEachRules()` is invoked on
 * a rule whose `each()` was configured with a single `ValidationRule` (the
 * list form, e.g. `each(FluentRule::string())`). The list form is terminal
 * and not composable — the caller must rebuild as keyed form first.
 *
 * @see ArrayRule::addEachRule()
 * @see ArrayRule::mergeEachRules()
 */
final class CannotExtendListShapedEach extends LogicException
{
    public static function on(string $method): self
    {
        return new self(sprintf(
            'Cannot call %s() on an ArrayRule whose each() is list-shaped '
            . '(e.g. each(FluentRule::string())). Convert to keyed form '
            . "first: each(['key' => FluentRule::…]).",
            $method,
        ));
    }
}
