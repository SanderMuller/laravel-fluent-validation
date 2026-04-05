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
     * Parse a pipe-delimited rule string into a fast-check config.
     * Returns null if any rule part is not fast-checkable.
     *
     * @return array<string, mixed>|null
     */
    private static function parse(string $ruleString): ?array
    {
        $config = [
            'required' => false,
            'string' => false, 'numeric' => false, 'integer' => false,
            'boolean' => false, 'email' => false,
            'url' => false, 'ip' => false, 'uuid' => false, 'ulid' => false,
            'accepted' => false, 'declined' => false,
            'alpha' => false, 'alphaDash' => false, 'alphaNum' => false,
            'min' => null, 'max' => null,
            'digits' => null, 'digitsMin' => null, 'digitsMax' => null,
            'in' => null, 'notIn' => null,
            'regex' => null, 'notRegex' => null,
        ];

        foreach (explode('|', $ruleString) as $part) {
            $result = self::parsePart($part, $config);

            if ($result === null) {
                return null;
            }

            $config = $result;
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
        $boolFlags = [
            'required', 'string', 'numeric', 'boolean',
            'email', 'url', 'ip', 'uuid', 'ulid',
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
            in_array($part, ['nullable', 'sometimes', 'bail'], true) => $config,
            str_starts_with($part, 'min:') => [...$config, 'min' => (int) substr($part, 4)],
            str_starts_with($part, 'max:') => [...$config, 'max' => (int) substr($part, 4)],
            str_starts_with($part, 'digits:') => [...$config, 'digits' => (int) substr($part, 7)],
            str_starts_with($part, 'digits_between:') => self::parseDigitsBetween($config, substr($part, 15)),
            str_starts_with($part, 'in:') => [...$config, 'in' => self::parseInValues(substr($part, 3))],
            str_starts_with($part, 'not_in:') => [...$config, 'notIn' => self::parseInValues(substr($part, 7))],
            str_starts_with($part, 'regex:') => [...$config, 'regex' => substr($part, 6)],
            str_starts_with($part, 'not_regex:') => [...$config, 'notRegex' => substr($part, 10)],
            in_array($part, ['array', 'date', 'filled'], true) || self::isDateComparison($part) => null,
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

    private static function isDateComparison(string $part): bool
    {
        return str_starts_with($part, 'after:') || str_starts_with($part, 'before:')
            || str_starts_with($part, 'after_or_equal:') || str_starts_with($part, 'before_or_equal:')
            || str_starts_with($part, 'date_format:') || str_starts_with($part, 'date_equals:');
    }

    /**
     * @param  array<string, mixed>  $c
     * @return \Closure(mixed): bool
     */
    private static function buildClosure(array $c): \Closure
    {
        // Pre-extract typed values for the hot path closure.
        $required = (bool) $c['required'];
        $isString = (bool) $c['string'];
        $isNumeric = (bool) $c['numeric'];
        $isInteger = (bool) $c['integer'];
        /** @var ?int $min */ $min = $c['min'];
        /** @var ?int $max */ $max = $c['max'];
        /** @var ?list<string> $in */ $in = $c['in'];
        /** @var ?list<string> $notIn */ $notIn = $c['notIn'];
        /** @var ?string $regex */ $regex = $c['regex'];
        /** @var ?string $notRegex */ $notRegex = $c['notRegex'];

        // Pre-build a list of value checks — only includes rules that are active.
        /** @var list<\Closure(mixed): bool> $checks */
        $checks = [];
        self::addTypeChecks($c, $checks);
        self::addFormatChecks($c, $checks);
        self::addDigitChecks($c, $checks);

        return static function (mixed $value) use ($required, $isString, $isNumeric, $isInteger, $min, $max, $in, $notIn, $regex, $notRegex, $checks): bool {
            if ($required && ($value === null || $value === '')) {
                return false;
            }

            if ($value === null) {
                return true;
            }

            foreach ($checks as $check) {
                if (! $check($value)) {
                    return false;
                }
            }

            // Size checks depend on type context (string length vs numeric value).
            if ($min !== null || $max !== null) {
                if ($isString && is_string($value)) {
                    $len = mb_strlen($value);
                    if (($min !== null && $len < $min) || ($max !== null && $len > $max)) {
                        return false;
                    }
                } elseif ($isNumeric || $isInteger) {
                    if (($min !== null && $value < $min) || ($max !== null && $value > $max)) {
                        return false;
                    }
                }
            }

            if ($in !== null && is_scalar($value) && ! in_array((string) $value, $in, true)) {
                return false;
            }

            if ($notIn !== null && is_scalar($value) && in_array((string) $value, $notIn, true)) {
                return false;
            }

            if ($regex !== null && is_string($value) && preg_match($regex, $value) === 0) {
                return false;
            }

            if ($notRegex !== null && is_string($value) && preg_match($notRegex, $value) === 1) {
                return false;
            }

            return true;
        };
    }

    /** @param  list<\Closure(mixed): bool>  $checks */
    private static function addTypeChecks(array $c, array &$checks): void
    {
        if ($c['accepted']) {
            $checks[] = static fn (mixed $v): bool => in_array($v, ['yes', 'on', '1', 1, true, 'true'], true);
        }

        if ($c['declined']) {
            $checks[] = static fn (mixed $v): bool => in_array($v, ['no', 'off', '0', 0, false, 'false'], true);
        }

        if ($c['boolean']) {
            $checks[] = static fn (mixed $v): bool => in_array($v, [true, false, 0, 1, '0', '1'], true);
        }

        if ($c['string']) {
            $checks[] = static fn (mixed $v): bool => is_string($v);
        }

        if ($c['numeric']) {
            $checks[] = static fn (mixed $v): bool => is_numeric($v);
        }

        if ($c['integer']) {
            $checks[] = static fn (mixed $v): bool => filter_var($v, FILTER_VALIDATE_INT) !== false;
        }
    }

    /** @param  list<\Closure(mixed): bool>  $checks */
    private static function addFormatChecks(array $c, array &$checks): void
    {
        if ($c['email']) {
            $checks[] = static fn (mixed $v): bool => is_string($v) && filter_var($v, FILTER_VALIDATE_EMAIL) !== false;
        }

        if ($c['url']) {
            $checks[] = static fn (mixed $v): bool => is_string($v) && filter_var($v, FILTER_VALIDATE_URL) !== false;
        }

        if ($c['ip']) {
            $checks[] = static fn (mixed $v): bool => is_string($v) && filter_var($v, FILTER_VALIDATE_IP) !== false;
        }

        if ($c['uuid']) {
            $checks[] = static fn (mixed $v): bool => is_string($v) && (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $v);
        }

        if ($c['ulid']) {
            $checks[] = static fn (mixed $v): bool => is_string($v) && (bool) preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $v);
        }

        if ($c['alpha']) {
            $checks[] = static fn (mixed $v): bool => is_string($v) && (bool) preg_match('/\A[a-zA-Z]+\z/u', $v);
        }

        if ($c['alphaDash']) {
            $checks[] = static fn (mixed $v): bool => is_string($v) && (bool) preg_match('/\A[a-zA-Z0-9_-]+\z/u', $v);
        }

        if ($c['alphaNum']) {
            $checks[] = static fn (mixed $v): bool => is_string($v) && (bool) preg_match('/\A[a-zA-Z0-9]+\z/u', $v);
        }
    }

    /** @param  list<\Closure(mixed): bool>  $checks */
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
