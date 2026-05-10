<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\FastCheck;

use Closure;

/**
 * Compiles value-only rule strings into a `Closure(mixed): bool`.
 * Handles type, format, date-literal, size, in/not_in, regex, digit checks.
 *
 * Does NOT handle:
 *  - `prohibited` (see {@see ProhibitedCompiler})
 *  - cross-field references (see {@see ItemContextCompiler})
 *  - presence conditionals (see {@see PresenceConditionalCompiler})
 *
 * Thin facade over {@see RuleConfigBuilder} — parsing and closure
 * construction live there so a new rule touches one file, not two.
 *
 * @internal
 */
final class CoreValueCompiler
{
    /**
     * Compile a rule string into a closure that checks a single value.
     * Returns null if the rule contains parts that can't be fast-checked.
     *
     * @return Closure(mixed): bool|null
     */
    public static function compile(string $ruleString): ?Closure
    {
        $config = RuleConfigBuilder::initialConfig();

        foreach (explode('|', $ruleString) as $part) {
            $result = RuleConfigBuilder::parseValuePart($part, $config);

            if ($result === null) {
                return null;
            }

            $config = $result;
        }

        if (! RuleConfigBuilder::validateSizeRuleHasType($config)) {
            return null;
        }

        return RuleConfigBuilder::buildValueClosure($config);
    }
}
