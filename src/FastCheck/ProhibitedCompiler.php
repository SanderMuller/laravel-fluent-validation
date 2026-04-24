<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\FastCheck;

use Closure;
use SanderMuller\FluentValidation\FastCheck\Shared\LaravelEmptiness;

/**
 * Compiles bare `prohibited` rules (optionally with `nullable` / `sometimes` /
 * `bail`) into a closure that enforces Laravel's `validateProhibited` —
 * value must match {@see LaravelEmptiness::isEmpty()}.
 *
 * Scope intentionally narrow. `prohibited` combined with any type/format/size/
 * cross-field rule hits a "value is explicitly null vs. absent from item"
 * ambiguity the closure can't resolve — those rules slow-path through Laravel.
 * See commit `fa7ebdf` for the full rationale.
 *
 * @internal
 */
final class ProhibitedCompiler
{
    private const ALLOWED_SIBLINGS = ['prohibited', 'nullable', 'sometimes', 'bail'];

    /**
     * @return Closure(mixed): bool|null
     */
    public static function compile(string $ruleString): ?Closure
    {
        $hasProhibited = false;

        foreach (explode('|', $ruleString) as $part) {
            if (! in_array($part, self::ALLOWED_SIBLINGS, true)) {
                return null;
            }

            if ($part === 'prohibited') {
                $hasProhibited = true;
            }
        }

        if (! $hasProhibited) {
            return null;
        }

        return LaravelEmptiness::isEmpty(...);
    }
}
