<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use SanderMuller\FluentValidation\OptimizedValidator;

/**
 * Sets prepared rules on a validator that was built without rules, with less
 * work than setRules() where the rules allow it.
 *
 * @internal Implementation detail of `HasFluentRules` and `FluentValidator`.
 */
final class OptimizedRules
{
    /**
     * @param  array<string, mixed>  $rules
     */
    public static function set(OptimizedValidator $validator, array $rules): OptimizedValidator
    {
        $plainRules = PlainRules::of($rules);

        if ($plainRules !== null) {
            return $validator->setPlainRules($plainRules);
        }

        $exploded = UnconditionalRules::explode($validator->getData(), $rules);

        return $exploded === null ? $validator->setRules($rules) : $validator->setExplodedRules($rules, ...$exploded);
    }
}
