<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\FastCheck;

/**
 * Compiles rule strings that reference sibling fields in an item array —
 * `same:FIELD`, `different:FIELD`, `gt:FIELD`, `gte:FIELD`, `lt:FIELD`,
 * `lte:FIELD`, `after:FIELD`, `before:FIELD`, `after_or_equal:FIELD`,
 * `before_or_equal:FIELD`, `date_equals:FIELD`, and `confirmed` /
 * `confirmed:FIELD` — into an item-aware `Closure(mixed, array): bool`.
 *
 * Returns null if the rule contains no item-aware comparison tokens (fast
 * pre-filter), or if any part can't be fast-checked even with item context.
 *
 * @internal
 */
final class ItemContextCompiler
{
    /**
     * @return \Closure(mixed, array<string, mixed>): bool|null
     */
    public static function compile(string $ruleString, ?string $attributeName = null): ?\Closure
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
     * @return array<string, mixed>|null
     */
    private static function parseWithItemContext(string $ruleString): ?array
    {
        $config = self::initialConfig();

        foreach (explode('|', $ruleString) as $part) {
            $result = self::parsePartWithItemContext($part, $config);

            if ($result === null) {
                return null;
            }

            $config = $result;
        }

        if (! CoreValueCompiler::validateSizeRuleHasType($config)) {
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
     * @return array<string, mixed>
     */
    private static function initialConfig(): array
    {
        return [
            ...CoreValueCompiler::initialConfig(),
            'afterField' => null, 'beforeField' => null,
            'afterOrEqualField' => null, 'beforeOrEqualField' => null,
            'dateEqualsField' => null,
            'sameField' => null, 'differentField' => null,
            'gtField' => null, 'gteField' => null,
            'ltField' => null, 'lteField' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     */
    private static function parsePartWithItemContext(string $part, array $config): ?array
    {
        // For date rules, use the field-ref-aware parser. For everything else,
        // delegate to CoreValueCompiler's value-only parser.
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
            default => CoreValueCompiler::parsePart($part, $config),
        };
    }

    /**
     * Parse a date comparison rule allowing field references. If the parameter
     * is a date literal, behaves like a literal parse. Otherwise, if it's a
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
     * against the item array. Delegates most checks to CoreValueCompiler's
     * value-only closure.
     *
     * @param  array<string, mixed>  $c
     * @return \Closure(mixed, array<string, mixed>): bool
     */
    private static function buildItemAwareClosure(array $c): \Closure
    {
        $valueClosure = CoreValueCompiler::buildClosure($c);

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

            if ($sameField !== null && ($item[$sameField] ?? null) !== $value) {
                return false;
            }

            if ($differentField !== null && ($item[$differentField] ?? null) === $value) {
                return false;
            }

            if ($hasSizeComparison) {
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
            // to 0 in numeric comparisons).
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
     * @return array{0: int|float, 1: int|float}|null
     */
    private static function sizePair(mixed $value, mixed $ref, bool $isNumeric, bool $isInteger, bool $isArray): ?array
    {
        if ($isNumeric || $isInteger) {
            if (! is_numeric($ref) || ! is_numeric($value)) {
                return null;
            }

            return [$value + 0, $ref + 0];
        }

        if ($isArray) {
            if (! is_array($ref) || ! is_array($value)) {
                return null;
            }

            return [count($value), count($ref)];
        }

        if (! is_string($ref) || ! is_string($value)) {
            return null;
        }

        return [mb_strlen($value), mb_strlen($ref)];
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
}
