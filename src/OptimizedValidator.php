<?php

declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Carbon\Carbon;
use Illuminate\Validation\Validator;

/**
 * Validator subclass that fast-checks expanded wildcard attributes
 * using pure PHP closures before falling back to Laravel's validation.
 *
 * On valid data, eligible wildcard attributes skip Laravel's rule parsing,
 * method dispatch, and error formatting entirely — yielding significant
 * speedups on large arrays (hundreds/thousands of items).
 *
 * Ineligible attributes (object rules, date comparisons, cross-field
 * references, etc.) fall through to parent::validateAttribute() transparently.
 */
class OptimizedValidator extends Validator
{
    /** @var array<string, \Closure(mixed): bool> Fast checks keyed by wildcard pattern */
    private array $fastChecks = [];

    /** @var array<string, string> Expanded attribute → wildcard pattern lookup */
    private array $attributePatternMap = [];

    /** @var array<string, true> Attributes that passed the fast check — skip all their rules */
    private array $fastPassedAttributes = [];

    /** @var array<string, true> Attributes already evaluated for fast-check eligibility */
    private array $fastEvaluatedAttributes = [];

    /**
     * @param  array<string, \Closure(mixed): bool>  $fastChecks
     * @param  array<string, string>  $attributePatternMap
     */
    public function withFastChecks(array $fastChecks, array $attributePatternMap): static
    {
        $this->fastChecks = $fastChecks;
        $this->attributePatternMap = $attributePatternMap;

        return $this;
    }

    /**
     * Reset fast-check caches when validation restarts.
     * Parent resets MessageBag and failedRules; we must reset our caches too
     * so stale results from a previous passes() call don't leak through.
     */
    public function passes(): bool
    {
        $this->fastPassedAttributes = [];
        $this->fastEvaluatedAttributes = [];

        return parent::passes();
    }

    protected function validateAttribute($attribute, $rule)
    {
        // Already fast-checked and passed — skip all remaining rules for this attribute.
        if (isset($this->fastPassedAttributes[$attribute])) {
            return;
        }

        // First encounter for this attribute: try the fast check.
        if (! isset($this->fastEvaluatedAttributes[$attribute])) {
            $this->fastEvaluatedAttributes[$attribute] = true;

            $pattern = $this->attributePatternMap[$attribute] ?? null;

            if ($pattern !== null && isset($this->fastChecks[$pattern])) {
                $value = $this->getValue($attribute);

                if (($this->fastChecks[$pattern])($value)) {
                    $this->fastPassedAttributes[$attribute] = true;

                    return;
                }
            }
        }

        // Ineligible or failed fast check — let Laravel handle it.
        parent::validateAttribute($attribute, $rule);
    }

    /**
     * Build fast-check closures from compiled rule strings.
     * Returns closures keyed by wildcard pattern that accept a single value.
     *
     * Only string-only rules are eligible. Returns null for a pattern if any
     * rule is an object, contains date comparisons, cross-field references,
     * distinct, size/between, or other non-fast-checkable constraints.
     *
     * @param  array<string, mixed>  $compiledRules  Compiled rules keyed by wildcard pattern
     * @return array<string, \Closure(mixed): bool>
     */
    public static function buildFastChecks(array $compiledRules): array
    {
        $checks = [];

        foreach ($compiledRules as $pattern => $rule) {
            if (! is_string($rule)) {
                continue;
            }

            $check = self::compileFastCheck($rule);

            if ($check instanceof \Closure) {
                $checks[$pattern] = $check;
            }
        }

        return $checks;
    }

    /**
     * @return \Closure(mixed): bool|null
     */
    private static function compileFastCheck(string $ruleString): ?\Closure
    {
        $config = self::parseFastCheckConfig($ruleString);

        return $config !== null ? self::buildFastCheckClosure($config) : null;
    }

    /**
     * Parse a pipe-delimited rule string into a fast-check config array.
     * Returns null if any rule part is not fast-checkable.
     *
     * @return array{required: bool, string: bool, numeric: bool, integer: bool, boolean: bool, date: bool, min: ?int, max: ?int, in: ?list<string>}|null
     */
    private static function parseFastCheckConfig(string $ruleString): ?array
    {
        $config = [
            'required' => false, 'string' => false, 'numeric' => false,
            'integer' => false, 'boolean' => false, 'date' => false,
            'min' => null, 'max' => null, 'in' => null,
        ];

        foreach (explode('|', $ruleString) as $part) {
            if ($part === 'required') {
                $config['required'] = true;
            } elseif ($part === 'string') {
                $config['string'] = true;
            } elseif ($part === 'numeric') {
                $config['numeric'] = true;
            } elseif ($part === 'integer' || $part === 'integer:strict') {
                $config['integer'] = true;
            } elseif ($part === 'boolean') {
                $config['boolean'] = true;
            } elseif ($part === 'date') {
                $config['date'] = true;
            } elseif ($part === 'array') {
                return null;
            } elseif (str_starts_with($part, 'min:')) {
                $config['min'] = (int) substr($part, 4);
            } elseif (str_starts_with($part, 'max:')) {
                $config['max'] = (int) substr($part, 4);
            } elseif (str_starts_with($part, 'in:')) {
                $config['in'] = array_map(
                    static fn (string $v): string => trim($v, '"'),
                    explode(',', substr($part, 3)),
                );
            } elseif (in_array($part, ['nullable', 'sometimes', 'bail', 'accepted', 'declined'], true)) {
                // Flow modifiers and boolean-like checks — safe to include.
            } elseif (str_starts_with($part, 'size:') || str_starts_with($part, 'between:')) {
                return null;
            } elseif (self::isDateComparisonRule($part)) {
                return null;
            } else {
                return null;
            }
        }

        return $config;
    }

    private static function isDateComparisonRule(string $part): bool
    {
        return str_starts_with($part, 'after:') || str_starts_with($part, 'before:')
            || str_starts_with($part, 'after_or_equal:') || str_starts_with($part, 'before_or_equal:')
            || str_starts_with($part, 'date_format:') || str_starts_with($part, 'date_equals:');
    }

    /**
     * @param  array{required: bool, string: bool, numeric: bool, integer: bool, boolean: bool, date: bool, min: ?int, max: ?int, in: ?list<string>}  $c
     * @return \Closure(mixed): bool
     */
    private static function buildFastCheckClosure(array $c): \Closure
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
