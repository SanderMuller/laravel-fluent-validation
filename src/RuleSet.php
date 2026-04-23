<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use Illuminate\Validation\ValidationException;
use IteratorAggregate;
use ReflectionProperty;
use SanderMuller\FluentValidation\Exceptions\BatchLimitExceededException;
use SanderMuller\FluentValidation\Internal\BatchLimitRemap;
use SanderMuller\FluentValidation\Internal\ItemErrorCollector;
use SanderMuller\FluentValidation\Internal\ItemRuleCompiler;
use SanderMuller\FluentValidation\Internal\ItemValidator;
use SanderMuller\FluentValidation\Rules\ArrayRule;
use Traversable;

/**
 * @implements Arrayable<string, mixed>
 * @implements IteratorAggregate<string, mixed>
 */
final class RuleSet implements Arrayable, IteratorAggregate
{
    use Conditionable;
    use Macroable;

    /** @var array<string, mixed> */
    private array $fields = [];

    private bool $failOnUnknownFields = false;

    private bool $stopOnFirstFailure = false;

    private ?string $errorBag = null;

    private readonly ItemRuleCompiler $ruleCompiler;

    private readonly ItemErrorCollector $errorCollector;

    public function __construct()
    {
        $this->ruleCompiler = new ItemRuleCompiler();
        $this->errorCollector = new ItemErrorCollector();
    }

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
     * @param  string|list<string>  ...$fields  Pass either as variadic strings
     *                                           (`->only('a', 'b')`) or as a single
     *                                           array (`->only(['a', 'b'])`) — matches
     *                                           Collection::only / Arr::only semantics.
     */
    public function only(string|array ...$fields): self
    {
        $flat = array_merge(...array_map(static fn (string|array $entry): array => is_array($entry) ? $entry : [$entry], $fields));
        $this->fields = array_intersect_key($this->fields, array_flip($flat));

        return $this;
    }

    /** @param  string|list<string>  ...$fields  Accepts variadic strings or a single array (matches `only()`). */
    public function except(string|array ...$fields): self
    {
        $flat = array_merge(...array_map(static fn (string|array $entry): array => is_array($entry) ? $entry : [$entry], $fields));
        $this->fields = array_diff_key($this->fields, array_flip($flat));

        return $this;
    }

    /** Collection-style alias of field(). */
    public function put(string $field, mixed $rule): self
    {
        return $this->field($field, $rule);
    }

    /**
     * Read a single field's stored rule (uncompiled), or `$default` when absent.
     */
    public function get(string $field, mixed $default = null): mixed
    {
        return $this->fields[$field] ?? $default;
    }

    /**
     * Read-modify-write a single field's rule. The stored rule is cloned (when
     * an object) before being passed to the callback, so mutations through
     * chain methods like `->rule(new X)` don't bleed back to prior captures
     * of the original.
     *
     *     $ruleSet->modify('email', fn (FluentRule $rule) => $rule->rule(new AllowedEducationEmail()));
     *
     * Throws when the field is not already in the rule set — use `put()` to
     * add new fields. The throw differentiates `modify` from `put` semantically:
     * silently creating missing keys would conflate the two.
     *
     * @param  \Closure(mixed): mixed  $callback  Receives the clone, returns the replacement rule.
     *
     * @throws \LogicException When `$field` is not in the rule set.
     */
    public function modify(string $field, \Closure $callback): self
    {
        if (! array_key_exists($field, $this->fields)) {
            throw new \LogicException(
                "Field [{$field}] is not in the rule set — use put() to add new fields.",
            );
        }

        $original = $this->fields[$field];
        $clone = is_object($original) ? clone $original : $original;
        $this->fields[$field] = $callback($clone);

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

    /**
     * Route the thrown `ValidationException` into a named error bag.
     *
     * Mirrors `Validator::validateWithBag($name, ...)` — useful when multiple
     * forms share a page and each needs its own error bag so their messages
     * don't collide. The bag only applies to the exception thrown by
     * `validate()` on failure; `check()`'s `Validated` result is unaffected
     * (it never throws, and the `MessageBag` it exposes has no "default" name).
     *
     *     RuleSet::from($rules)->withBag('updatePassword')->validate($input);
     */
    public function withBag(string $name): self
    {
        $this->errorBag = $name;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->flatten();
    }

    /**
     * Collection-style alias of `toArray()`. Catches the muscle-memory
     * `->all()` reach that two devs in one downstream audit hit independently;
     * aliasing is friction-free vs throwing `BadMethodCallException`.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->flatten();
    }

    /**
     * Spread support: `[...$ruleSet, 'extra' => $rule]` works without an
     * explicit `->toArray()` call. Matches the Collection / Arrayable
     * sibling shape.
     *
     * @return Traversable<string, mixed>
     */
    public function getIterator(): Traversable
    {
        yield from $this->flatten();
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
     * Validate and return a Validated result object. Does not throw on failure.
     * Use when you want errors-as-data (import rows, batch jobs, conditional logic).
     *
     *     $result = RuleSet::from($rules)->check($row->toArray());
     *     if ($result->fails()) {
     *         Log::warning('...', $result->errors()->all());
     *         return null;
     *     }
     *     $validated = $result->validated();
     *
     * Uses the full optimization engine: fast-check closures, conditional
     * pre-evaluation, batched DB validation, O(n) wildcard expansion.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     */
    public function check(array $data, array $messages = [], array $attributes = []): Validated
    {
        try {
            $validated = $this->validate($data, $messages, $attributes);

            return new Validated(
                passes: true,
                validated: $validated,
                errors: new MessageBag(),
                validator: Validator::make($data, []),
            );
        } catch (ValidationException $validationException) {
            return new Validated(
                passes: false,
                validated: [],
                errors: $validationException->validator->errors(),
                validator: $validationException->validator,
            );
        }

        // Note: `validate()` below remaps BatchLimitExceededException to
        // ValidationException before it escapes, so `check()` honours its
        // "does not throw on failure" contract across the new exception path.
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
        if ($this->errorBag !== null) {
            // Trap every ValidationException from the inner pipeline and
            // stamp the error bag before rethrowing. Mirrors Laravel's
            // `Validator::validateWithBag`.
            try {
                return $this->runValidateInternal($data, $messages, $attributes);
            } catch (ValidationException $validationException) {
                $validationException->errorBag = $this->errorBag;

                throw $validationException;
            }
        }

        return $this->runValidateInternal($data, $messages, $attributes);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function runValidateInternal(array $data, array $messages, array $attributes): array
    {
        // PHPStan can't trace `BatchLimitExceededException` through the facade
        // chain (Validator::make -> ItemValidator -> ItemRuleCompiler::buildBatchVerifier
        // -> BatchDatabaseChecker::buildVerifier), but the catch is reachable —
        // Phase 2 tests prove it via RuleSet::validate() + hard-cap breach.
        try {
            return $this->validateInternal($data, $messages, $attributes);
        } catch (BatchLimitExceededException $batchLimitExceededException) { // @phpstan-ignore catch.neverThrown
            throw BatchLimitRemap::toValidationException(
                $batchLimitExceededException,
                $batchLimitExceededException->attribute ?? array_key_first($this->fields) ?? 'items',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validateInternal(array $data, array $messages, array $attributes): array
    {
        [$topRules, $wildcardGroups] = $this->separateRules();

        [$ruleMessages, $ruleAttributes] = self::extractMetadata($topRules);
        $messages += $ruleMessages;
        $attributes += $ruleAttributes;

        if ($this->failOnUnknownFields) {
            $this->rejectUnknownFields($data, $topRules, $wildcardGroups, $messages, $attributes);
        }

        if ($wildcardGroups === []) {
            $compiled = self::compile($topRules);

            // Fast-check pass: run compiled closures on top-level fields when
            // all keys are flat (no dots). Dotted keys from children() require
            // nested lookup and validated-data shaping that Laravel provides.
            $hasDottedKey = false;
            foreach (array_keys($compiled) as $key) {
                if (str_contains($key, '.')) {
                    $hasDottedKey = true;
                    break;
                }
            }

            if (! $hasDottedKey) {
                [$fastChecks, $slowRules] = $this->ruleCompiler->buildFastChecks($compiled);

                if ($slowRules === [] && $fastChecks !== []) {
                    $allPass = true;
                    foreach ($fastChecks as $check) {
                        if (! $check($data)) {
                            $allPass = false;
                            break;
                        }
                    }

                    if ($allPass) {
                        /** @var array<string, mixed> */
                        return array_intersect_key($data, $compiled);
                    }
                }
            }

            /** @var array<string, mixed> */
            return Validator::make($data, $compiled, $messages, $attributes)
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
        return (new ItemValidator($stopOnFirstFailure, $this->ruleCompiler, $this->errorCollector))
            ->validate($items, $itemRules, $itemMessages, $itemAttributes, $parent, $isScalar);
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

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, array<mixed>>
     */
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
