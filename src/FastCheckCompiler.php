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
     * Compile a rule string into a closure that checks a single value against
     * item-level context (sibling fields). This variant resolves date field
     * references like `after:start_date` against the passed item array.
     *
     * Returns null if the rule contains parts that can't be fast-checked even
     * with item context. Used by RuleSet for wildcard-item slow-rule recovery.
     *
     * @return \Closure(mixed, array<string, mixed>): bool|null
     */
    public static function compileWithItemContext(string $ruleString): ?\Closure
    {
        $config = self::parseWithItemContext($ruleString);

        return $config !== null ? self::buildItemAwareClosure($config) : null;
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
        if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $param) !== 1) {
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
            default => self::parsePart($part, $config),
        };
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

        $hasFieldRef = $afterField !== null || $beforeField !== null
            || $afterOrEqualField !== null || $beforeOrEqualField !== null
            || $dateEqualsField !== null;

        if (! $hasFieldRef) {
            return static fn (mixed $value, array $_item): bool => $valueClosure($value);
        }

        return static function (mixed $value, array $item) use (
            $valueClosure,
            $afterField, $beforeField,
            $afterOrEqualField, $beforeOrEqualField,
            $dateEqualsField
        ): bool {
            if (! $valueClosure($value)) {
                return false;
            }

            // Value-level closure already handled nullable/empty-string skips.
            // If we got this far and $value isn't a string, fail field-ref checks.
            if (! is_string($value)) {
                // If the value is null/'' it passed nullable/empty semantics earlier;
                // in that case, field-ref comparisons should be skipped.
                if ($value === null || $value === '') {
                    return true;
                }

                return false;
            }

            $ts = strtotime($value);

            if ($ts === false) {
                return false;
            }

            if ($afterField !== null) {
                $ref = strtotime((string) ($item[$afterField] ?? ''));
                if ($ref === false || $ts <= $ref) {
                    return false;
                }
            }

            if ($afterOrEqualField !== null) {
                $ref = strtotime((string) ($item[$afterOrEqualField] ?? ''));
                if ($ref === false || $ts < $ref) {
                    return false;
                }
            }

            if ($beforeField !== null) {
                $ref = strtotime((string) ($item[$beforeField] ?? ''));
                if ($ref === false || $ts >= $ref) {
                    return false;
                }
            }

            if ($beforeOrEqualField !== null) {
                $ref = strtotime((string) ($item[$beforeOrEqualField] ?? ''));
                if ($ref === false || $ts > $ref) {
                    return false;
                }
            }

            if ($dateEqualsField !== null) {
                $ref = strtotime((string) ($item[$dateEqualsField] ?? ''));
                if ($ref === false || date('Y-m-d', $ts) !== date('Y-m-d', $ref)) {
                    return false;
                }
            }

            return true;
        };
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
