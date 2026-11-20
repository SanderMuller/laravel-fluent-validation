<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as BaseValidator;
use SanderMuller\FluentValidation\BatchDatabaseChecker;
use SanderMuller\FluentValidation\MemoizingValidator;
use SanderMuller\FluentValidation\PrecomputedPresenceVerifier;
use SanderMuller\FluentValidation\PresenceConditionalReducer;
use SanderMuller\FluentValidation\ValueConditionalReducer;

/**
 * Executes the per-item validation loop for a wildcard group. Applies
 * fast-check closures first, falling back to Laravel validators for
 * slow rules. Owns the dispatch cache (reduce rules once per distinct
 * dispatch-value) and the per-rule-shape validator cache (reuse
 * `Validator` instances across items with the same effective rule set).
 *
 * Extracted from `RuleSet::validateItems` in Phase 2b. Instantiated once
 * per wildcard group dispatch — `stopOnFirstFailure` is cooked into the
 * constructor so the loop can bail without checking `$this->ruleSet->…`.
 *
 * @internal
 */
final readonly class ItemValidator
{
    public function __construct(
        private bool $stopOnFirstFailure,
        private ItemRuleCompiler $compiler,
        private ItemErrorCollector $errors,
    ) {}

    /**
     * @param  array<int|string, mixed>  $items
     * @param  array<string, mixed>  $itemRules
     * @param  array<string, string>  $itemMessages
     * @param  array<string, string>  $itemAttributes
     * @param  array<int|string, list<array-key>>  $excludedFields  receives, per item index, the fields an exclude rule removed
     * @return array<string, list<string>>
     */
    public function validate(array $items, array $itemRules, array $itemMessages, array $itemAttributes, string $parent, bool $isScalar, array &$excludedFields = []): array
    {
        $conditionalFields = $this->compiler->analyzeConditionals($itemRules);
        $itemRules = PlainRuleJoiner::joinAll($itemRules, $conditionalFields);
        $siblingFields = PresenceConditionalReducer::fields($itemRules) + ValueConditionalReducer::fields($itemRules);
        $hasSiblingDependentConditionals = $siblingFields !== [];

        $dispatchField = DispatchField::find($this->compiler, $conditionalFields, $itemRules);
        /** @var array<string, array<string, mixed>> $rulesByDispatch */
        $rulesByDispatch = [];
        /** @var array<string, array{0: array<string, Closure(array<string, mixed>): bool>, 1: array<string, mixed>}> $fastChecksByDispatch */
        $fastChecksByDispatch = [];
        /** @var array<string, array{0: array<string, Closure(array<string, mixed>): bool>, 1: array<string, mixed>}> $fastChecksByReduced */
        $fastChecksByReduced = [];
        /** @var array<string, array<string, mixed>> $laravelRulesByDispatch */
        $laravelRulesByDispatch = [];
        /** @var array<string, list<array-key>> $excludedByDispatch */
        $excludedByDispatch = [];

        [$fastChecks, $originalSlowRules] = $this->compiler->buildFastChecks($itemRules);
        $laravelFields = $this->laravelFieldFilter($isScalar, $itemRules);
        /** @var array<string, BaseValidator> $validatorCache */
        $validatorCache = [];
        /** @var array<string, list<string>> $errors */
        $errors = [];

        // Batch database validation: for rules without conditionals, pre-query
        // all exists/unique values in one shot and set a precomputed verifier
        // on per-item validators so they skip individual DB queries. Presence
        // conditionals can drop or rewrite rules per item, so the batched set
        // would no longer match the per-item rule set — disable batching.
        $batchVerifier = null;
        if ($conditionalFields === [] && ! $hasSiblingDependentConditionals && BatchDatabaseChecker::isAvailable()) {
            $batchVerifier = $this->compiler->buildBatchVerifier($originalSlowRules, $items, $isScalar);
        }

        $names = new ItemAttributeNames($isScalar, array_map(strval(...), array_keys($itemRules)), $parent, $itemMessages, $itemAttributes);

        foreach ($items as $index => $item) {
            /** @var array<string, mixed> $itemData */
            $itemData = $isScalar ? ['_v' => $item] : (is_array($item) ? $item : []);

            $dispatchValue = $dispatchField !== null ? DispatchField::key($itemData, $dispatchField) : null;

            if ($dispatchValue !== null) {
                if (! isset($rulesByDispatch[$dispatchValue])) {
                    $rulesByDispatch[$dispatchValue] = $this->compiler->reduceRulesForItem($itemRules, $itemData, $conditionalFields, $itemMessages, $siblingFields);
                    $fastChecksByDispatch[$dispatchValue] = $this->compiler->buildFastChecks($rulesByDispatch[$dispatchValue]);
                }

                $effectiveRules = $rulesByDispatch[$dispatchValue];
                [$dispatchFastChecks, $dispatchSlowRules] = $fastChecksByDispatch[$dispatchValue];
                self::recordExcludedFields($excludedFields, $index, $excludedByDispatch[$dispatchValue] ??= array_keys(array_diff_key($conditionalFields, $effectiveRules)));
            } elseif ($conditionalFields !== [] || $hasSiblingDependentConditionals) {
                $effectiveRules = $this->compiler->reduceRulesForItem($itemRules, $itemData, $conditionalFields, $itemMessages, $siblingFields);
                $reducedKey = $this->compiler->ruleCacheKey($effectiveRules);
                // Memoize compiled fast-checks per distinct reduced rule set —
                // many items reduce identically, so reuse beats recompiling.
                [$dispatchFastChecks, $dispatchSlowRules] = $fastChecksByReduced[$reducedKey]
                    ??= $this->compiler->buildFastChecks($effectiveRules);
                self::recordExcludedFields($excludedFields, $index, array_keys(array_diff_key($conditionalFields, $effectiveRules)));
            } else {
                $effectiveRules = $itemRules;
                $dispatchFastChecks = $fastChecks;
                $dispatchSlowRules = $originalSlowRules;
            }

            if ($dispatchFastChecks !== []) {
                $fastPass = $this->errors->passesAllFastChecks($dispatchFastChecks, $itemData);

                if ($fastPass && $dispatchSlowRules === []) {
                    continue;
                }

                if ($fastPass) {
                    $reducedSlowRules = $this->rulesForLaravel($dispatchSlowRules, $itemRules, $itemData, $conditionalFields, $itemMessages, $siblingFields, $dispatchValue, $laravelRulesByDispatch);

                    if ($reducedSlowRules === []) {
                        continue;
                    }

                    $cacheKey = $this->compiler->ruleCacheKey($reducedSlowRules);

                    if (! isset($validatorCache[$cacheKey])) {
                        $validatorCache[$cacheKey] = $this->makeItemValidator($itemData, $reducedSlowRules, $itemMessages, $itemAttributes);

                        if ($batchVerifier instanceof PrecomputedPresenceVerifier) {
                            $validatorCache[$cacheKey]->setPresenceVerifier($batchVerifier);
                        }
                    } else {
                        $validatorCache[$cacheKey]->setData($itemData);
                    }

                    $names->forItem($validatorCache[$cacheKey], $index, $itemData);

                    if (! $validatorCache[$cacheKey]->passes()) {
                        $this->errors->collectErrors($validatorCache[$cacheKey], $parent, $index, $isScalar, $errors);

                        if ($this->stopOnFirstFailure) {
                            return $errors;
                        }
                    }

                    continue;
                }
            }

            $effectiveRules = $laravelFields($effectiveRules, $dispatchFastChecks, $dispatchSlowRules, $itemData);
            $effectiveRules = $this->rulesForLaravel($effectiveRules, $itemRules, $itemData, $conditionalFields, $itemMessages, $siblingFields, $dispatchValue, $laravelRulesByDispatch);
            $cacheKey = $this->compiler->ruleCacheKey($effectiveRules);

            if (! isset($validatorCache[$cacheKey])) {
                $validatorCache[$cacheKey] = $this->makeItemValidator($itemData, $effectiveRules, $itemMessages, $itemAttributes);

                if ($batchVerifier instanceof PrecomputedPresenceVerifier) {
                    $validatorCache[$cacheKey]->setPresenceVerifier($batchVerifier);
                }
            } else {
                $validatorCache[$cacheKey]->setData($itemData);
            }

            $names->forItem($validatorCache[$cacheKey], $index, $itemData);

            if (! $validatorCache[$cacheKey]->passes()) {
                $this->errors->collectErrors($validatorCache[$cacheKey], $parent, $index, $isScalar, $errors);

                if ($this->stopOnFirstFailure) {
                    return $errors;
                }
            }
        }

        return $errors;
    }

    /**
     * Record the conditional fields that the item's reduced rules no longer
     * hold: an exclude rule removed them.
     *
     * @param  array<int|string, list<array-key>>  $excludedFields
     * @param  list<array-key>  $excluded
     */
    private static function recordExcludedFields(array &$excludedFields, int|string $index, array $excluded): void
    {
        if ($excluded !== []) {
            $excludedFields[$index] = $excluded;
        }
    }

    /**
     * The rules Laravel validates an item against. A reduced field keeps its
     * active presence or value conditional as written, so Laravel writes its
     * own message ("required when type is image") instead of plain "required".
     *
     * @param  array<string, mixed>  $rules  reduced rules for the item
     * @param  array<string, mixed>  $itemRules
     * @param  array<string, mixed>  $itemData
     * @param  array<string, list<array{action: string, field: string, values: list<string>}>>  $conditionalFields
     * @param  array<string, string>  $itemMessages
     * @param  array<string, true>  $siblingFields
     * @param  array<string, array<string, mixed>>  $byDispatch
     * @return array<string, mixed>
     */
    private function rulesForLaravel(array $rules, array $itemRules, array $itemData, array $conditionalFields, array $itemMessages, array $siblingFields, ?string $dispatchValue, array &$byDispatch): array
    {
        if (array_intersect_key($rules, $siblingFields) === []) {
            return $rules;
        }

        $laravelRules = $dispatchValue !== null && isset($byDispatch[$dispatchValue])
            ? $byDispatch[$dispatchValue]
            : $this->compiler->reduceRulesForItem($itemRules, $itemData, $conditionalFields, $itemMessages, $siblingFields, keepActiveConditionals: true);

        if ($dispatchValue !== null) {
            $byDispatch[$dispatchValue] = $laravelRules;
        }

        // Laravel reads a dependency's rules too: `boolean` makes required_if:flag,true match '1'.
        $restored = array_intersect_key($laravelRules, $rules, $siblingFields);
        $dependencies = array_fill_keys([...PresenceConditionalReducer::dependencies($restored), ...ValueConditionalReducer::dependencies($restored)], true);

        return array_intersect_key($laravelRules, $rules + $dependencies);
    }

    /**
     * Picks the rules Laravel runs for an item that failed a fast check. A
     * field whose fast check passed has no errors, so Laravel gets the failed
     * fields, the slow rules, and the fields whose rules those read. When a
     * rule can read any field, all rules go.
     *
     * @param  array<string, mixed>  $itemRules
     * @return Closure(array<string, mixed>, array<string, Closure(array<string, mixed>): bool>, array<string, mixed>, array<string, mixed>): array<string, mixed>
     */
    private function laravelFieldFilter(bool $isScalar, array $itemRules): Closure
    {
        if ($isScalar || FieldLocalRules::canReadAnyField($itemRules)) {
            return static fn (array $rules): array => $rules;
        }

        $fieldLocalRules = new FieldLocalRules();

        return static function (array $rules, array $fastChecks, array $slowRules, array $itemData) use ($fieldLocalRules): array {
            $pending = array_keys($slowRules);

            foreach ($fastChecks as $field => $check) {
                if (! $check($itemData)) {
                    $pending[] = $field;
                }
            }

            $keep = [];

            while ($pending !== []) {
                $field = array_pop($pending);

                if (isset($keep[$field]) || ! array_key_exists($field, $rules)) {
                    continue;
                }

                $keep[$field] = true;
                $pending = [...$pending, ...$fieldLocalRules->references($rules[$field])];
            }

            return array_intersect_key($rules, $keep);
        };
    }

    /**
     * Build a per-item validator for a distinct rule shape, reused across items
     * via setData().
     *
     * With the default validator resolver the factory returns a plain
     * {@see BaseValidator}, so we swap in a {@see MemoizingValidator} — copying
     * the base's factory state (extensions, container, presence verifier) — so
     * every reused item's passes() hits the shared, worker-wide parse cache
     * instead of re-parsing identical rule strings per item.
     *
     * When the app registered a custom `Validator::resolver()`, the base is a
     * bespoke subclass whose overridden behaviour our state-copy can't
     * replicate, so it is returned unchanged: a Grease-greased validator keeps
     * its own memoization, a consumer's overrides keep firing. Only the plain
     * default is safe to optimize.
     *
     * @param  array<string, mixed>  $itemData
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     */
    private function makeItemValidator(array $itemData, array $rules, array $messages, array $attributes): BaseValidator
    {
        $base = Validator::make($itemData, $rules, $messages, $attributes);

        if ($base::class !== BaseValidator::class) {
            return $base;
        }

        // Empty rules on the target: the pre-exploded rules are copied from the
        // factory-built base, avoiding a second parse of the same shape.
        $memoizing = new MemoizingValidator($base->getTranslator(), $itemData, [], $messages, $attributes);

        ValidatorStateCopier::copy($base, $memoizing);

        return $memoizing;
    }
}
