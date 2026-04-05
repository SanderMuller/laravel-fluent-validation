<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Validation\ValidationException;
use ReflectionProperty;
use SanderMuller\FluentValidation\Rules\ArrayRule;

/**
 * @implements Arrayable<string, mixed>
 */
final class RuleSet implements Arrayable
{
    use Conditionable;

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

    /** @param  self|array<string, mixed>  $rules */
    public function merge(self|array $rules): self
    {
        $this->fields = array_merge(
            $this->fields,
            $rules instanceof self ? $rules->fields : $rules,
        );

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->flatten();
    }

    /**
     * Prepare rules for a Validator in one call. Handles flatten, expand,
     * extract metadata, and compile in the correct order.
     *
     * Designed for custom Validator subclasses:
     *
     *     $p = RuleSet::from($rules)->prepare($data);
     *     parent::__construct($translator, $data, $p->rules, $p->messages, $p->attributes);
     *
     * @param  array<string, mixed>  $data
     */
    public function prepare(array $data): PreparedRules
    {
        [$expanded, $implicitAttributes] = $this->expand($data);
        [$messages, $attributes] = self::extractMetadata($expanded);

        return new PreparedRules(
            rules: self::compile($expanded),
            messages: $messages,
            attributes: $attributes,
            implicitAttributes: $implicitAttributes,
        );
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
        [$topRules, $wildcardGroups] = $this->separateRules();

        [$ruleMessages, $ruleAttributes] = self::extractMetadata($topRules);
        $messages += $ruleMessages;
        $attributes += $ruleAttributes;

        if ($wildcardGroups === []) {
            /** @var array<string, mixed> */
            return Validator::make($data, self::compile($topRules), $messages, $attributes)->validate();
        }

        $topValidator = Validator::make($data, self::compile($topRules), $messages, $attributes);
        if ($topValidator->fails()) {
            throw new ValidationException($topValidator);
        }

        $fallbackResult = null;
        $allErrors = $this->validateWildcardGroups($wildcardGroups, $data, $messages, $attributes, $fallbackResult);

        if ($fallbackResult !== null) {
            return $fallbackResult;
        }

        if ($allErrors !== []) {
            $this->throwValidationErrors($allErrors);
        }

        /** @var array<string, mixed> */
        return $topValidator->validated();
    }

    /**
     * Split flattened rules into top-level rules and wildcard groups.
     *
     * @return array{0: array<string, mixed>, 1: array<string, array<string, mixed>>}
     */
    private function separateRules(): array
    {
        $flat = $this->flatten();
        $topRules = [];
        /** @var array<string, array<string, mixed>> $wildcardGroups */
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

        return [$topRules, $wildcardGroups];
    }

    /**
     * Validate all wildcard groups per-item with fast-check optimization.
     *
     * @param  array<string, array<string, mixed>>  $wildcardGroups
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     * @return array<string, list<string>>
     *
     * @throws ValidationException
     */
    /**
     * @param  array<string, array<string, mixed>>  $wildcardGroups
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     * @param  array<string, mixed>|null  $fallbackResult  Set when full expansion fallback is used
     * @return array<string, list<string>>
     *
     * @throws ValidationException
     */
    private function validateWildcardGroups(
        array $wildcardGroups,
        array $data,
        array $messages,
        array $attributes,
        ?array &$fallbackResult = null,
    ): array {
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
            $rawItemRules = $isScalar
                ? ['_v' => $groupRules['*']]
                : $this->rewriteRulesForPerItem($groupRules, $parent);

            [$itemMessages, $itemAttributes] = self::extractMetadata($rawItemRules);
            $itemMessages = $messages + $itemMessages;
            $itemAttributes = $attributes + $itemAttributes;
            $itemRules = self::compile($rawItemRules);

            if ($this->requiresFullExpansion($itemRules)) {
                $fallbackResult = $this->validateStandard($data, $messages, $attributes);

                return [];
            }

            $groupErrors = $this->validateItems($items, $itemRules, $itemMessages, $itemAttributes, $parent, $isScalar);
            $allErrors += $groupErrors;
        }

        return $allErrors;
    }

    /**
     * Validate individual items in a wildcard group.
     *
     * @param  array<int|string, mixed>  $items
     * @param  array<string, mixed>  $itemRules
     * @param  array<string, string>  $itemMessages
     * @param  array<string, string>  $itemAttributes
     * @return array<string, list<string>>
     */
    private function validateItems(array $items, array $itemRules, array $itemMessages, array $itemAttributes, string $parent, bool $isScalar): array
    {
        [$fastChecks, $slowRules] = $this->buildFastChecks($itemRules);
        $allFast = $slowRules === [];
        $itemValidator = null;
        $slowValidator = null;
        /** @var array<string, list<string>> $errors */
        $errors = [];

        foreach ($items as $index => $item) {
            /** @var array<string, mixed> $itemData */
            $itemData = $isScalar ? ['_v' => $item] : (is_array($item) ? $item : []);

            if ($fastChecks !== []) {
                $fastPass = $this->passesAllFastChecks($fastChecks, $itemData);

                if ($fastPass && $allFast) {
                    continue; // All fields fast-checked and passed — skip Laravel entirely.
                }

                if ($fastPass && $slowRules !== []) {
                    // Fast fields passed — only validate slow fields via Laravel.
                    if ($slowValidator === null) {
                        $slowValidator = Validator::make($itemData, $slowRules, $itemMessages, $itemAttributes);
                    } else {
                        $slowValidator->setData($itemData);
                    }

                    if (! $slowValidator->passes()) {
                        $this->collectErrors($slowValidator, $parent, $index, $isScalar, $errors);
                    }

                    continue;
                }
            }

            // No fast-checks, or a fast-check failed — validate all fields for proper error messages.
            if ($itemValidator === null) {
                $itemValidator = Validator::make($itemData, $itemRules, $itemMessages, $itemAttributes);
            } else {
                $itemValidator->setData($itemData);
            }

            if (! $itemValidator->passes()) {
                $this->collectErrors($itemValidator, $parent, $index, $isScalar, $errors);
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function collectErrors(\Illuminate\Validation\Validator $validator, string $parent, int|string $index, bool $isScalar, array &$errors): void
    {
        /** @var array<string, list<string>> $itemErrors */
        $itemErrors = $validator->errors()->toArray();
        foreach ($itemErrors as $field => $fieldErrors) {
            $fullPath = $isScalar ? "{$parent}.{$index}" : "{$parent}.{$index}.{$field}";
            $errors[$fullPath] = $fieldErrors;
        }
    }

    /**
     * @param  list<\Closure(array<string, mixed>): bool>  $fastChecks
     * @param  array<string, mixed>  $itemData
     */
    private function passesAllFastChecks(array $fastChecks, array $itemData): bool
    {
        foreach ($fastChecks as $fastCheck) {
            if (! $fastCheck($itemData)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, list<string>>  $errors
     *
     * @throws ValidationException
     */
    private function throwValidationErrors(array $errors): never
    {
        $errorValidator = Validator::make([], []);
        foreach ($errors as $field => $fieldErrors) {
            foreach ($fieldErrors as $fieldError) {
                $errorValidator->errors()->add($field, $fieldError);
            }
        }

        throw new ValidationException($errorValidator);
    }

    /**
     * Build fast-check closures for eligible fields.
     * Returns fast checks for compilable fields and the remaining slow rules.
     *
     * @param  array<string, mixed>  $compiledRules
     * @return array{0: list<\Closure(array<string, mixed>): bool>, 1: array<string, mixed>}
     */
    private function buildFastChecks(array $compiledRules): array
    {
        $checks = [];
        $slowRules = [];

        foreach ($compiledRules as $field => $rule) {
            if (! is_string($rule)) {
                $slowRules[$field] = $rule;

                continue;
            }

            $valueCheck = FastCheckCompiler::compile($rule);

            if ($valueCheck instanceof \Closure) {
                $checks[] = static fn (array $data): bool => $valueCheck($data[$field] ?? null);
            } else {
                $slowRules[$field] = $rule;
            }
        }

        return [$checks, $slowRules];
    }

    /**
     * Rewrite conditional rule references from wildcard paths to relative paths
     * so per-item validation works. Transforms:
     *   ['exclude_unless', 'items.*.type', 'chapter'] → ['exclude_unless', 'type', 'chapter']
     *   'gte:items.*.start_time' → 'gte:start_time'
     *
     * @param  array<string, mixed>  $groupRules
     * @return array<string, mixed>
     */
    private function rewriteRulesForPerItem(array $groupRules, string $parent): array
    {
        $prefix = $parent . '.*.';
        $rewritten = [];

        foreach ($groupRules as $field => $rule) {
            if (! is_array($rule)) {
                $rewritten[$field] = $rule;

                continue;
            }

            $newRules = [];

            foreach ($rule as $r) {
                if (is_array($r) && count($r) >= 2 && is_string($r[1])) {
                    // ['exclude_unless', 'items.*.type', ...] → ['exclude_unless', 'type', ...]
                    $r[1] = $this->stripPrefix($r[1], $prefix);
                    $newRules[] = $r;
                } elseif (is_string($r) && str_contains($r, $prefix)) {
                    // 'gte:items.*.start_time' → 'gte:start_time'
                    $newRules[] = str_replace($prefix, '', $r);
                } else {
                    $newRules[] = $r;
                }
            }

            $rewritten[$field] = $newRules;
        }

        return $rewritten;
    }

    private function stripPrefix(string $value, string $prefix): string
    {
        return str_starts_with($value, $prefix) ? substr($value, strlen($prefix)) : $value;
    }

    /** @param  array<string, mixed>  $compiledRules */
    private function requiresFullExpansion(array $compiledRules): bool
    {
        // Cross-item rules like distinct need full expansion to compare across items.
        // Nested wildcards (chapters.*.title) are fine — the per-item validator
        // handles them within each item's scope.
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

        [$ruleMessages, $ruleAttributes] = self::extractMetadata($rules);
        $messages += $ruleMessages;
        $attributes += $ruleAttributes;

        $validator = Validator::make($data, self::compile($rules), $messages, $attributes);

        if ($implicitAttributes !== []) {
            (new ReflectionProperty($validator, 'implicitAttributes'))
                ->setValue($validator, $implicitAttributes);
        }

        /** @var array<string, mixed> */
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

    /**
     * Extract labels and per-rule messages from rule objects before compilation.
     *
     * @param  array<string, mixed>  $rules
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    public static function extractMetadata(array $rules): array
    {
        $messages = [];
        $attributes = [];

        foreach ($rules as $field => $rule) {
            // For mixed arrays like ['exclude', FluentRule::string('ID')],
            // look inside for rule objects with metadata.
            $objects = is_object($rule) ? [$rule] : (is_array($rule) ? array_filter($rule, is_object(...)) : []);

            foreach ($objects as $object) {
                self::extractObjectMetadata($object, $field, $messages, $attributes);
            }
        }

        return [$messages, $attributes];
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

    /**
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     */
    private static function extractObjectMetadata(object $object, string $field, array &$messages, array &$attributes): void
    {
        if (method_exists($object, 'getLabel')) {
            $label = $object->getLabel();

            if (is_string($label)) {
                $attributes[$field] = $label;
            }
        }

        if (method_exists($object, 'getCustomMessages')) {
            /** @var array<string, string> $customMessages */
            $customMessages = $object->getCustomMessages();
            foreach ($customMessages as $ruleName => $msg) {
                $messages[$ruleName === '' ? $field : $field . '.' . $ruleName] = $msg;
            }
        }
    }

    /** @param  array<string, mixed>  $rules */
    private static function flattenRule(string $prefix, mixed $rule, array &$rules): void
    {
        // Get nested rule definitions if the rule supports them.
        $eachRules = $rule instanceof ArrayRule ? $rule->getEachRules() : null;

        /** @var array<string, mixed>|null $childRules */
        $childRules = is_object($rule) && method_exists($rule, 'getChildRules') ? $rule->getChildRules() : null;

        if ($eachRules === null && $childRules === null) {
            $rules[$prefix] = $rule;

            return;
        }

        // Store the parent rule, stripped of nested definitions to prevent double-validation.
        $rules[$prefix] = $rule instanceof ArrayRule ? $rule->withoutEachRules() : $rule;

        // each() → wildcard paths: items.*.name
        if ($eachRules instanceof ValidationRule) {
            self::flattenRule($prefix . '.*', $eachRules, $rules);
        } elseif (is_array($eachRules)) {
            foreach ($eachRules as $field => $fieldRule) {
                self::flattenRule($prefix . '.*.' . $field, $fieldRule, $rules);
            }
        }

        // children() → fixed paths: search.value, answer.email_address
        foreach ($childRules ?? [] as $field => $fieldRule) {
            self::flattenRule($prefix . '.' . $field, $fieldRule, $rules);
        }
    }
}
