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
     * The rules setData() explodes from the initial rules, kept when the
     * explode does not read the data: null before the first setData(), false
     * when it reads the data.
     *
     * @var array<array-key, mixed>|false|null
     */
    private array|false|null $rulesWithoutData = null;

    /**
     * Message templates by translator, locale, attribute type, rule and
     * attribute, for the custom and fallback messages in $messageSources.
     *
     * @var array<string, string>
     */
    private array $messageTemplates = [];

    /** @var array{0: array<array-key, mixed>, 1: array<array-key, mixed>}|null */
    private ?array $messageSources = null;

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
     * Sets the data, and reuses the rules the first call exploded when the
     * explode does not read the data: string rules on keys without a `*`.
     * Byte-identical to the parent otherwise.
     *
     * @param  array<array-key, mixed>  $data
     * @return $this
     */
    public function setData(array $data): static
    {
        if (! is_array($this->rulesWithoutData)) {
            // parent::setData() calls setRules() with the same initial rules,
            // which clears the known result.
            $readsData = $this->rulesWithoutData === false;
            parent::setData($data);
            $this->rulesWithoutData = $readsData || $this->explodeReadsData($this->initialRules) ? false : $this->rules;

            return $this;
        }

        $this->data = $this->parseData($data);
        $this->rules = $this->rulesWithoutData;

        return $this;
    }

    /**
     * @param  array<array-key, mixed>  $rules
     * @return $this
     */
    public function setRules(array $rules): static
    {
        $this->rulesWithoutData = null;

        return parent::setRules($rules);
    }

    /**
     * @param  array<array-key, mixed>  $rules
     */
    private function explodeReadsData(array $rules): bool
    {
        foreach ($rules as $attribute => $rule) {
            if (str_contains((string) $attribute, '*') || ! is_string($rule) && (! is_array($rule) || array_filter($rule, is_string(...)) !== $rule)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Looks up the message template once per attribute, rule and attribute
     * type. The key also holds the translator and its locale, and a change of
     * the custom or fallback messages drops the kept templates. Translation
     * lines added while the validator lives are not seen.
     *
     * @param  string  $attribute
     * @param  string  $rule
     */
    protected function getMessage($attribute, $rule): mixed
    {
        $sources = [$this->customMessages, $this->fallbackMessages];

        if ($sources !== $this->messageSources) {
            $this->messageTemplates = [];
            $this->messageSources = $sources;
        }

        $key = spl_object_id($this->translator) . '|' . $this->translator->getLocale() . '|' . $this->getAttributeType($attribute) . '|' . $rule . '|' . $attribute;

        if (! isset($this->messageTemplates[$key])) {
            $message = parent::getMessage($attribute, $rule);

            if (! is_string($message)) {
                return $message;
            }

            $this->messageTemplates[$key] = $message;
        }

        return $this->messageTemplates[$key];
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
