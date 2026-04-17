<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

/**
 * Compiles pipe-delimited rule strings into fast PHP closures
 * that validate a single value without invoking Laravel's validator.
 *
 * Used by both RuleSet (per-item validation) and OptimizedValidator
 * (per-attribute fast-checks in FormRequests).
 */
final class FastCheckCompiler
{
    /**
     * Compile a rule string into a closure that checks a single value.
     * Returns null if the rule contains parts that can't be fast-checked.
     *
     * @return \Closure(mixed): bool|null
     */
    public static function compile(string $ruleString): ?\Closure
    {
        $config = self::parse($ruleString);

        return $config !== null ? self::buildClosure($config) : null;
    }

    /**
     * Compile a rule string with presence-conditional rewriting
     * (`required_with`, `required_without`, `required_with_all`,
     * `required_without_all`). The returned closure evaluates the
     * presence condition(s) against the item, then delegates to the
     * pre-compiled "required active" or "required inactive" variant.
     *
     * Returns null if the rule contains no presence conditional, the
     * field identifiers are malformed, or the stripped remainder is
     * itself not fast-checkable.
     *
     * @return \Closure(mixed, array<string, mixed>): bool|null
     */
    public static function compileWithPresenceConditionals(string $ruleString): ?\Closure
    {
        if (! str_contains($ruleString, 'required_with:')
            && ! str_contains($ruleString, 'required_without:')
            && ! str_contains($ruleString, 'required_with_all:')
            && ! str_contains($ruleString, 'required_without_all:')
        ) {
            return null;
        }

        /** @var list<array{type: string, fields: list<string>}> $conditions */
        $conditions = [];
        $remaining = [];

        foreach (explode('|', $ruleString) as $part) {
            if (preg_match('/^(required_with_all|required_without_all|required_with|required_without):(.+)$/', $part, $m) === 1) {
                $fields = explode(',', $m[2]);

                // Validate identifier shape on every field; multi-param is supported.
                foreach ($fields as $field) {
                    if (preg_match('/\A[a-zA-Z_]\w*\z/', $field) !== 1) {
                        return null;
                    }
                }

                $conditions[] = ['type' => $m[1], 'fields' => $fields];
            } else {
                $remaining[] = $part;
            }
        }

        if ($conditions === []) {
            return null;
        }

        $stripped = implode('|', $remaining);

        // Compile the non-required remainder. Try item-aware first so
        // combinations like `required_with:foo|same:bar` or
        // `required_without:foo|gt:other` compose into one fast closure;
        // fall back to the value-only compiler for plain rules, wrapped
        // to the item-aware signature.
        /** @var ?\Closure(mixed, array<string, mixed>): bool $checkRest */
        $checkRest = $stripped === ''
            ? static fn (mixed $_value, array $_item): bool => true
            : self::buildItemAwareBranch($stripped);

        if (! $checkRest instanceof \Closure) {
            return null;
        }

        return static function (mixed $value, array $item) use ($conditions, $checkRest): bool {
            $active = false;
            foreach ($conditions as $condition) {
                if (self::presenceConditionActive($condition['type'], $condition['fields'], $item)) {
                    $active = true;
                    break;
                }
            }

            // When presence conditions activate, the field is required in
            // Laravel's sense: fail if empty per `validateRequired` (null,
            // whitespace-only string, or empty Countable/array).
            if ($active && self::isLaravelEmpty($value)) {
                return false;
            }

            return $checkRest($value, $item);
        };
    }

    /**
     * Build an item-aware branch closure for a stripped rule remainder.
     * Prefers `compileWithItemContext` so same/different/date-ref/size-ref
     * tokens compose; falls back to the value-only `compile()` wrapped to
     * the item-aware signature when the remainder is purely value-level.
     *
     * @return \Closure(mixed, array<string, mixed>): bool|null
     */
    private static function buildItemAwareBranch(string $ruleString): ?\Closure
    {
        $itemAware = self::compileWithItemContext($ruleString);

        if ($itemAware instanceof \Closure) {
            return $itemAware;
        }

        $valueOnly = self::compile($ruleString);

        if (! $valueOnly instanceof \Closure) {
            return null;
        }

        return static fn (mixed $value, array $_item): bool => $valueOnly($value);
    }

    /**
     * Match Laravel's `validateRequired` definition of "empty":
     *   - null
     *   - string whose `trim()` is ''
     *   - array or Countable with count() === 0
     *
     * Used by presence-conditional gates on both sides — sibling
     * presence and target required check.
     */
    private static function isLaravelEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return $value === [];
        }

        if ($value instanceof \Countable) {
            return count($value) === 0;
        }

        return false;
    }

    /**
     * A field is "present" by Laravel's `validateRequired` criteria: not null,
     * not whitespace-only string, not empty array/Countable.
     *
     * @param  list<string>  $fields
     * @param  array<string, mixed>  $item
     */
    private static function presenceConditionActive(string $type, array $fields, array $item): bool
    {
        $present = [];
        foreach ($fields as $field) {
            $present[] = ! self::isLaravelEmpty($item[$field] ?? null);
        }

        return match ($type) {
            'required_with' => in_array(true, $present, true),            // any present
            'required_without' => in_array(false, $present, true),         // any absent
            'required_with_all' => ! in_array(false, $present, true),      // all present
            'required_without_all' => ! in_array(true, $present, true),    // all absent
            default => false,
        };
    }

    /**
     * Compile a rule string into a closure that checks a single value against
     * item-level context (sibling fields). This variant resolves date field
     * references like `after:start_date` against the passed item array.
     *
     * When `$attributeName` is provided, the `confirmed` / `confirmed:X` rule
     * is rewritten to `same:${attr}_confirmation` (or `same:X`) before parse.
     * Without it, rules containing `confirmed` cannot be fast-checked because
     * the confirmation field name depends on the attribute being validated.
     *
     * Returns null if the rule contains parts that can't be fast-checked even
     * with item context. Used by RuleSet for wildcard-item slow-rule recovery.
     *
     * @return \Closure(mixed, array<string, mixed>): bool|null
     */
    public static function compileWithItemContext(string $ruleString, ?string $attributeName = null): ?\Closure
    {
        // Rewrite `confirmed` / `confirmed:X` to `same:...` when an attribute
        // name is available. `confirmed` alone uses `${attr}_confirmation` as
        // the sibling field (Laravel's default). Without an attribute name,
        // the rewrite can't happen — fall through and let the pre-filter bail.
        if ($attributeName !== null && self::containsConfirmedRule($ruleString)) {
            $ruleString = self::rewriteConfirmedRule($ruleString, $attributeName);
        }

        // Fast pre-filter: only re-parse if the rule actually has an item-aware
        // comparison. Without this, every slow rule pays for a second full
        // parse (wasted work for conditional/unknown rules).
        if (! str_contains($ruleString, 'after:')
            && ! str_contains($ruleString, 'before:')
            && ! str_contains($ruleString, 'date_equals:')
            && ! str_contains($ruleString, 'same:')
            && ! str_contains($ruleString, 'different:')
            && ! str_contains($ruleString, 'gt:')
            && ! str_contains($ruleString, 'gte:')
            && ! str_contains($ruleString, 'lt:')
            && ! str_contains($ruleString, 'lte:')
        ) {
            return null;
        }

        $config = self::parseWithItemContext($ruleString);

        return $config !== null ? self::buildItemAwareClosure($config) : null;
    }

    /**
     * Is the `confirmed` rule present as a standalone pipe part or with a
     * `confirmed:X` parameter? Rejects substring matches inside other rule
     * names (e.g., no false positive on a hypothetical `foo_confirmed`).
     */
    private static function containsConfirmedRule(string $ruleString): bool
    {
        foreach (explode('|', $ruleString) as $part) {
            if ($part === 'confirmed' || str_starts_with($part, 'confirmed:')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rewrite `confirmed` → `same:${attr}_confirmation` and
     * `confirmed:X` → `same:X`. Leaves other pipe parts alone.
     */
    private static function rewriteConfirmedRule(string $ruleString, string $attributeName): string
    {
        $parts = explode('|', $ruleString);

        foreach ($parts as $i => $part) {
            if ($part === 'confirmed') {
                $parts[$i] = 'same:' . $attributeName . '_confirmation';
            } elseif (str_starts_with($part, 'confirmed:')) {
                $parts[$i] = 'same:' . substr($part, 10);
            }
        }

        return implode('|', $parts);
    }

    /**
     * Parse a pipe-delimited rule string into a fast-check config.
     * Returns null if any rule part is not fast-checkable.
     *
     * @return array<string, mixed>|null
     */
    private static function parse(string $ruleString): ?array
    {
        $config = [
            'required' => false, 'filled' => false,
            'nullable' => false, 'sometimes' => false,
            'string' => false, 'numeric' => false, 'integer' => false,
            'boolean' => false, 'array' => false, 'email' => false, 'date' => false,
            'url' => false, 'ip' => false, 'uuid' => false, 'ulid' => false,
            'accepted' => false, 'declined' => false,
            'alpha' => false, 'alphaDash' => false, 'alphaNum' => false,
            'min' => null, 'max' => null,
            'digits' => null, 'digitsMin' => null, 'digitsMax' => null,
            'in' => null, 'notIn' => null,
            'regex' => null, 'notRegex' => null,
            'dateFormat' => null,
            'after' => null, 'before' => null,
            'afterOrEqual' => null, 'beforeOrEqual' => null,
            'dateEquals' => null,
        ];

        foreach (explode('|', $ruleString) as $part) {
            $result = self::parsePart($part, $config);

            if ($result === null) {
                return null;
            }

            $config = $result;
        }

        // Size rules (min/max) require a type flag so the closure knows
        // how to measure: string length, array count, or numeric value.
        // Without one, Laravel infers from runtime type — not fast-checkable.
        if (($config['min'] !== null || $config['max'] !== null)
            && $config['string'] === false
            && $config['array'] === false
            && $config['numeric'] === false
            && $config['integer'] === false
        ) {
            return null;
        }

        return $config;
    }

    /**
     * Parse a single rule part and update the config. Returns null if unsupported.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     */
    private static function parsePart(string $part, array $config): ?array
    {
        // Simple boolean flags
        // 'filled' not fast-checkable: distinguishing absent vs present-null
        // requires presence tracking the closure doesn't have.
        $boolFlags = [
            'required', 'string', 'numeric', 'boolean',
            'array', 'email', 'date', 'url', 'ip', 'uuid', 'ulid',
            'accepted', 'declined',
        ];

        if (in_array($part, $boolFlags, true)) {
            return [...$config, $part => true];
        }

        return match (true) {
            $part === 'integer', $part === 'integer:strict' => [...$config, 'integer' => true],
            $part === 'alpha', $part === 'alpha:ascii' => [...$config, 'alpha' => true],
            $part === 'alpha_dash', $part === 'alpha_dash:ascii' => [...$config, 'alphaDash' => true],
            $part === 'alpha_num', $part === 'alpha_num:ascii' => [...$config, 'alphaNum' => true],
            $part === 'nullable' => [...$config, 'nullable' => true],
            // 'sometimes' not fast-checkable: distinguishing absent from
            // present-null requires presence info the closure doesn't have.
            $part === 'sometimes' => null,
            $part === 'bail' => $config,
            str_starts_with($part, 'min:') => [...$config, 'min' => (int) substr($part, 4)],
            str_starts_with($part, 'max:') => [...$config, 'max' => (int) substr($part, 4)],
            str_starts_with($part, 'digits:') => [...$config, 'digits' => (int) substr($part, 7)],
            str_starts_with($part, 'digits_between:') => self::parseDigitsBetween($config, substr($part, 15)),
            str_starts_with($part, 'in:') => [...$config, 'in' => self::parseInValues(substr($part, 3))],
            str_starts_with($part, 'not_in:') => [...$config, 'notIn' => self::parseInValues(substr($part, 7))],
            str_starts_with($part, 'regex:') => [...$config, 'regex' => substr($part, 6)],
            str_starts_with($part, 'not_regex:') => [...$config, 'notRegex' => substr($part, 10)],
            str_starts_with($part, 'date_format:') => [...$config, 'dateFormat' => substr($part, 12)],
            str_starts_with($part, 'date_equals:') => self::parseDateLiteral($config, 'dateEquals', substr($part, 12)),
            str_starts_with($part, 'after_or_equal:') => self::parseDateLiteral($config, 'afterOrEqual', substr($part, 15)),
            str_starts_with($part, 'before_or_equal:') => self::parseDateLiteral($config, 'beforeOrEqual', substr($part, 16)),
            str_starts_with($part, 'after:') => self::parseDateLiteral($config, 'after', substr($part, 6)),
            str_starts_with($part, 'before:') => self::parseDateLiteral($config, 'before', substr($part, 7)),
            // 'array' is now handled by boolFlags above
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function parseDigitsBetween(array $config, string $value): array
    {
        $parts = explode(',', $value);

        return [...$config, 'digitsMin' => (int) $parts[0], 'digitsMax' => (int) ($parts[1] ?? $parts[0])];
    }

    /** @return list<string> */
    private static function parseInValues(string $values): array
    {
        return array_map(
            static fn (string $v): string => trim($v, '"'),
            explode(',', $values),
        );
    }

    /**
     * Parse a date comparison rule. Only compiles when the parameter is a
     * date literal (resolvable by strtotime), not a field reference.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     */
    private static function parseDateLiteral(array $config, string $key, string $param): ?array
    {
        // Field references (e.g., "start_date") can't be resolved at compile time.
        // Only date literals ("2030-01-01", "today", "now", "+1 week") are supported.
        $timestamp = strtotime($param);

        if ($timestamp === false) {
            return null; // Not a date literal — bail
        }

        return [...$config, $key => $timestamp];
    }

    /**
     * Parse a date comparison rule allowing field references. If the parameter
     * is a date literal, behaves like parseDateLiteral. Otherwise, if it's a
     * plausible field name, stores it under `{$key}Field` for item-time resolution.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     */
    private static function parseDateParamWithFieldRef(array $config, string $key, string $param): ?array
    {
        $timestamp = strtotime($param);

        if ($timestamp !== false) {
            return [...$config, $key => $timestamp];
        }

        // Plausible field identifier — store as deferred field reference.
        if (preg_match('/\A[a-zA-Z_]\w*\z/', $param) !== 1) {
            return null;
        }

        return [...$config, $key . 'Field' => $param];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function parseWithItemContext(string $ruleString): ?array
    {
        $config = [
            'required' => false, 'filled' => false,
            'nullable' => false, 'sometimes' => false,
            'string' => false, 'numeric' => false, 'integer' => false,
            'boolean' => false, 'array' => false, 'email' => false, 'date' => false,
            'url' => false, 'ip' => false, 'uuid' => false, 'ulid' => false,
            'accepted' => false, 'declined' => false,
            'alpha' => false, 'alphaDash' => false, 'alphaNum' => false,
            'min' => null, 'max' => null,
            'digits' => null, 'digitsMin' => null, 'digitsMax' => null,
            'in' => null, 'notIn' => null,
            'regex' => null, 'notRegex' => null,
            'dateFormat' => null,
            'after' => null, 'before' => null,
            'afterOrEqual' => null, 'beforeOrEqual' => null,
            'dateEquals' => null,
            'afterField' => null, 'beforeField' => null,
            'afterOrEqualField' => null, 'beforeOrEqualField' => null,
            'dateEqualsField' => null,
            'sameField' => null, 'differentField' => null,
            'gtField' => null, 'gteField' => null,
            'ltField' => null, 'lteField' => null,
        ];

        foreach (explode('|', $ruleString) as $part) {
            $result = self::parsePartWithItemContext($part, $config);

            if ($result === null) {
                return null;
            }

            $config = $result;
        }

        // Same size-rule type-flag guard as parse().
        if (($config['min'] !== null || $config['max'] !== null)
            && $config['string'] === false
            && $config['array'] === false
            && $config['numeric'] === false
            && $config['integer'] === false
        ) {
            return null;
        }

        // gt/gte/lt/lte against a sibling require an explicit type flag so
        // the closure knows how to size the values (numeric value, string
        // length, or array count). Without a type flag, bail — matches how
        // Laravel's validateGt/validateLt fail when the attribute has no
        // size-rule applied.
        $hasSizeComparison = $config['gtField'] !== null
            || $config['gteField'] !== null
            || $config['ltField'] !== null
            || $config['lteField'] !== null;

        if ($hasSizeComparison
            && $config['string'] === false
            && $config['array'] === false
            && $config['numeric'] === false
            && $config['integer'] === false
        ) {
            return null;
        }

        // date_format + date field-ref is a Laravel-only code path.
        // checkDateTimeOrder parses BOTH sides with the attribute's format
        // AND returns true when the referenced value is null/missing.
        // Our strtotime-based resolver can't match either behavior, so
        // bail and let Laravel handle it.
        $hasDateFieldRef = $config['afterField'] !== null
            || $config['beforeField'] !== null
            || $config['afterOrEqualField'] !== null
            || $config['beforeOrEqualField'] !== null
            || $config['dateEqualsField'] !== null;

        if ($config['dateFormat'] !== null && $hasDateFieldRef) {
            return null;
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     */
    private static function parsePartWithItemContext(string $part, array $config): ?array
    {
        // For date rules, use the field-ref-aware parser. For everything else,
        // delegate to the standard parsePart.
        return match (true) {
            str_starts_with($part, 'date_equals:') => self::parseDateParamWithFieldRef($config, 'dateEquals', substr($part, 12)),
            str_starts_with($part, 'after_or_equal:') => self::parseDateParamWithFieldRef($config, 'afterOrEqual', substr($part, 15)),
            str_starts_with($part, 'before_or_equal:') => self::parseDateParamWithFieldRef($config, 'beforeOrEqual', substr($part, 16)),
            str_starts_with($part, 'after:') => self::parseDateParamWithFieldRef($config, 'after', substr($part, 6)),
            str_starts_with($part, 'before:') => self::parseDateParamWithFieldRef($config, 'before', substr($part, 7)),
            str_starts_with($part, 'same:') => self::parseFieldOnlyRef($config, 'sameField', substr($part, 5)),
            str_starts_with($part, 'different:') => self::parseFieldOnlyRef($config, 'differentField', substr($part, 10)),
            str_starts_with($part, 'gte:') => self::parseFieldOnlyRef($config, 'gteField', substr($part, 4)),
            str_starts_with($part, 'lte:') => self::parseFieldOnlyRef($config, 'lteField', substr($part, 4)),
            str_starts_with($part, 'gt:') => self::parseFieldOnlyRef($config, 'gtField', substr($part, 3)),
            str_starts_with($part, 'lt:') => self::parseFieldOnlyRef($config, 'ltField', substr($part, 3)),
            default => self::parsePart($part, $config),
        };
    }

    /**
     * Parse a single-field reference parameter. Bails (returns null) if the
     * parameter isn't a single plausible identifier — so multi-param forms
     * like `different:a,b` fall through to Laravel.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     */
    private static function parseFieldOnlyRef(array $config, string $key, string $param): ?array
    {
        if (preg_match('/\A[a-zA-Z_]\w*\z/', $param) !== 1) {
            return null;
        }

        return [...$config, $key => $param];
    }

    /**
     * Build a closure that takes (value, item) and resolves date field refs
     * against the item array. Delegates most checks to buildClosure's helpers.
     *
     * @param  array<string, mixed>  $c
     * @return \Closure(mixed, array<string, mixed>): bool
     */
    private static function buildItemAwareClosure(array $c): \Closure
    {
        // Pre-resolve the value-level closure from buildClosure for all the
        // non-field-ref parts. Then wrap with field-ref handling.
        $valueClosure = self::buildClosure($c);

        /** @var ?string $afterField */
        $afterField = $c['afterField'];
        /** @var ?string $beforeField */
        $beforeField = $c['beforeField'];
        /** @var ?string $afterOrEqualField */
        $afterOrEqualField = $c['afterOrEqualField'];
        /** @var ?string $beforeOrEqualField */
        $beforeOrEqualField = $c['beforeOrEqualField'];
        /** @var ?string $dateEqualsField */
        $dateEqualsField = $c['dateEqualsField'];
        /** @var ?string $sameField */
        $sameField = $c['sameField'];
        /** @var ?string $differentField */
        $differentField = $c['differentField'];
        /** @var ?string $gtField */
        $gtField = $c['gtField'];
        /** @var ?string $gteField */
        $gteField = $c['gteField'];
        /** @var ?string $ltField */
        $ltField = $c['ltField'];
        /** @var ?string $lteField */
        $lteField = $c['lteField'];

        $isNumeric = (bool) $c['numeric'];
        $isInteger = (bool) $c['integer'];
        $isArray = (bool) $c['array'];
        $nullable = (bool) $c['nullable'];
        $hasImplicit = (bool) $c['required'] || (bool) $c['accepted'] || (bool) $c['declined'];

        $hasDateFieldRef = $afterField !== null || $beforeField !== null
            || $afterOrEqualField !== null || $beforeOrEqualField !== null
            || $dateEqualsField !== null;

        $hasEqualityFieldRef = $sameField !== null || $differentField !== null;

        $hasSizeComparison = $gtField !== null || $gteField !== null
            || $ltField !== null || $lteField !== null;

        if (! $hasDateFieldRef && ! $hasEqualityFieldRef && ! $hasSizeComparison) {
            return static fn (mixed $value, array $_item): bool => $valueClosure($value);
        }

        return static function (mixed $value, array $item) use (
            $valueClosure,
            $afterField, $beforeField,
            $afterOrEqualField, $beforeOrEqualField,
            $dateEqualsField,
            $sameField, $differentField,
            $gtField, $gteField, $ltField, $lteField,
            $isNumeric, $isInteger, $isArray, $nullable, $hasImplicit,
            $hasDateFieldRef, $hasSizeComparison
        ): bool {
            if (! $valueClosure($value)) {
                return false;
            }

            // Laravel parity: non-implicit rules skip on empty string when
            // no implicit rule is present (via `presentOrRuleIsImplicit`).
            // This covers `same:other` / `different:other` / `after:other`
            // etc. without `required` — Laravel never evaluates them on ''.
            if ($value === '' && ! $hasImplicit) {
                return true;
            }

            // Null skips only when `nullable` is set. Without `nullable`,
            // Laravel treats null as "present" and evaluates the cross-field
            // rule — `different:x` with value=null and x=null fails because
            // null === null.
            if ($value === null && $nullable) {
                return true;
            }

            // Equality field-refs (same / different) work on any value type.
            if ($sameField !== null && ($item[$sameField] ?? null) !== $value) {
                return false;
            }

            if ($differentField !== null && ($item[$differentField] ?? null) === $value) {
                return false;
            }

            if ($hasSizeComparison) {
                // Laravel parity: gt/gte/lt/lte require the ref side to match
                // the same type-family as the attribute. A numeric attribute
                // with a non-numeric ref (null, string, array, missing) fails;
                // a string attribute with a non-string ref fails; etc.
                // valueClosure has already guaranteed the value-side type.
                if ($gtField !== null) {
                    $sizes = self::sizePair($value, $item[$gtField] ?? null, $isNumeric, $isInteger, $isArray);
                    if ($sizes === null || $sizes[0] <= $sizes[1]) {
                        return false;
                    }
                }

                if ($gteField !== null) {
                    $sizes = self::sizePair($value, $item[$gteField] ?? null, $isNumeric, $isInteger, $isArray);
                    if ($sizes === null || $sizes[0] < $sizes[1]) {
                        return false;
                    }
                }

                if ($ltField !== null) {
                    $sizes = self::sizePair($value, $item[$ltField] ?? null, $isNumeric, $isInteger, $isArray);
                    if ($sizes === null || $sizes[0] >= $sizes[1]) {
                        return false;
                    }
                }

                if ($lteField !== null) {
                    $sizes = self::sizePair($value, $item[$lteField] ?? null, $isNumeric, $isInteger, $isArray);
                    if ($sizes === null || $sizes[0] > $sizes[1]) {
                        return false;
                    }
                }
            }

            if (! $hasDateFieldRef) {
                // String size comparisons handled above; no date path needed.
                return true;
            }

            if (! is_string($value)) {
                return false;
            }

            $ts = strtotime($value);

            if ($ts === false) {
                return false;
            }

            // Laravel parity: when the referenced field doesn't resolve to a
            // valid timestamp, its value is loosely compared as 0 (null coerces
            // to 0 in numeric comparisons). This means:
            //  - `after` / `after_or_equal`: unresolvable ref → compare against 0,
            //     so any positive timestamp passes.
            //  - `before` / `before_or_equal` / `date_equals`: unresolvable ref →
            //     compare against 0, so any positive timestamp fails.
            if ($afterField !== null) {
                $ref = self::resolveRefTimestamp($item, $afterField);
                if ($ts <= $ref) {
                    return false;
                }
            }

            if ($afterOrEqualField !== null) {
                $ref = self::resolveRefTimestamp($item, $afterOrEqualField);
                if ($ts < $ref) {
                    return false;
                }
            }

            if ($beforeField !== null) {
                $ref = self::resolveRefTimestamp($item, $beforeField);
                if ($ts >= $ref) {
                    return false;
                }
            }

            if ($beforeOrEqualField !== null) {
                $ref = self::resolveRefTimestamp($item, $beforeOrEqualField);
                if ($ts > $ref) {
                    return false;
                }
            }

            if ($dateEqualsField !== null) {
                $ref = self::resolveRefTimestamp($item, $dateEqualsField);
                if ($ref === 0 || date('Y-m-d', $ts) !== date('Y-m-d', $ref)) {
                    return false;
                }
            }

            return true;
        };
    }

    /**
     * Produce `[valueSize, refSize]` for a size comparison under the given
     * type flag. Returns null if the types don't match — Laravel rejects
     * `numeric|gt:ref` when ref isn't numeric, `string|gt:ref` when ref
     * isn't string, `array|gt:ref` when ref isn't array.
     *
     * The value side is always type-correct (valueClosure already enforced
     * it), so only the ref side needs the type-alignment check.
     *
     * @return array{0: int|float, 1: int|float}|null
     */
    private static function sizePair(mixed $value, mixed $ref, bool $isNumeric, bool $isInteger, bool $isArray): ?array
    {
        if ($isNumeric || $isInteger) {
            if (! is_numeric($ref)) {
                return null;
            }

            return [$value + 0, $ref + 0];
        }

        if ($isArray) {
            if (! is_array($ref)) {
                return null;
            }

            return [count($value), count($ref)];
        }

        // Default: string length comparison (under `string` rule).
        if (! is_string($ref)) {
            return null;
        }

        return [mb_strlen((string) $value), mb_strlen($ref)];
    }

    /**
     * Resolve an item's date field to a timestamp. Unresolvable values (null,
     * missing, empty, non-string, unparseable) coerce to 0 — matches Laravel's
     * loose-comparison behavior when a referenced field doesn't parse.
     *
     * @param  array<array-key, mixed>  $item
     */
    private static function resolveRefTimestamp(array $item, string $field): int
    {
        $raw = $item[$field] ?? null;

        if ($raw === null || $raw === '' || ! is_string($raw)) {
            return 0;
        }

        $ts = strtotime($raw);

        return $ts === false ? 0 : $ts;
    }

    /**
     * @param  array<string, mixed>  $c
     * @return \Closure(mixed): bool
     */
    private static function buildClosure(array $c): \Closure
    {
        // Pre-extract typed values for the hot path closure.
        $required = (bool) $c['required'];
        $nullable = (bool) $c['nullable'];
        $accepted = (bool) $c['accepted'];
        $declined = (bool) $c['declined'];
        $isString = (bool) $c['string'];
        $isNumeric = (bool) $c['numeric'];
        $isInteger = (bool) $c['integer'];
        $isArray = (bool) $c['array'];
        /** @var ?int $min */ $min = $c['min'];
        /** @var ?int $max */ $max = $c['max'];
        /** @var ?list<string> $in */ $in = $c['in'];
        /** @var ?list<string> $notIn */ $notIn = $c['notIn'];
        /** @var ?string $regex */ $regex = $c['regex'];
        /** @var ?string $notRegex */ $notRegex = $c['notRegex'];

        $hasImplicit = $required || $accepted || $declined;

        /** @var list<\Closure(mixed): bool> $checks */
        $checks = [];
        self::addTypeChecks($c, $checks);
        self::addFormatChecks($c, $checks);
        self::addDateChecks($c, $checks);
        self::addDigitChecks($c, $checks);

        $hasSize = $min !== null || $max !== null;
        $hasInRegex = $in !== null || $notIn !== null || $regex !== null || $notRegex !== null;

        return static function (mixed $value) use (
            $required, $nullable, $hasImplicit,
            $isString, $isNumeric, $isInteger, $isArray,
            $min, $max, $hasSize,
            $in, $notIn, $regex, $notRegex, $hasInRegex,
            $checks
        ): bool {
            // Presence gates (inlined for hot-path perf).
            // Explicit === comparisons beat in_array() here — avoids allocating
            // the [null, '', []] literal array on every closure call.
            if ($required && ($value === null || $value === '' || $value === [])) {
                return false;
            }

            if ($value === null) {
                if ($nullable && ! $hasImplicit) {
                    return true;
                }
            } elseif ($value === '' && ! $hasImplicit) {
                return true;
            }

            foreach ($checks as $check) {
                if (! $check($value)) {
                    return false;
                }
            }

            // Size check (inlined)
            if ($hasSize) {
                if ($isString && is_string($value)) {
                    $size = mb_strlen($value);
                } elseif ($isArray && is_array($value)) {
                    $size = count($value);
                } elseif (($isNumeric || $isInteger) && is_numeric($value)) {
                    $size = $value + 0;
                } else {
                    $size = null;
                }

                if ($size !== null) {
                    if ($min !== null && $size < $min) {
                        return false;
                    }

                    if ($max !== null && $size > $max) {
                        return false;
                    }
                }
            }

            // in/not_in/regex/not_regex (inlined)
            if ($hasInRegex) {
                $isScalar = is_scalar($value);

                if ($in !== null && (! $isScalar || ! in_array((string) $value, $in, true))) {
                    return false;
                }

                if ($notIn !== null && $isScalar && in_array((string) $value, $notIn, true)) {
                    return false;
                }

                if ($regex !== null || $notRegex !== null) {
                    $stringOrNumeric = is_string($value) || is_numeric($value);

                    if ($regex !== null && (! $stringOrNumeric || preg_match($regex, (string) $value) === 0)) {
                        return false;
                    }

                    if ($notRegex !== null && (! $stringOrNumeric || preg_match($notRegex, (string) $value) === 1)) {
                        return false;
                    }
                }
            }

            return true;
        };
    }

    /**
     * @param  array<string, mixed>  $c
     * @param  list<\Closure(mixed): bool>  $checks
     */
    private static function addTypeChecks(array $c, array &$checks): void
    {
        if (($c['accepted'] ?? false) === true) {
            $checks[] = static fn (mixed $v): bool => in_array($v, ['yes', 'on', '1', 1, true, 'true'], true);
        }

        if (($c['declined'] ?? false) === true) {
            $checks[] = static fn (mixed $v): bool => in_array($v, ['no', 'off', '0', 0, false, 'false'], true);
        }

        if (($c['boolean'] ?? false) === true) {
            $checks[] = static fn (mixed $v): bool => in_array($v, [true, false, 0, 1, '0', '1'], true);
        }

        if (($c['string'] ?? false) === true) {
            $checks[] = static fn (mixed $v): bool => is_string($v);
        }

        if (($c['array'] ?? false) === true) {
            $checks[] = static fn (mixed $v): bool => is_array($v);
        }

        if (($c['numeric'] ?? false) === true) {
            $checks[] = static fn (mixed $v): bool => is_numeric($v);
        }

        if (($c['integer'] ?? false) === true) {
            $checks[] = static fn (mixed $v): bool => filter_var($v, FILTER_VALIDATE_INT) !== false;
        }
    }

    /**
     * @param  array<string, mixed>  $c
     * @param  list<\Closure(mixed): bool>  $checks
     */
    private static function addFormatChecks(array $c, array &$checks): void
    {
        if (($c['email'] ?? false) === true) {
            $checks[] = static fn (mixed $v): bool => is_string($v) && filter_var($v, FILTER_VALIDATE_EMAIL) !== false;
        }

        if (($c['url'] ?? false) === true) {
            $checks[] = static fn (mixed $v): bool => is_string($v) && filter_var($v, FILTER_VALIDATE_URL) !== false;
        }

        if (($c['ip'] ?? false) === true) {
            $checks[] = static fn (mixed $v): bool => is_string($v) && filter_var($v, FILTER_VALIDATE_IP) !== false;
        }

        if (($c['uuid'] ?? false) === true) {
            $checks[] = static fn (mixed $v): bool => is_string($v) && (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $v);
        }

        if (($c['ulid'] ?? false) === true) {
            $checks[] = static fn (mixed $v): bool => is_string($v) && (bool) preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $v);
        }

        // Laravel's alpha/alpha_dash/alpha_num accept strings and numbers,
        // but reject bools, arrays, and null.
        $stringlike = static fn (mixed $v): bool => is_string($v) || is_int($v) || is_float($v);

        if (($c['alpha'] ?? false) === true) {
            $checks[] = static fn (mixed $v): bool => $stringlike($v) && (bool) preg_match('/\A[a-zA-Z]+\z/u', (string) $v);
        }

        if (($c['alphaDash'] ?? false) === true) {
            $checks[] = static fn (mixed $v): bool => $stringlike($v) && (bool) preg_match('/\A[a-zA-Z0-9_-]+\z/u', (string) $v);
        }

        if (($c['alphaNum'] ?? false) === true) {
            $checks[] = static fn (mixed $v): bool => $stringlike($v) && (bool) preg_match('/\A[a-zA-Z0-9]+\z/u', (string) $v);
        }
    }

    /**
     * @param  array<string, mixed>  $c
     * @param  list<\Closure(mixed): bool>  $checks
     */
    private static function addDateChecks(array $c, array &$checks): void
    {
        // Build a single combined date check closure that calls strtotime() once
        // per value, then evaluates all date conditions against the cached timestamp.
        $isDate = ($c['date'] ?? false) === true;
        /** @var ?string $dateFormat */
        $dateFormat = $c['dateFormat'] ?? null;
        /** @var ?int $after */
        $after = $c['after'] ?? null;
        /** @var ?int $afterOrEqual */
        $afterOrEqual = $c['afterOrEqual'] ?? null;
        /** @var ?int $before */
        $before = $c['before'] ?? null;
        /** @var ?int $beforeOrEqual */
        $beforeOrEqual = $c['beforeOrEqual'] ?? null;
        /** @var ?int $dateEquals */
        $dateEquals = $c['dateEquals'] ?? null;
        $dateEqualsStr = $dateEquals !== null ? date('Y-m-d', $dateEquals) : null;

        $hasDateChecks = $isDate || $dateFormat !== null || $after !== null
            || $afterOrEqual !== null || $before !== null || $beforeOrEqual !== null
            || $dateEquals !== null;

        if ($hasDateChecks) {
            $checks[] = static function (mixed $v) use ($isDate, $dateFormat, $after, $afterOrEqual, $before, $beforeOrEqual, $dateEqualsStr): bool {
                if (! is_string($v)) {
                    return false;
                }

                if ($dateFormat !== null) {
                    $d = \DateTime::createFromFormat('!' . $dateFormat, $v);

                    return $d !== false && $d->format($dateFormat) === $v;
                }

                // Single strtotime() call for all date comparisons
                $ts = strtotime($v);

                if ($ts === false) {
                    return ! $isDate && $after === null && $afterOrEqual === null
                        && $before === null && $beforeOrEqual === null && $dateEqualsStr === null;
                }

                if ($after !== null && $ts <= $after) {
                    return false;
                }

                if ($afterOrEqual !== null && $ts < $afterOrEqual) {
                    return false;
                }

                if ($before !== null && $ts >= $before) {
                    return false;
                }

                if ($beforeOrEqual !== null && $ts > $beforeOrEqual) {
                    return false;
                }

                if ($dateEqualsStr !== null && date('Y-m-d', $ts) !== $dateEqualsStr) {
                    return false;
                }

                return true;
            };
        }
    }

    /**
     * @param  array<string, mixed>  $c
     * @param  list<\Closure(mixed): bool>  $checks
     */
    private static function addDigitChecks(array $c, array &$checks): void
    {
        /** @var ?int $digits */
        $digits = $c['digits'];
        /** @var ?int $digitsMin */
        $digitsMin = $c['digitsMin'];
        /** @var ?int $digitsMax */
        $digitsMax = $c['digitsMax'];

        if ($digits !== null) {
            $checks[] = static function (mixed $v) use ($digits): bool {
                if (! is_scalar($v)) {
                    return false;
                }

                $s = (string) $v;

                return ctype_digit($s) && strlen($s) === $digits;
            };
        }

        if ($digitsMin !== null || $digitsMax !== null) {
            $checks[] = static function (mixed $v) use ($digitsMin, $digitsMax): bool {
                if (! is_scalar($v)) {
                    return false;
                }

                $s = (string) $v;

                return ctype_digit($s)
                    && ($digitsMin === null || strlen($s) >= $digitsMin)
                    && ($digitsMax === null || strlen($s) <= $digitsMax);
            };
        }
    }
}
