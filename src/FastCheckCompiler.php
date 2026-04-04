<?php

declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Carbon\Carbon;

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
     * @return array{required: bool, string: bool, numeric: bool, integer: bool, boolean: bool, date: bool, min: ?int, max: ?int, in: ?list<string>}|null
     */
    private static function parse(string $ruleString): ?array
    {
        $config = [
            'required' => false, 'string' => false, 'numeric' => false,
            'integer' => false, 'boolean' => false, 'date' => false,
            'min' => null, 'max' => null, 'in' => null,
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
     * @param  array{required: bool, string: bool, numeric: bool, integer: bool, boolean: bool, date: bool, min: ?int, max: ?int, in: ?list<string>}  $config
     * @return array{required: bool, string: bool, numeric: bool, integer: bool, boolean: bool, date: bool, min: ?int, max: ?int, in: ?list<string>}|null
     */
    private static function parsePart(string $part, array $config): ?array
    {
        return match (true) {
            $part === 'required' => [...$config, 'required' => true],
            $part === 'string' => [...$config, 'string' => true],
            $part === 'numeric' => [...$config, 'numeric' => true],
            $part === 'integer', $part === 'integer:strict' => [...$config, 'integer' => true],
            $part === 'boolean' => [...$config, 'boolean' => true],
            $part === 'date' => [...$config, 'date' => true],
            in_array($part, ['nullable', 'sometimes', 'bail'], true) => $config,
            str_starts_with($part, 'min:') => [...$config, 'min' => (int) substr($part, 4)],
            str_starts_with($part, 'max:') => [...$config, 'max' => (int) substr($part, 4)],
            str_starts_with($part, 'in:') => [...$config, 'in' => array_map(
                static fn (string $v): string => trim($v, '"'),
                explode(',', substr($part, 3)),
            )],
            self::isUnsupported($part) => null,
            default => null,
        };
    }

    private static function isUnsupported(string $part): bool
    {
        return in_array($part, ['array', 'accepted', 'declined'], true)
            || str_starts_with($part, 'size:')
            || str_starts_with($part, 'between:')
            || self::isDateComparison($part);
    }

    private static function isDateComparison(string $part): bool
    {
        return str_starts_with($part, 'after:') || str_starts_with($part, 'before:')
            || str_starts_with($part, 'after_or_equal:') || str_starts_with($part, 'before_or_equal:')
            || str_starts_with($part, 'date_format:') || str_starts_with($part, 'date_equals:');
    }

    /**
     * @param  array{required: bool, string: bool, numeric: bool, integer: bool, boolean: bool, date: bool, min: ?int, max: ?int, in: ?list<string>}  $c
     * @return \Closure(mixed): bool
     */
    private static function buildClosure(array $c): \Closure
    {
        return static function (mixed $value) use ($c): bool {
            if ($c['required'] && ($value === null || $value === '')) {
                return false;
            }

            if ($value === null) {
                return true;
            }

            if ($c['boolean'] && ! in_array($value, [true, false, 0, 1, '0', '1'], true)) {
                return false;
            }

            if ($c['date'] && is_string($value) && Carbon::parse($value)->getTimestamp() === false) {
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

            return true;
        };
    }
}
