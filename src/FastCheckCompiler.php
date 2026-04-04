<?php

declare(strict_types=1);

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
     * @return array{required: bool, filled: bool, string: bool, numeric: bool, integer: bool, boolean: bool, date: bool, email: bool, url: bool, accepted: bool, declined: bool, min: ?int, max: ?int, in: ?list<string>, notIn: ?list<string>, regex: ?string, notRegex: ?string}|null
     */
    private static function parse(string $ruleString): ?array
    {
        $config = [
            'required' => false, 'filled' => false,
            'string' => false, 'numeric' => false, 'integer' => false,
            'boolean' => false, 'date' => false, 'email' => false,
            'url' => false, 'accepted' => false, 'declined' => false,
            'min' => null, 'max' => null, 'in' => null, 'notIn' => null,
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
     * @param  array{required: bool, filled: bool, string: bool, numeric: bool, integer: bool, boolean: bool, date: bool, email: bool, url: bool, accepted: bool, declined: bool, min: ?int, max: ?int, in: ?list<string>, notIn: ?list<string>, regex: ?string, notRegex: ?string}  $config
     * @return array{required: bool, filled: bool, string: bool, numeric: bool, integer: bool, boolean: bool, date: bool, email: bool, url: bool, accepted: bool, declined: bool, min: ?int, max: ?int, in: ?list<string>, notIn: ?list<string>, regex: ?string, notRegex: ?string}|null
     */
    private static function parsePart(string $part, array $config): ?array
    {
        return match (true) {
            $part === 'required' => [...$config, 'required' => true],
            $part === 'filled' => [...$config, 'filled' => true],
            $part === 'string' => [...$config, 'string' => true],
            $part === 'numeric' => [...$config, 'numeric' => true],
            $part === 'integer', $part === 'integer:strict' => [...$config, 'integer' => true],
            $part === 'boolean' => [...$config, 'boolean' => true],
            $part === 'date' => [...$config, 'date' => true],
            $part === 'email' => [...$config, 'email' => true],
            $part === 'url' => [...$config, 'url' => true],
            $part === 'accepted' => [...$config, 'accepted' => true],
            $part === 'declined' => [...$config, 'declined' => true],
            in_array($part, ['nullable', 'sometimes', 'bail'], true) => $config,
            str_starts_with($part, 'min:') => [...$config, 'min' => (int) substr($part, 4)],
            str_starts_with($part, 'max:') => [...$config, 'max' => (int) substr($part, 4)],
            str_starts_with($part, 'in:') => [...$config, 'in' => self::parseInValues(substr($part, 3))],
            str_starts_with($part, 'not_in:') => [...$config, 'notIn' => self::parseInValues(substr($part, 7))],
            str_starts_with($part, 'regex:') => [...$config, 'regex' => substr($part, 6)],
            str_starts_with($part, 'not_regex:') => [...$config, 'notRegex' => substr($part, 10)],
            $part === 'array' || self::isDateComparison($part) => null,
            default => null,
        };
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
     * @param  array{required: bool, filled: bool, string: bool, numeric: bool, integer: bool, boolean: bool, date: bool, email: bool, url: bool, accepted: bool, declined: bool, min: ?int, max: ?int, in: ?list<string>, notIn: ?list<string>, regex: ?string, notRegex: ?string}  $c
     * @return \Closure(mixed): bool
     */
    private static function buildClosure(array $c): \Closure
    {
        return static function (mixed $value) use ($c): bool {
            if ($c['required'] && ($value === null || $value === '')) {
                return false;
            }

            if ($c['filled'] && ($value === null || $value === '')) {
                return false;
            }

            if ($value === null) {
                return true;
            }

            if ($c['accepted'] && ! in_array($value, ['yes', 'on', '1', 1, true, 'true'], true)) {
                return false;
            }

            if ($c['declined'] && ! in_array($value, ['no', 'off', '0', 0, false, 'false'], true)) {
                return false;
            }

            if ($c['boolean'] && ! in_array($value, [true, false, 0, 1, '0', '1'], true)) {
                return false;
            }

            if ($c['date'] && is_string($value) && strtotime($value) === false) {
                return false;
            }

            if ($c['email'] && (! is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false)) {
                return false;
            }

            if ($c['url'] && (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false)) {
                return false;
            }

            if ($c['string'] && ! is_string($value)) {
                return false;
            }

            if ($c['numeric'] && ! is_numeric($value)) {
                return false;
            }

            if ($c['integer'] && is_numeric($value) && (int) $value !== $value) {
                return false;
            }

            if ($c['string'] && is_string($value)) {
                $len = mb_strlen($value);

                if ($c['min'] !== null && $len < $c['min']) {
                    return false;
                }

                if ($c['max'] !== null && $len > $c['max']) {
                    return false;
                }
            } elseif ($c['numeric'] || $c['integer']) {
                if ($c['min'] !== null && $value < $c['min']) {
                    return false;
                }

                if ($c['max'] !== null && $value > $c['max']) {
                    return false;
                }
            }

            if ($c['in'] !== null && is_scalar($value) && ! in_array((string) $value, $c['in'], true)) {
                return false;
            }

            if ($c['notIn'] !== null && is_scalar($value) && in_array((string) $value, $c['notIn'], true)) {
                return false;
            }

            if ($c['regex'] !== null && is_string($value) && ! preg_match($c['regex'], $value)) {
                return false;
            }

            if ($c['notRegex'] !== null && is_string($value) && preg_match($c['notRegex'], $value)) {
                return false;
            }

            return true;
        };
    }
}
