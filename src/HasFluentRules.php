<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Closure;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Validator;
use ReflectionObject;
use ReflectionProperty;
use SanderMuller\FluentValidation\Exceptions\BatchLimitExceededException;
use SanderMuller\FluentValidation\Internal\BatchLimitRemap;

/**
 * Add this trait to a FormRequest to enable FluentRule features:
 * each()/children() flattening, wildcard expansion, label/message
 * extraction, rule compilation, implicit attribute mapping, and
 * per-attribute fast-check optimization for wildcard rules.
 *
 *     class StorePostRequest extends FormRequest
 *     {
 *         use HasFluentRules;
 *
 *         public function rules(): array
 *         {
 *             return [
 *                 'name'  => FluentRule::string('Full Name')->required()->max(255),
 *                 'items' => FluentRule::array()->required()->each([
 *                     'name' => FluentRule::string()->required(),
 *                 ]),
 *             ];
 *         }
 *     }
 */
trait HasFluentRules
{
    protected function createDefaultValidator(ValidationFactory $factory): Validator
    {
        /** @var array<string, mixed>|RuleSet $rules */
        $rules = method_exists($this, 'rules') // @phpstan-ignore function.alreadyNarrowedType
            ? $this->container->call([$this, 'rules'])
            : [];

        /** @var array<string, mixed> $data */
        $data = $this->validationData();

        // Auto-unwrap: rules() may return either a plain array or a RuleSet
        // (the latter pattern lets callers chain ->only/->except/->merge
        // before returning, eliminating a terminal ->toArray() call).
        $ruleSet = $rules instanceof RuleSet ? $rules : RuleSet::from($rules);
        $prepared = $ruleSet->prepare($data);

        // Pre-exclude rules whose exclude_unless/exclude_if conditions
        // don't match the actual data. This happens BEFORE the validator
        // constructor, so excluded rules are never parsed.
        $preparedRules = $this->preExcludeRules($prepared->rules, $data);

        [$fastChecks, $attributePatternMap] = $this->buildFastCheckMaps($prepared, $preparedRules);

        $messages = $this->messages() + $prepared->messages;
        $attributes = $this->attributes() + $prepared->attributes;

        // Only use OptimizedValidator when there are fast-checkable wildcard
        // rules or conditional rules were pre-excluded.
        if ($fastChecks !== [] || count($preparedRules) < count($prepared->rules)) {
            $validator = $this->makeOptimizedValidator($factory, $data, $preparedRules, $messages, $attributes);
            $validator->withFastChecks($fastChecks, $attributePatternMap);
        } else {
            /** @var Validator $validator */
            $validator = $factory->make($data, $preparedRules, $messages, $attributes);
        }

        if ($prepared->implicitAttributes !== []) {
            (new ReflectionProperty(Validator::class, 'implicitAttributes'))
                ->setValue($validator, $prepared->implicitAttributes);
        }

        $validator->stopOnFirstFailure($this->stopOnFirstFailure);

        // Batch exists/unique queries for wildcard-expanded attributes only.
        // Non-wildcard rules are left to the original verifier to avoid
        // interfering with scoped exists/unique checks on single fields.
        if ($prepared->implicitAttributes !== [] && BatchDatabaseChecker::isAvailable()) {
            $wildcardAttributes = array_merge(...array_values($prepared->implicitAttributes));

            try {
                $batchVerifier = $this->buildFormRequestBatchVerifier($preparedRules, $data, $wildcardAttributes);
            } catch (BatchLimitExceededException $batchLimitExceededException) {
                throw BatchLimitRemap::toValidationException(
                    $batchLimitExceededException,
                    $wildcardAttributes[0] ?? 'items',
                );
            }

            if ($batchVerifier !== null) {
                $validator->setPresenceVerifier($batchVerifier);
            }
        }

        if ($this->isPrecognitive()) {
            $validator->setRules(
                $this->filterPrecognitiveRules($validator->getRulesWithoutPlaceholders())
            );
        }

        return $validator;
    }

    /**
     * Build fast-check closures and the attribute-to-pattern lookup map.
     *
     * @param  array<string, mixed>  $preparedRules  Rules after pre-exclusion
     * @return array{0: array<string, Closure(mixed): bool>, 1: array<string, string>}
     */
    private function buildFastCheckMaps(PreparedRules $prepared, array $preparedRules): array
    {
        $wildcardRules = [];
        foreach ($prepared->implicitAttributes as $pattern => $expandedPaths) {
            if (isset($preparedRules[$expandedPaths[0] ?? ''])) {
                $wildcardRules[$pattern] = $preparedRules[$expandedPaths[0]];
            }
        }

        $fastChecks = OptimizedValidator::buildFastChecks($wildcardRules);

        return [$fastChecks, $this->buildAttributePatternMap($fastChecks, $prepared->implicitAttributes, $preparedRules)];
    }

    /**
     * @param array<string, Closure(mixed): bool> $fastChecks
     * @param  array<string, list<string>>  $implicitAttributes
     * @param  array<string, mixed>  $preparedRules
     * @return array<string, string>
     */
    private function buildAttributePatternMap(array $fastChecks, array $implicitAttributes, array $preparedRules): array
    {
        $map = [];

        foreach ($implicitAttributes as $pattern => $expandedPaths) {
            if (isset($fastChecks[$pattern])) {
                foreach ($expandedPaths as $path) {
                    if (isset($preparedRules[$path])) {
                        $map[$path] = $pattern;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * Build a PrecomputedPresenceVerifier by scanning prepared rules for
     * batchable Exists/Unique objects and collecting their values from
     * the expanded attribute paths.
     *
     * Guards the collector against two hostile-input vectors before any DB
     * query fires:
     *
     * - Parent-max short-circuit: for each concrete wildcard attribute,
     *   derive the parent array path, look up its `max:N` rule (string or
     *   array form), and compare the actual item count. If the parent is
     *   over budget, throw `BatchLimitExceededException(reason='parent-max')`
     *   — the trait-level catch remaps it to `ValidationException` so
     *   callers see the package's standard exception type. Note: because
     *   the throw happens in `createDefaultValidator()`, `failedValidation()`
     *   is NOT invoked (the validator is never built).
     * - Hard cap (Phase 2): enforced inside `BatchDatabaseChecker::buildVerifier`.
     *
     * @param  array<string, mixed>  $preparedRules  Full prepared rules (NOT pre-intersected).
     * @param  array<string, mixed>  $data
     * @param  list<string>  $wildcardAttributes  Concrete expanded wildcard paths (e.g. `items.0.id`).
     *
     * @throws BatchLimitExceededException
     */
    private function buildFormRequestBatchVerifier(array $preparedRules, array $data, array $wildcardAttributes): ?PrecomputedPresenceVerifier
    {
        $wildcardRules = array_intersect_key($preparedRules, array_flip($wildcardAttributes));

        $this->assertParentArraysWithinMax($wildcardRules, $preparedRules, $data);

        $groups = BatchDatabaseChecker::collectExpandedValues($wildcardRules, $data);

        if ($groups === []) {
            return null;
        }

        return BatchDatabaseChecker::buildVerifier($groups);
    }

    /**
     * Inspect parent array rules for `max:N` and short-circuit before any
     * DB query if any concrete wildcard attribute's parent overflows.
     *
     * Only runs for wildcard attributes that actually carry a batchable
     * Exists / Unique rule — no point refusing to query when we weren't
     * going to query anyway.
     *
     * @param  array<string, mixed>  $wildcardRules  Rules keyed by concrete wildcard path.
     * @param  array<string, mixed>  $preparedRules  Full rules, needed to find parent rule strings.
     * @param  array<string, mixed>  $data
     *
     * @throws BatchLimitExceededException
     */
    private function assertParentArraysWithinMax(array $wildcardRules, array $preparedRules, array $data): void
    {
        /** @var array<string, int> $cache  Parent path → actual item count */
        $cache = [];

        foreach ($wildcardRules as $attribute => $attributeRules) {
            if (! is_array($attributeRules)) {
                continue;
            }

            $rule = null;
            foreach ($attributeRules as $candidate) {
                if ($candidate instanceof Exists
                    || $candidate instanceof Unique) {
                    if (! BatchDatabaseChecker::isBatchable($candidate)) {
                        continue;
                    }

                    $rule = $candidate;
                    break;
                }
            }

            if ($rule === null) {
                continue;
            }

            $parent = $this->deriveParentPath((string) $attribute);

            if ($parent === null) {
                continue;
            }

            $max = $this->extractParentMax($preparedRules[$parent] ?? null);

            if ($max === null) {
                continue;
            }

            if (! isset($cache[$parent])) {
                $items = data_get($data, $parent);
                $cache[$parent] = is_countable($items) ? count($items) : 0;
            }

            $count = $cache[$parent];

            if ($count <= $max) {
                continue;
            }

            $table = BatchDatabaseChecker::getVerifierTable($rule) ?? '';
            $column = BatchDatabaseChecker::getVerifierColumn($rule) ?? '';
            $ruleType = $rule instanceof Unique ? 'unique' : 'exists';

            throw new BatchLimitExceededException(
                table: $table,
                column: $column,
                ruleType: $ruleType,
                reason: BatchLimitExceededException::REASON_PARENT_MAX,
                valueCount: $count,
                limit: $max,
                attribute: $parent,
            );
        }
    }

    /**
     * Derive the parent array path from a concrete wildcard attribute. Handles
     * both associative (`orders.0.items.0.id` → `orders.0.items`) and scalar
     * (`items.0` → `items`) wildcard shapes — where "associative" here means
     * record-like child keys on numerically-indexed parents.
     *
     * **Known limitation:** when wildcards expand against a PHP-associative
     * array (string keys, e.g. `{"items": {"foo": {...}}}`) the expanded
     * attribute is `items.foo.id` which does not match this regex. The
     * parent-max short-circuit silently skips the attribute and the hard cap
     * in `BatchDatabaseChecker::$maxValuesPerGroup` remains the only guard.
     * Fixing this requires pattern-aware derivation (`$implicitAttributes`);
     * deferred until a real use-case surfaces.
     */
    private function deriveParentPath(string $attribute): ?string
    {
        $parent = (string) preg_replace('/\.\d+(?:\.[^.]+)?$/', '', $attribute, 1, $count);

        if ($count === 0 || $parent === '' || $parent === $attribute) {
            return null;
        }

        return $parent;
    }

    /**
     * Parse `max:N` from a parent rule in either string (`'required|array|max:100'`)
     * or array (`['required', 'array', 'max:100', ...]`) form. Returns null when
     * no `max:` directive is present.
     */
    private function extractParentMax(mixed $parentRule): ?int
    {
        if ($parentRule === null) {
            return null;
        }

        $tokens = [];

        if (is_string($parentRule)) {
            $tokens = explode('|', $parentRule);
        } elseif (is_array($parentRule)) {
            foreach ($parentRule as $entry) {
                if (is_string($entry)) {
                    $tokens[] = $entry;
                }
            }
        } else {
            return null;
        }

        foreach ($tokens as $token) {
            if (preg_match('/^max:(\d+)$/', $token, $m) === 1) {
                return (int) $m[1];
            }
        }

        return null;
    }

    /**
     * Pre-exclude rules whose exclude_unless/exclude_if conditions
     * don't match the actual data. Operates on expanded rules (concrete
     * paths like interactions.5.style.top) before they reach the validator.
     *
     * @param  array<string, mixed>  $rules
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function preExcludeRules(array $rules, array $data): array
    {
        /** @var array<string, string> $cache */
        $cache = [];

        foreach ($rules as $attribute => $ruleSet) {
            if (! is_array($ruleSet)) {
                continue;
            }

            foreach ($ruleSet as $rule) {
                if (! is_array($rule)) {
                    continue;
                }

                if (count($rule) < 3) {
                    continue;
                }

                $action = $rule[0];

                if ($action !== 'exclude_unless' && $action !== 'exclude_if') {
                    continue;
                }

                /** @var string $conditionField */
                $conditionField = $rule[1];
                /** @var list<string> $allowedValues */
                $allowedValues = array_slice($rule, 2);

                // Resolve wildcard: interactions.*.type → interactions.5.type
                if (str_contains($conditionField, '*')) {
                    preg_match_all('/\.(\d+)(?:\.|$)/', (string) $attribute, $m);
                    /** @var list<numeric-string> $indices */
                    $indices = $m[1];
                    $idx = 0;
                    $conditionField = (string) preg_replace_callback('/\*/', static function () use ($indices, &$idx): string {
                        return $indices[$idx++] ?? '*';
                    }, $conditionField);
                }

                if (! isset($cache[$conditionField])) {
                    $rawValue = data_get($data, $conditionField);
                    $cache[$conditionField] = is_scalar($rawValue) ? (string) $rawValue : '';
                }

                $actual = $cache[$conditionField];

                $shouldExclude = ($action === 'exclude_unless' && ! in_array($actual, $allowedValues, true))
                    || ($action === 'exclude_if' && in_array($actual, $allowedValues, true));

                if ($shouldExclude) {
                    unset($rules[$attribute]);
                    break;
                }
            }
        }

        return $rules;
    }

    /**
     * Create an OptimizedValidator with the same setup the factory provides
     * (extensions, container, presence verifier, excludeUnvalidatedArrayKeys)
     * without mutating the shared factory's resolver. Octane-safe.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     */
    private function makeOptimizedValidator(
        ValidationFactory $factory,
        array $data,
        array $rules,
        array $messages,
        array $attributes,
    ): OptimizedValidator {
        // Create a standard Validator through the factory to get full setup,
        // then transfer its configuration to an OptimizedValidator.
        /** @var Validator $base */
        $base = $factory->make($data, $rules, $messages, $attributes);

        // Create with EMPTY rules to skip re-parsing the 3500+ expanded rules.
        // The parsed rules are copied from the base validator below.
        $optimized = new OptimizedValidator(
            $base->getTranslator(),
            $data,
            [],
            $messages,
            $attributes,
        );

        // Copy parsed rules AND factory-applied configuration from the base.
        $ref = new ReflectionObject($base);
        foreach (['rules', 'initialRules', 'container', 'presenceVerifier', 'excludeUnvalidatedArrayKeys', 'extensions', 'implicitExtensions', 'dependentExtensions', 'replacers', 'fallbackMessages'] as $prop) {
            if ($ref->hasProperty($prop)) {
                $p = $ref->getProperty($prop);
                $value = $p->getValue($base);
                if (! in_array($value, [null, [], false], true)) {
                    $p->setValue($optimized, $value);
                }
            }
        }

        return $optimized;
    }
}
