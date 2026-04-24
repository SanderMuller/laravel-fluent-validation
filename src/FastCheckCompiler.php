<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Closure;
use SanderMuller\FluentValidation\FastCheck\CoreValueCompiler;
use SanderMuller\FluentValidation\FastCheck\ItemContextCompiler;
use SanderMuller\FluentValidation\FastCheck\PresenceConditionalCompiler;
use SanderMuller\FluentValidation\FastCheck\ProhibitedCompiler;

/**
 * Thin dispatcher over per-family compilers under {@see FastCheck}.
 *
 * Compiles pipe-delimited rule strings into fast PHP closures that
 * validate a single value without invoking Laravel's validator.
 * Used by both {@see RuleSet} (per-item validation) and
 * {@see OptimizedValidator} (per-attribute fast-checks in FormRequests).
 *
 * Public API is stable — all three entry points keep verbatim signatures.
 */
final class FastCheckCompiler
{
    /**
     * Compile a value-only rule string into a closure that checks a single value.
     * Returns null if the rule contains parts that can't be fast-checked.
     *
     * Dispatch order (core-first): {@see CoreValueCompiler} covers the hot
     * path (type/format/size/in/regex/date-literal). {@see ProhibitedCompiler}
     * handles bare `prohibited` + nullable/sometimes/bail siblings.
     *
     * @return Closure(mixed): bool|null
     */
    public static function compile(string $ruleString): ?Closure
    {
        return CoreValueCompiler::compile($ruleString)
            ?? ProhibitedCompiler::compile($ruleString);
    }

    /**
     * Compile a rule string with presence-conditional rewriting
     * (`required_with`, `required_without`, `required_with_all`,
     * `required_without_all`). The returned closure evaluates the
     * presence condition(s) against the item, then delegates to the
     * pre-compiled "required active" or "required inactive" variant.
     *
     * @return Closure(mixed, array<string, mixed>): bool|null
     */
    public static function compileWithPresenceConditionals(string $ruleString): ?Closure
    {
        return PresenceConditionalCompiler::compile($ruleString);
    }

    /**
     * Compile a rule string into a closure that checks a single value against
     * item-level context (sibling fields). Handles `same`, `different`, `before`,
     * `after`, `date_equals`, `gt`, `gte`, `lt`, `lte`, `confirmed`.
     *
     * When `$attributeName` is provided, `confirmed` / `confirmed:X` rewrites
     * to `same:${attr}_confirmation` (or `same:X`) before parse. Without it,
     * rules containing `confirmed` cannot be fast-checked.
     *
     * @return Closure(mixed, array<string, mixed>): bool|null
     */
    public static function compileWithItemContext(string $ruleString, ?string $attributeName = null): ?Closure
    {
        return ItemContextCompiler::compile($ruleString, $attributeName);
    }
}
