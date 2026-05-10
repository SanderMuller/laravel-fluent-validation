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
     * Soft cap on the compile cache. Rule strings normally converge to a
     * small fixed set per app, but apps that build rules from runtime values
     * (per-tenant `in:` lists, request-specific regexes, generated literals)
     * can grow the cache without bound on long-lived Octane workers. Above
     * this size we drop the cache to avoid worker bloat — correctness is
     * preserved, only the warm hit-rate resets.
     */
    private const COMPILE_CACHE_MAX = 1024;

    /** @var array<string, ?Closure(mixed): bool> */
    private static array $compileCache = [];

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
        // Date-comparison rules (`after:today`, `before:now`, `date_equals:`,
        // ...) bake the result of `strtotime()` into the compiled closure at
        // compile time. Caching that closure across requests would freeze
        // relative timestamps for the lifetime of the Octane worker. Skip
        // the cache entirely for these — re-compilation is cheap.
        if (self::hasDateComparison($ruleString)) {
            return CoreValueCompiler::compile($ruleString)
                ?? ProhibitedCompiler::compile($ruleString);
        }

        if (array_key_exists($ruleString, self::$compileCache)) {
            return self::$compileCache[$ruleString];
        }

        if (count(self::$compileCache) >= self::COMPILE_CACHE_MAX) {
            self::$compileCache = [];
        }

        return self::$compileCache[$ruleString] = CoreValueCompiler::compile($ruleString)
            ?? ProhibitedCompiler::compile($ruleString);
    }

    private static function hasDateComparison(string $ruleString): bool
    {
        return str_contains($ruleString, 'after:')
            || str_contains($ruleString, 'before:')
            || str_contains($ruleString, 'after_or_equal:')
            || str_contains($ruleString, 'before_or_equal:')
            || str_contains($ruleString, 'date_equals:');
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
