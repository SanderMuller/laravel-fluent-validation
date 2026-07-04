<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Illuminate\Validation\ValidationRuleParser;
use Illuminate\Validation\Validator;

/**
 * Validator subclass that memoizes string-rule parsing. Behaviour-identical
 * to {@see Validator} — pass/fail, messages, and order are byte-identical.
 *
 * `ValidationRuleParser::parse('max:255')` → `['Max', ['255']]` is a pure,
 * context-free function of the rule string, but vanilla recomputes it
 * constantly: {@see getRule()} loops EVERY rule of an attribute and parses
 * each, and it is reached from many probes per pass (`hasRule`,
 * `isValidatable`, `requireParameterCount`, dependent-field checks…), so the
 * same string is re-parsed repeatedly within one `passes()` — roughly
 * O(rules²) per attribute. On a reused per-item validator (see
 * {@see Internal\ItemValidator}) it re-parses once per item on top of that.
 * Memoizing collapses the re-parses to a single hash hit.
 *
 * The memo is **static**: parsing has no instance/config state and no
 * invalidation trigger, so it is globally correct, shared across every
 * instance (and subclass — {@see OptimizedValidator} extends this class), and
 * stays warm for the worker's lifetime under Octane. Non-string rules
 * (arrays, `Rule` objects, `CompilableRules`) bypass the memo and parse live,
 * exactly as vanilla — they have no stable string key.
 *
 * Only `getRule()` is overridden — the looped, multiply-called parse site.
 * `validateAttribute()`'s single parse-per-rule is left to vanilla: overriding
 * that long method to save one parse per rule isn't worth the fragility.
 */
class MemoizingValidator extends Validator
{
    /**
     * Soft cap on the parse cache. Rule strings normally converge to a small
     * fixed set per app, but apps that build rules from runtime values
     * (per-tenant `in:` lists, request-specific regexes, generated literals)
     * can grow the cache without bound on long-lived Octane workers. Above
     * this size we drop the cache to avoid worker bloat — correctness is
     * preserved, only the warm hit-rate resets. Mirrors
     * {@see FastCheckCompiler}'s compile-cache cap.
     */
    private const PARSE_CACHE_MAX = 1024;

    /**
     * Worker-global memo: rule string => parsed `[name, parameters]`.
     *
     * @var array<string, array<int, mixed>>
     */
    protected static array $parsedRuleCache = [];

    /**
     * Clear the static parse cache. Used by tests that need a known cache
     * state; the Octane lifecycle is not a caller — workers tolerate the
     * cap-reset at runtime via {@see getRule()}.
     *
     * @internal Test-only.
     */
    public static function resetParseCache(): void
    {
        self::$parsedRuleCache = [];
    }

    /**
     * Get a rule and its parameters for a given attribute, memoizing the parse
     * of string rules. Byte-identical to the parent otherwise.
     *
     * @param  string  $attribute
     * @param  string|array<array-key, mixed>  $rules
     * @return array<int, mixed>|null
     */
    protected function getRule($attribute, $rules): ?array
    {
        if (! array_key_exists($attribute, $this->rules)) {
            return null;
        }

        $rules = (array) $rules;

        /** @var array<int, mixed> $attributeRules */
        $attributeRules = $this->rules[$attribute];

        foreach ($attributeRules as $rule) {
            if (is_string($rule)) {
                $parsed = $this->memoizedParse($rule);
            } elseif (is_array($rule)) {
                // Array-form rules (e.g. ['Exists', ...]) parse to a string
                // name and can match a probe, so parse them live (no stable
                // string key to memoize on).
                $parsed = ValidationRuleParser::parse($rule);
            } else {
                // Rule / CompilableRules objects parse to [$object, []] — the
                // name is the object, which can never equal a string rule-name
                // probe. Skipping is byte-identical to the parent, which would
                // parse then fail the in_array check below.
                continue;
            }

            [$name, $parameters] = $parsed;

            // Strict comparison (mandated by phpstan-strict-rules) is
            // behaviourally identical to the parent's loose `in_array` here:
            // both $name (a normalized rule name) and every element of $rules
            // are strings, so no type juggling can occur.
            if (in_array($name, $rules, true)) {
                return [$name, $parameters];
            }
        }

        return null;
    }

    /**
     * Parse a string rule once and cache it worker-wide.
     *
     * @return array<int, mixed>
     */
    private function memoizedParse(string $rule): array
    {
        if (! array_key_exists($rule, self::$parsedRuleCache)) {
            if (count(self::$parsedRuleCache) >= self::PARSE_CACHE_MAX) {
                self::$parsedRuleCache = [];
            }

            /** @var array<int, mixed> $freshParse */
            $freshParse = ValidationRuleParser::parse($rule);
            self::$parsedRuleCache[$rule] = $freshParse;
        }

        return self::$parsedRuleCache[$rule];
    }
}
