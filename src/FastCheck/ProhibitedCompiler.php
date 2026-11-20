<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\FastCheck;

use Closure;
use SanderMuller\FluentValidation\FastCheck\Shared\LaravelEmptiness;

/**
 * Compiles `prohibited` rules into a closure that enforces Laravel's
 * `validateProhibited`: the value must match {@see LaravelEmptiness::isEmpty()}.
 *
 * With other value rules, such as `nullable|prohibited|array|max:20`, the
 * closure also runs those rules on the value. A value arrives as null both
 * when it is explicitly null and when it is absent, so a check that is not
 * sure must fail and leave the item to Laravel (see commit `fa7ebdf`).
 * {@see CoreValueCompiler} follows that rule for every value.
 *
 * @internal
 */
final class ProhibitedCompiler
{
    /** `prohibited` and the modifiers that do not check a value by themselves. */
    private const MODIFIERS = ['prohibited', 'nullable', 'sometimes', 'bail'];

    /** Rules that Laravel runs on an empty value too, so the item stays with Laravel. */
    private const PRESENCE_RULES = ['required', 'accepted', 'declined', 'present', 'filled', 'missing'];

    /**
     * @return Closure(mixed): bool|null
     */
    public static function compile(string $ruleString): ?Closure
    {
        $parts = explode('|', $ruleString);

        if (! in_array('prohibited', $parts, true)) {
            return null;
        }

        $otherRules = array_values(array_diff($parts, self::MODIFIERS));

        if ($otherRules === []) {
            return LaravelEmptiness::isEmpty(...);
        }

        foreach ($otherRules as $rule) {
            if (str_starts_with($rule, 'prohibited') || in_array(explode('_', explode(':', $rule, 2)[0], 2)[0], self::PRESENCE_RULES, true)) {
                return null;
            }
        }

        $otherCheck = CoreValueCompiler::compile(implode('|', array_diff($parts, ['prohibited'])));

        if (! $otherCheck instanceof Closure) {
            return null;
        }

        return static fn (mixed $value): bool => LaravelEmptiness::isEmpty($value) && $otherCheck($value);
    }
}
