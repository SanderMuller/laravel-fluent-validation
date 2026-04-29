<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\FastCheck;

use Closure;
use DateTime;

/**
 * Compiles value-only rule strings into a `Closure(mixed): bool`.
 * Handles type, format, date-literal, size, in/not_in, regex, digit checks.
 *
 * Does NOT handle:
 *  - `prohibited` (see {@see ProhibitedCompiler})
 *  - cross-field references (see {@see ItemContextCompiler})
 *  - presence conditionals (see {@see PresenceConditionalCompiler})
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
        $config = self::parse($ruleString);

        return $config !== null ? self::buildClosure($config) : null;
    }

    /**
     * Parse a pipe-delimited rule string into a fast-check config.
     * Returns null if any rule part is not fast-checkable.
     *
     * @internal Exposed for {@see ItemContextCompiler} reuse.
     *
     * @return array<string, mixed>|null
     */
    public static function parse(string $ruleString): ?array
    {
        $config = self::initialConfig();

        foreach (explode('|', $ruleString) as $part) {
            $result = self::parsePart($part, $config);

            if ($result === null) {
                return null;
            }

            $config = $result;
        }

        if (! self::validateSizeRuleHasType($config)) {
            return null;
        }

        return $config;
    }

    /**
     * Parse a single rule part and update the config. Returns null if unsupported.
     *
     * @internal Exposed for {@see ItemContextCompiler} reuse.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     */
    public static function parsePart(string $part, array $config): ?array
    {
        // Simple boolean flags. `prohibited` intentionally excluded here —
        // ProhibitedCompiler owns that family. `filled` not fast-checkable:
        // distinguishing absent vs present-null requires presence tracking
        // the closure doesn't have.
        $boolFlags = [
            'required', 'string', 'numeric', 'boolean',
            'array', 'email', 'date', 'url', 'ip', 'uuid', 'ulid',
            'accepted', 'declined',
        ];

        if (in_array($part, $boolFlags, true)) {
            return [...$config, $part => true];
        }

        return match (true) {
            $part === 'integer' => [...$config, 'integer' => true],
            $part === 'integer:strict' => [...$config, 'integer' => true, 'integer.strict' => true],
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
            default => null,
        };
    }

    /**
     * Initial config with every recognized key pre-populated. Exposed for
     * {@see ItemContextCompiler} which extends it with field-ref keys.
     *
     * @internal
     *
     * @return array<string, mixed>
     */
    public static function initialConfig(): array
    {
        return [
            'required' => false, 'filled' => false,
            'nullable' => false, 'sometimes' => false,
            'string' => false, 'numeric' => false, 'integer' => false, 'integer.strict' => false,
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
    }

    /**
     * Size rules (min/max) require a type flag so the closure knows how to
     * measure: string length, array count, or numeric value. Without one,
     * Laravel infers from runtime type — not fast-checkable.
     *
     * @internal Exposed for {@see ItemContextCompiler} reuse.
     *
     * @param  array<string, mixed>  $config
     */
    public static function validateSizeRuleHasType(array $config): bool
    {
        if ($config['min'] === null && $config['max'] === null) {
            return true;
        }

        return $config['string'] === true
            || $config['array'] === true
            || $config['numeric'] === true
            || $config['integer'] === true;
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
            return null;
        }

        return [...$config, $key => $timestamp];
    }

    /**
     * @internal Exposed for {@see ItemContextCompiler} reuse.
     *
     * @param  array<string, mixed>  $c
     * @return Closure(mixed): bool
     */
    public static function buildClosure(array $c): Closure
    {
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

        /** @var list<Closure(mixed): bool> $checks */
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
            if ($required && (in_array($value, [null, '', []], true))) {
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

            if ($hasSize) {
                if ($isString && is_string($value)) {
                    $size = mb_strlen($value);
                } elseif ($isArray && is_array($value)) {
                    $size = count($value);
                } elseif (($isNumeric || $isInteger) && is_numeric($value)) {
                    // is_numeric narrows to int|float|numeric-string; +0
                    // promotes string to int/float uniformly.
                    $size = is_string($value) ? $value + 0 : $value;
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
     * @param list<Closure(mixed): bool> $checks
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
            $checks[] = ($c['integer.strict'] ?? false) === true
                ? static fn (mixed $v): bool => is_int($v)
                : static fn (mixed $v): bool => filter_var($v, FILTER_VALIDATE_INT) !== false;
        }
    }

    /**
     * @param  array<string, mixed>  $c
     * @param list<Closure(mixed): bool> $checks
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

        if (($c['alpha'] ?? false) === true) {
            $checks[] = static fn (mixed $v): bool => (is_string($v) || is_int($v) || is_float($v)) && (bool) preg_match('/\A[a-zA-Z]+\z/u', (string) $v);
        }

        if (($c['alphaDash'] ?? false) === true) {
            $checks[] = static fn (mixed $v): bool => (is_string($v) || is_int($v) || is_float($v)) && (bool) preg_match('/\A[a-zA-Z0-9_-]+\z/u', (string) $v);
        }

        if (($c['alphaNum'] ?? false) === true) {
            $checks[] = static fn (mixed $v): bool => (is_string($v) || is_int($v) || is_float($v)) && (bool) preg_match('/\A[a-zA-Z0-9]+\z/u', (string) $v);
        }
    }

    /**
     * @param  array<string, mixed>  $c
     * @param list<Closure(mixed): bool> $checks
     */
    private static function addDateChecks(array $c, array &$checks): void
    {
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
                    $d = DateTime::createFromFormat('!' . $dateFormat, $v);

                    return $d !== false && $d->format($dateFormat) === $v;
                }

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
     * @param list<Closure(mixed): bool> $checks
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
