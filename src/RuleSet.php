<?php

declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Carbon\Carbon;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use ReflectionProperty;
use SanderMuller\FluentValidation\Rules\ArrayRule;

/**
 * @implements Arrayable<string, mixed>
 */
final class RuleSet implements Arrayable
{
    /** @var array<string, mixed> */
    private array $fields = [];

    public static function make(): self
    {
        return new self();
    }

    /** @param  array<string, mixed>  $rules */
    public static function from(array $rules): self
    {
        $ruleSet = new self();
        $ruleSet->fields = $rules;

        return $ruleSet;
    }

    public function field(string $name, mixed $rule): self
    {
        $this->fields[$name] = $rule;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->flatten();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function expandWildcards(array $data): array
    {
        return $this->expand($data)[0];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(array $data, array $messages = [], array $attributes = []): array
    {
        $flat = $this->flatten();

        $topRules = [];
        /** @var array<string, array<string, mixed>> */
        $wildcardGroups = [];

        foreach ($flat as $field => $rule) {
            if (! str_contains($field, '*')) {
                $topRules[$field] = $rule;

                continue;
            }

            $starPos = (int) strpos($field, '.*');
            $parent = substr($field, 0, $starPos);
            $child = substr($field, $starPos + 2);
            $child = $child === '' ? '*' : ltrim($child, '.');
            $wildcardGroups[$parent][$child] = $rule;
        }

        if ($wildcardGroups === []) {
            return Validator::make($data, self::compile($topRules), $messages, $attributes)->validate();
        }

        $topValidator = Validator::make($data, self::compile($topRules), $messages, $attributes);
        if ($topValidator->fails()) {
            throw new ValidationException($topValidator);
        }

        /** @var array<string, list<string>> $allErrors */
        $allErrors = [];

        foreach ($wildcardGroups as $parent => $groupRules) {
            $items = data_get($data, $parent, []);
            if (! is_array($items)) {
                continue;
            }

            if ($items === []) {
                continue;
            }

            $isScalar = isset($groupRules['*']) && count($groupRules) === 1;

            $itemRules = $isScalar ? self::compile(['_v' => $groupRules['*']]) : self::compile($groupRules);

            if ($this->requiresFullExpansion($groupRules, $itemRules)) {
                return $this->validateStandard($data, $messages, $attributes);
            }

            // Build fast PHP checks for string-only rules. If ALL fields
            // are fast-checkable, items that pass the fast check skip
            // the Laravel validator entirely (~100x faster happy path).
            $fastChecks = $this->buildFastChecks($itemRules);
            $itemValidator = null;

            foreach ($items as $index => $item) {
                /** @var array<string, mixed> $itemData */
                $itemData = $isScalar ? ['_v' => $item] : (is_array($item) ? $item : []);

                // Fast path: if all fields pass the PHP check, skip Laravel entirely.
                if ($fastChecks !== null) {
                    $fastPass = true;

                    foreach ($fastChecks as $fastCheck) {
                        if (! $fastCheck($itemData)) {
                            $fastPass = false;
                            break;
                        }
                    }

                    if ($fastPass) {
                        continue;
                    }
                }

                // Slow path: use Laravel for proper error messages.
                if ($itemValidator === null) {
                    $itemValidator = Validator::make($itemData, $itemRules, $messages, $attributes);
                } else {
                    $itemValidator->setData($itemData);
                }

                if (! $itemValidator->passes()) {
                    foreach ($itemValidator->errors()->toArray() as $field => $fieldErrors) {
                        $fullPath = $isScalar
                            ? "{$parent}.{$index}"
                            : "{$parent}.{$index}.{$field}";
                        $allErrors[$fullPath] = $fieldErrors;
                    }
                }
            }
        }

        if ($allErrors !== []) {
            $errorValidator = Validator::make([], []);
            foreach ($allErrors as $field => $fieldErrors) {
                foreach ($fieldErrors as $fieldError) {
                    $errorValidator->errors()->add($field, $fieldError);
                }
            }

            throw new ValidationException($errorValidator);
        }

        return $topValidator->validated() + $data;
    }

    /**
     * Build fast PHP closures for compiled string rules.
     * Returns null if any field can't be fast-checked (has object rules or unknown rules).
     *
     * @param  array<string, mixed>  $compiledRules
     * @return list<\Closure(array<string, mixed>): bool>|null
     */
    private function buildFastChecks(array $compiledRules): ?array
    {
        $checks = [];

        foreach ($compiledRules as $field => $rule) {
            if (! is_string($rule)) {
                return null;
            }

            $check = $this->compileFastCheck($field, $rule);

            if (! $check instanceof \Closure) {
                return null;
            }

            $checks[] = $check;
        }

        return $checks;
    }

    /**
     * @return \Closure(array<string, mixed>): bool|null
     */
    private function compileFastCheck(string $field, string $ruleString): ?\Closure
    {
        $parts = explode('|', $ruleString);
        $isRequired = false;
        $isString = false;
        $isNumeric = false;
        $isInteger = false;
        $isBoolean = false;
        $isDate = false;
        $min = null;
        $max = null;
        /** @var list<string>|null $inValues */
        $inValues = null;

        foreach ($parts as $part) {
            if ($part === 'required') {
                $isRequired = true;
            } elseif ($part === 'string') {
                $isString = true;
            } elseif ($part === 'numeric') {
                $isNumeric = true;
            } elseif ($part === 'integer' || $part === 'integer:strict') {
                $isInteger = true;
            } elseif ($part === 'boolean') {
                $isBoolean = true;
            } elseif ($part === 'date') {
                $isDate = true;
            } elseif ($part === 'array') {
                return null;
            } elseif (str_starts_with($part, 'min:')) {
                $min = (int) substr($part, 4);
            } elseif (str_starts_with($part, 'max:')) {
                $max = (int) substr($part, 4);
            } elseif (str_starts_with($part, 'in:')) {
                $inValues = array_map(
                    static fn (string $v): string => trim($v, '"'),
                    explode(',', substr($part, 3))
                );
            } elseif (in_array($part, ['nullable', 'sometimes', 'bail'], true)) {
                // These affect flow, not value checks. Safe to include.
            } elseif (str_starts_with($part, 'after:') || str_starts_with($part, 'before:')
                || str_starts_with($part, 'after_or_equal:') || str_starts_with($part, 'before_or_equal:')
                || str_starts_with($part, 'date_format:') || str_starts_with($part, 'date_equals:')) {
                // Date comparison rules — handled by the date check below.
            } elseif ($part === 'accepted' || $part === 'declined') {
                // Boolean-like checks.
            } elseif (str_starts_with($part, 'size:') || str_starts_with($part, 'between:')) {
                return null;
            } else {
                return null;
            }
        }

        return static function (array $data) use ($field, $isRequired, $isString, $isNumeric, $isInteger, $isBoolean, $isDate, $min, $max, $inValues): bool {
            $value = $data[$field] ?? null;

            if ($isRequired && ($value === null || $value === '')) {
                return false;
            }

            if ($value === null) {
                return true;
            }

            if ($isBoolean && ! in_array($value, [true, false, 0, 1, '0', '1'], true)) {
                return false;
            }

            if ($isDate && is_string($value) && Carbon::parse($value)->getTimestamp() === false) {
                return false;
            }

            if ($isString && ! is_string($value)) {
                return false;
            }

            if ($isNumeric && ! is_numeric($value)) {
                return false;
            }

            if ($isInteger && is_numeric($value) && (int) $value !== $value) {
                return false;
            }

            if ($isString && is_string($value)) {
                $len = mb_strlen($value);
                if ($min !== null && $len < $min) {
                    return false;
                }

                if ($max !== null && $len > $max) {
                    return false;
                }
            } elseif ($isNumeric) {
                if ($min !== null && $value < $min) {
                    return false;
                }

                if ($max !== null && $value > $max) {
                    return false;
                }
            }

            if ($inValues !== null && is_scalar($value) && ! in_array((string) $value, $inValues, true)) {
                return false;
            }

            return true;
        };
    }

    /**
     * @param  array<string, mixed>  $groupRules
     * @param  array<string, mixed>  $compiledRules
     */
    private function requiresFullExpansion(array $groupRules, array $compiledRules): bool
    {
        foreach (array_keys($groupRules) as $child) {
            if (str_contains($child, '*')) {
                return true;
            }
        }

        foreach ($compiledRules as $compiledRule) {
            $ruleString = is_string($compiledRule) ? $compiledRule : (is_array($compiledRule) ? implode('|', array_filter($compiledRule, is_string(...))) : '');
            if (str_contains($ruleString, 'distinct')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validateStandard(array $data, array $messages, array $attributes): array
    {
        [$rules, $implicitAttributes] = $this->expand($data);

        $validator = Validator::make($data, self::compile($rules), $messages, $attributes);

        if ($implicitAttributes !== []) {
            (new ReflectionProperty($validator, 'implicitAttributes'))
                ->setValue($validator, $implicitAttributes);
        }

        return $validator->validate();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>}
     */
    public function expand(array $data): array
    {
        $flatRules = $this->flatten();
        $rules = [];
        $implicitAttributes = [];

        foreach ($flatRules as $field => $rule) {
            if (! str_contains($field, '*')) {
                $rules[$field] = $rule;

                continue;
            }

            $paths = WildcardExpander::expand($field, $data);

            if ($paths !== []) {
                $implicitAttributes[$field] = $paths;
            }

            foreach ($paths as $path) {
                $rules[$path] = $rule;
            }
        }

        return [$rules, $implicitAttributes];
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    public static function compile(array $rules): array
    {
        foreach ($rules as $field => $rule) {
            if (is_object($rule) && method_exists($rule, 'compiledRules')) {
                $rules[$field] = $rule->compiledRules();
            }
        }

        return $rules;
    }

    /** @return array<string, mixed> */
    private function flatten(): array
    {
        $rules = [];

        foreach ($this->fields as $field => $rule) {
            self::flattenRule($field, $rule, $rules);
        }

        return $rules;
    }

    /** @param  array<string, mixed>  $rules */
    private static function flattenRule(string $prefix, mixed $rule, array &$rules): void
    {
        if (! $rule instanceof ArrayRule) {
            $rules[$prefix] = $rule;

            return;
        }

        $eachRules = $rule->getEachRules();

        if ($eachRules === null) {
            $rules[$prefix] = $rule;

            return;
        }

        $rules[$prefix] = $rule->withoutEachRules();

        if ($eachRules instanceof ValidationRule) {
            self::flattenRule($prefix . '.*', $eachRules, $rules);

            return;
        }

        foreach ($eachRules as $childField => $childRule) {
            self::flattenRule($prefix . '.*.' . $childField, $childRule, $rules);
        }
    }
}
