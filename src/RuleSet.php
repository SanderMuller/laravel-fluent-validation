<?php

declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use ReflectionProperty;
use SanderMuller\FluentValidation\Rules\ArrayRule;

/**
 * @implements Arrayable<string, mixed>
 * @phpstan-ignore complexity.classLike
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

            $itemValidator = null;

            foreach ($items as $index => $item) {
                $itemData = $isScalar ? ['_v' => $item] : (is_array($item) ? $item : []);

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
                foreach ((array) $fieldErrors as $fieldError) {
                    $errorValidator->errors()->add($field, (string) $fieldError);
                }
            }

            throw new ValidationException($errorValidator);
        }

        return $topValidator->validated() + $data;
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
