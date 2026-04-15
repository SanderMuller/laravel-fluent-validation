<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use Illuminate\Validation\ValidationException;
use ReflectionProperty;
use SanderMuller\FluentValidation\Rules\ArrayRule;

/**
 * @implements Arrayable<string, mixed>
 */
final class RuleSet implements Arrayable
{
    use Conditionable;
    use Macroable;

    /** @var array<string, mixed> */
    private array $fields = [];

    private bool $failOnUnknownFields = false;

    private bool $stopOnFirstFailure = false;

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

    /**
     * Reject input keys that are not present in the rule set.
     * Unknown fields will receive a "prohibited" validation error.
     */
    public function failOnUnknownFields(): self
    {
        $this->failOnUnknownFields = true;

        return $this;
    }

    /**
     * Stop validating remaining fields after the first failure.
     */
    public function stopOnFirstFailure(): self
    {
        $this->stopOnFirstFailure = true;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->flatten();
    }

    /**
     * Dump the compiled rules for debugging and terminate execution.
     */
    public function dd(mixed ...$args): never
    {
        dd($this->dump(), ...$args);
    }

    /**
     * Dump the compiled rules for debugging.
     *
     * @return array{rules: array<string, array<mixed>>, messages: array<string, string>, attributes: array<string, string>}
     */
    public function dump(): array
    {
        $flat = $this->flatten();
        [$messages, $attributes] = self::extractMetadata($flat);

        return [
            'rules' => self::compileToArrays($flat),
            'messages' => $messages,
            'attributes' => $attributes,
        ];
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
     * @param  array<string, mixed>|null  $flatRules  Pre-computed flatten() result
     */
    public function prepare(array $data, ?array $flatRules = null): PreparedRules
    {
        [$expanded, $implicitAttributes] = $this->expand($data, $flatRules);
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
     * Validate data against the rule set with full optimization.
     *
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

        if ($this->failOnUnknownFields) {
            $this->rejectUnknownFields($data, $topRules, $wildcardGroups, $messages, $attributes);
        }

        if ($wildcardGroups === []) {
            /** @var array<string, mixed> */
            return Validator::make($data, self::compile($topRules), $messages, $attributes)
                ->stopOnFirstFailure($this->stopOnFirstFailure)
                ->validate();
        }

        $topValidator = Validator::make($data, self::compile($topRules), $messages, $attributes)
            ->stopOnFirstFailure($this->stopOnFirstFailure);
        if ($topValidator->fails()) {
            throw new ValidationException($topValidator);
        }

        $fallbackResult = null;
        $allErrors = $this->validateWildcardGroups($wildcardGroups, $data, $messages, $attributes, $fallbackResult, $this->stopOnFirstFailure);

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
     * @param  array<string, mixed>|null  $flatRules  Pre-computed flatten() result to avoid re-processing
     * @return array{0: array<string, mixed>, 1: array<string, array<string, mixed>>}
     */
    private function separateRules(?array $flatRules = null): array
    {
        $flat = $flatRules ?? $this->flatten();
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
        array  $wildcardGroups,
        array  $data,
        array  $messages,
        array  $attributes,
        ?array &$fallbackResult = null,
        bool   $stopOnFirstFailure = false,
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

            $groupErrors = $this->validateItems($items, $itemRules, $itemMessages, $itemAttributes, $parent, $isScalar, $stopOnFirstFailure);
            $allErrors += $groupErrors;

            if ($stopOnFirstFailure && $allErrors !== []) {
                return $allErrors;
            }
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
    private function validateItems(array $items, array $itemRules, array $itemMessages, array $itemAttributes, string $parent, bool $isScalar, bool $stopOnFirstFailure = false): array
    {
        $conditionalFields = $this->analyzeConditionals($itemRules);

        $dispatchField = $this->findCommonDispatchField($conditionalFields);
        /** @var array<string, array<string, mixed>> $rulesByDispatch */
        $rulesByDispatch = [];
        /** @var array<string, array{0: array<string, \Closure(array<string, mixed>): bool>, 1: array<string, mixed>}> $fastChecksByDispatch */
        $fastChecksByDispatch = [];

        [$fastChecks, $originalSlowRules] = $this->buildFastChecks($itemRules);
        /** @var array<string, \Illuminate\Validation\Validator> $validatorCache */
        $validatorCache = [];
        /** @var array<string, list<string>> $errors */
        $errors = [];

        // Batch database validation: for rules without conditionals, pre-query
        // all exists/unique values in one shot and set a precomputed verifier
        // on per-item validators so they skip individual DB queries.
        $batchVerifier = null;
        if ($conditionalFields === [] && BatchDatabaseChecker::isAvailable()) {
            $batchVerifier = $this->buildBatchVerifier($originalSlowRules, $items, $isScalar);
        }

        foreach ($items as $index => $item) {
            /** @var array<string, mixed> $itemData */
            $itemData = $isScalar ? ['_v' => $item] : (is_array($item) ? $item : []);

            // Get effective rules — use dispatch cache if available.
            if ($dispatchField !== null) {
                $rawDispatch = $itemData[$dispatchField] ?? '';
                $dispatchValue = is_scalar($rawDispatch) ? (string) $rawDispatch : '';

                if (! isset($rulesByDispatch[$dispatchValue])) {
                    $rulesByDispatch[$dispatchValue] = $this->reduceRulesForItem($itemRules, $itemData, $conditionalFields);
                    // Build fast checks for the stripped (conditional-free) rules.
                    $fastChecksByDispatch[$dispatchValue] = $this->buildFastChecks($rulesByDispatch[$dispatchValue]);
                }

                $effectiveRules = $rulesByDispatch[$dispatchValue];
                [$dispatchFastChecks, $dispatchSlowRules] = $fastChecksByDispatch[$dispatchValue];
            } elseif ($conditionalFields !== []) {
                $effectiveRules = $this->reduceRulesForItem($itemRules, $itemData, $conditionalFields);
                [$dispatchFastChecks, $dispatchSlowRules] = $this->buildFastChecks($effectiveRules);
            } else {
                $effectiveRules = $itemRules;
                $dispatchFastChecks = $fastChecks;
                $dispatchSlowRules = $originalSlowRules;
            }

            if ($dispatchFastChecks !== []) {
                $fastPass = $this->passesAllFastChecks(array_values($dispatchFastChecks), $itemData);

                if ($fastPass && $dispatchSlowRules === []) {
                    continue;
                }

                if ($fastPass) {
                    $reducedSlowRules = $dispatchSlowRules;

                    if ($reducedSlowRules === []) {
                        continue;
                    }

                    $cacheKey = $this->ruleCacheKey($reducedSlowRules);

                    if (! isset($validatorCache[$cacheKey])) {
                        $validatorCache[$cacheKey] = Validator::make($itemData, $reducedSlowRules, $itemMessages, $itemAttributes);

                        if ($batchVerifier instanceof PrecomputedPresenceVerifier) {
                            $validatorCache[$cacheKey]->setPresenceVerifier($batchVerifier);
                        }
                    } else {
                        $validatorCache[$cacheKey]->setData($itemData);
                    }

                    if (! $validatorCache[$cacheKey]->passes()) {
                        $this->collectErrors($validatorCache[$cacheKey], $parent, $index, $isScalar, $errors);

                        if ($stopOnFirstFailure) {
                            return $errors;
                        }
                    }

                    continue;
                }
            }

            $cacheKey = $this->ruleCacheKey($effectiveRules);

            if (! isset($validatorCache[$cacheKey])) {
                $validatorCache[$cacheKey] = Validator::make($itemData, $effectiveRules, $itemMessages, $itemAttributes);

                if ($batchVerifier instanceof PrecomputedPresenceVerifier) {
                    $validatorCache[$cacheKey]->setPresenceVerifier($batchVerifier);
                }
            } else {
                $validatorCache[$cacheKey]->setData($itemData);
            }

            if (! $validatorCache[$cacheKey]->passes()) {
                $this->collectErrors($validatorCache[$cacheKey], $parent, $index, $isScalar, $errors);

                if ($stopOnFirstFailure) {
                    return $errors;
                }
            }
        }

        return $errors;
    }

    /**
     * Analyze conditional rules (exclude_unless/exclude_if) in item rules.
     * Returns a map of field → condition info for fast per-item evaluation.
     *
     * @param  array<string, mixed>  $itemRules
     * @return array<string, array{action: string, field: string, values: list<string>}>
     */
    private function analyzeConditionals(array $itemRules): array
    {
        $conditionals = [];

        foreach ($itemRules as $field => $rules) {
            if (! is_array($rules)) {
                continue;
            }

            foreach ($rules as $rule) {
                if (is_array($rule) && count($rule) >= 3
                    && ($rule[0] === 'exclude_unless' || $rule[0] === 'exclude_if')
                    && is_string($rule[1])) {
                    $conditionals[$field] = [
                        'action' => $rule[0],
                        'field' => $rule[1],
                        'values' => array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', array_values(array_slice($rule, 2))),
                    ];
                    break;
                }
            }
        }

        return $conditionals;
    }

    /**
     * Reduce item rules by evaluating conditional exclusions against the item data.
     *
     * @param  array<string, mixed>  $itemRules
     * @param  array<string, mixed>  $itemData
     * @param  array<string, array{action: string, field: string, values: list<string>}>  $conditionalFields
     * @return array<string, mixed>
     */
    private function reduceRulesForItem(array $itemRules, array $itemData, array $conditionalFields): array
    {
        foreach ($conditionalFields as $field => $condition) {
            $rawValue = $itemData[$condition['field']] ?? '';
            $actualValue = is_scalar($rawValue) ? (string) $rawValue : '';

            $shouldExclude = ($condition['action'] === 'exclude_unless' && ! in_array($actualValue, $condition['values'], true))
                || ($condition['action'] === 'exclude_if' && in_array($actualValue, $condition['values'], true));

            if ($shouldExclude) {
                unset($itemRules[$field]);
            } else {
                // Condition matched — strip the conditional tuple so only the
                // actual validation rules remain. This enables fast-checking.
                $itemRules[$field] = $this->stripConditionalTuples($itemRules[$field]);
            }
        }

        return $itemRules;
    }

    /**
     * Strip exclude_unless/exclude_if tuples from a rule array, leaving
     * only the actual validation rules. Joins remaining strings into a
     * pipe-delimited string when possible.
     */
    private function stripConditionalTuples(mixed $rules): mixed
    {
        if (! is_array($rules)) {
            return $rules;
        }

        $stripped = [];

        foreach ($rules as $rule) {
            if (is_array($rule) && isset($rule[0]) && is_string($rule[0])
                && in_array($rule[0], ['exclude_unless', 'exclude_if', 'required_if', 'required_unless'], true)) {
                continue;
            }

            // Stringify Stringable objects (Rule::in, Rule::notIn) so the
            // result can be fast-checked as a pipe-joined string.
            $stripped[] = $rule instanceof \Stringable ? (string) $rule : $rule;
        }

        // If all remaining rules are strings, join them for faster parsing.
        $allStrings = true;
        foreach ($stripped as $rule) {
            if (! is_string($rule)) {
                $allStrings = false;
                break;
            }
        }

        if ($allStrings && $stripped !== []) {
            return implode('|', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $stripped));
        }

        return $stripped;
    }

    /**
     * Find a common dispatch field if ALL conditionals reference the same field.
     * Returns the field name (e.g., "type") or null if conditions reference
     * different fields or there are no conditionals.
     *
     * @param  array<string, array{action: string, field: string, values: list<string>}>  $conditionalFields
     */
    private function findCommonDispatchField(array $conditionalFields): ?string
    {
        if ($conditionalFields === []) {
            return null;
        }

        $field = null;

        foreach ($conditionalFields as $condition) {
            if ($field === null) {
                $field = $condition['field'];
            } elseif ($field !== $condition['field']) {
                return null; // Multiple fields — can't dispatch.
            }
        }

        return $field;
    }

    /**
     * Generate a cache key for a rule set to reuse validators.
     *
     * @param  array<string, mixed>  $rules
     */
    private function ruleCacheKey(array $rules): string
    {
        return implode(',', array_keys($rules));
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
     * Reject input keys not covered by any rule in the set.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $topRules
     * @param  array<string, array<string, mixed>>  $wildcardGroups
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     *
     * @throws ValidationException
     */
    private function rejectUnknownFields(array $data, array $topRules, array $wildcardGroups, array $messages, array $attributes): void
    {
        $allowedKeys = array_keys($topRules);

        foreach ($wildcardGroups as $parent => $children) {
            $allowedKeys[] = $parent;

            foreach (array_keys($children) as $child) {
                $allowedKeys[] = $child === '*'
                    ? $parent . '.*'
                    : $parent . '.*.' . $child;
            }
        }

        $unknownKeys = [];

        foreach (array_keys(Arr::dot($data)) as $inputKey) {
            if (! $this->isKnownField($inputKey, $allowedKeys)) {
                $unknownKeys[$inputKey] = 'prohibited';
            }
        }

        if ($unknownKeys !== []) {
            Validator::make($data, $unknownKeys, $messages, $attributes)->validate();
        }
    }

    /**
     * Check if an input key matches any allowed rule key, including wildcard patterns.
     *
     * @param  list<string>  $allowedKeys
     */
    private function isKnownField(string $inputKey, array $allowedKeys): bool
    {
        foreach ($allowedKeys as $ruleKey) {
            if ($ruleKey === $inputKey) {
                return true;
            }

            if (str_contains($ruleKey, '*')) {
                $pattern = '/^' . str_replace('\*', '[^.]+', preg_quote($ruleKey, '/')) . '$/';

                if (preg_match($pattern, $inputKey) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param  array<string, list<string>>  $errors */
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

            if (! $valueCheck instanceof \Closure) {
                $slowRules[$field] = $rule;

                continue;
            }

            // Nested wildcard field (e.g., options.*.label): expand and check each item
            if (str_contains($field, '*.')) {
                $parts = explode('.*.', $field, 2);
                $parentField = $parts[0];
                $childField = $parts[1];

                $checks[] = static function (array $data) use ($parentField, $childField, $valueCheck): bool {
                    $items = $data[$parentField] ?? null;
                    if (! is_array($items)) {
                        return true; // No items to validate
                    }

                    foreach ($items as $item) {
                        if (! is_array($item)) {
                            return false;
                        }

                        if (! $valueCheck($item[$childField] ?? null)) {
                            return false;
                        }
                    }

                    return true;
                };
            } elseif ($field === '*') {
                // Scalar each: value is in '_v' key
                $checks[] = static fn (array $data): bool => $valueCheck($data['_v'] ?? null);
            } else {
                $checks[] = static fn (array $data): bool => $valueCheck($data[$field] ?? null);
            }
        }

        return [$checks, $slowRules];
    }

    /**
     * Build a PrecomputedPresenceVerifier by batching all exists/unique values
     * from slow rules across all items in a single whereIn query.
     *
     * @param  array<string, mixed>  $slowRules
     * @param  array<int|string, mixed>  $items
     */
    private function buildBatchVerifier(array $slowRules, array $items, bool $isScalar): ?PrecomputedPresenceVerifier
    {
        $batchableFields = BatchDatabaseChecker::findBatchableRules($slowRules);

        if ($batchableFields === []) {
            return null;
        }

        $groups = BatchDatabaseChecker::collectValues($batchableFields, $items, $isScalar);

        if ($groups === []) {
            return null;
        }

        return BatchDatabaseChecker::buildVerifier($groups);
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
     * @param  array<string, mixed>|null  $flatRules  Pre-computed flatten() result
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>}
     */
    public function expand(array $data, ?array $flatRules = null): array
    {
        $flatRules ??= $this->flatten();
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
     * Compile rules to array format, guaranteed to return arrays per field.
     * Useful when passing rules to APIs that expect array<string, array<mixed>>
     * (e.g., Livewire's $this->validate()).
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, array<mixed>>
     */
    /**
     * Compile rules and extract labels/messages for use with Livewire's validate().
     * Returns [rules, messages, attributes] matching validate()'s parameter order.
     *
     * Usage in Filament components:
     *   [$rules, $messages, $attributes] = RuleSet::compileWithMetadata($this->rules());
     *   $this->validate($rules, $messages, $attributes);
     *
     * @param  array<string, mixed>  $rules
     * @return array{0: array<string, mixed>, 1: array<string, string>, 2: array<string, string>}
     */
    public static function compileWithMetadata(array $rules): array
    {
        $ruleSet = self::from($rules);
        $flattened = $ruleSet->flattenRules();
        [$messages, $attributes] = self::extractMetadata($flattened);

        return [self::compile($flattened), $messages, $attributes];
    }

    public static function compileToArrays(array $rules): array
    {
        $compiled = self::compile($rules);

        /** @var array<string, array<mixed>> $result */
        $result = [];

        foreach ($compiled as $field => $rule) {
            if (is_string($rule)) {
                $result[$field] = explode('|', $rule);
            } elseif (is_array($rule)) {
                $result[$field] = $rule;
            } else {
                $result[$field] = [$rule];
            }
        }

        return $result;
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

    /**
     * Flatten rules with wildcard keys preserved (e.g. items.*.name).
     * Unlike prepare(), this does NOT expand wildcards against data.
     *
     * @return array<string, mixed>
     */
    public function flattenRules(): array
    {
        return $this->flatten();
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
