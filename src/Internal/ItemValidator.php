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
 * @internal
 *
 * @phpstan-import-type ExcludedFields from WildcardValidatedData
 */
final readonly class ItemValidator
{
    public function __construct(
        private ItemRuleCompiler $compiler,
        private ItemErrorCollector $errors,
        private ?string $stopAt = null,
        /** @var list<string> the item fields that a wildcard reference names */
        private array $references = [],
    ) {}

    /**
     * @param  array<int|string, mixed>  $items
     * @param  array<string, mixed>  $itemRules
     * @param  array<string, string>  $itemMessages
     * @param  array<string, string>  $itemAttributes
     * @param  array<string, list<array{action: string, field: string, values: list<string>}>>  $conditionalFields  {@see ItemRuleCompiler::analyzeConditionals()}
     * @param  array<int|string, ExcludedFields>  $excludedFields  receives, per item index, the fields an exclude rule removed
     * @return array<string, list<string>>
     */
    public function validate(array $items, array $itemRules, array $itemMessages, array $itemAttributes, string $parent, bool $isScalar, array $conditionalFields, array &$excludedFields = []): array
    {
        $plan = ItemRulePlan::for($this->compiler, $itemRules, $conditionalFields, $itemMessages, $isScalar);
        $itemRules = $plan->itemRules;
        $siblingFields = $plan->siblingFields;
        $reducedRules = null;
        /** @var array<string, array{0: array<string, mixed>, 1: array<string, Closure(array<string, mixed>): bool>, 2: array<string, mixed>, 3: ExcludedFields, 4: Closure(array<string, mixed>): bool}> $byReduced */
        $byReduced = [];
        // A local copy saves a method call per item.
        $byDispatch = [];
        /** @var array<string, array<string, mixed>> $laravelRulesByDispatch */
        $laravelRulesByDispatch = [];
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
        if ($conditionalFields === [] && $siblingFields === [] && BatchDatabaseChecker::isAvailable()) {
            $batchVerifier = $this->compiler->buildBatchVerifier($plan->slowRules, $items, $isScalar);
        }

        $names = new ItemAttributeNames($isScalar, array_map(strval(...), array_keys($itemRules)), $parent, $itemMessages, $itemAttributes, $this->references);

        foreach ($items as $index => $item) {
            /** @var array<string, mixed> $itemData */
            $itemData = $isScalar ? ['_v' => $item] : (is_array($item) ? $item : []);

            $dispatchValue = $plan->dispatchField !== null ? DispatchField::key($itemData, $plan->dispatchField) : null;

            if ($dispatchValue !== null) {
                [$effectiveRules, $dispatchFastChecks, $dispatchSlowRules, $excludedFields[$index], $passAll] = $byDispatch[$dispatchValue] ??= $plan->forDispatch($dispatchValue, $itemData);
            } elseif ($conditionalFields !== [] || $siblingFields !== []) {
                $reducedRules ??= ReducedRulesCache::for($this->compiler, $itemRules, $conditionalFields, $itemMessages, $siblingFields);
                [$reduced, $reducedKey] = $reducedRules->reduce($itemData);
                // Many items reduce identically, so reuse beats recompiling.
                [$effectiveRules, $dispatchFastChecks, $dispatchSlowRules, $excludedFields[$index], $passAll] = $byReduced[$reducedKey] ??= $plan->reduced($reduced);
            } else {
                $effectiveRules = $itemRules;
                $dispatchFastChecks = $plan->passChecks;
                $passAll = $plan->passAll();
                $dispatchSlowRules = $plan->slowRules;
            }

            if ($dispatchFastChecks !== []) {
                $fastPass = $passAll($itemData);

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
                        $validatorCache[$cacheKey] = self::makeItemValidator($itemData, $reducedSlowRules, $itemMessages, $itemAttributes);

                        if ($batchVerifier instanceof PrecomputedPresenceVerifier) {
                            $validatorCache[$cacheKey]->setPresenceVerifier($batchVerifier);
                        }
                    } else {
                        $validatorCache[$cacheKey]->setData($itemData);
                    }

                    $names->forItem($validatorCache[$cacheKey], $index, $itemData);

                    if (! $validatorCache[$cacheKey]->passes() && $this->errors->collectErrors($validatorCache[$cacheKey], $parent, $index, $isScalar, $errors, $this->stopAt)) {
                        return $errors;
                    }

                    continue;
                }
            }

            $effectiveRules = ($plan->laravelFields)($effectiveRules, $dispatchFastChecks, $dispatchSlowRules, $itemData);
            $effectiveRules = $this->rulesForLaravel($effectiveRules, $itemRules, $itemData, $conditionalFields, $itemMessages, $siblingFields, $dispatchValue, $laravelRulesByDispatch);
            $cacheKey = $this->compiler->ruleCacheKey($effectiveRules);

            if (! isset($validatorCache[$cacheKey])) {
                $validatorCache[$cacheKey] = self::makeItemValidator($itemData, $effectiveRules, $itemMessages, $itemAttributes);

                if ($batchVerifier instanceof PrecomputedPresenceVerifier) {
                    $validatorCache[$cacheKey]->setPresenceVerifier($batchVerifier);
                }
            } else {
                $validatorCache[$cacheKey]->setData($itemData);
            }

            $names->forItem($validatorCache[$cacheKey], $index, $itemData);

            if (! $validatorCache[$cacheKey]->passes() && $this->errors->collectErrors($validatorCache[$cacheKey], $parent, $index, $isScalar, $errors, $this->stopAt)) {
                return $errors;
            }
        }

        return $errors;
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
        if (array_intersect_key($rules, $siblingFields) !== []) {
            $rules = $this->withActiveConditionals($rules, $itemRules, $itemData, $conditionalFields, $itemMessages, $siblingFields, $dispatchValue, $byDispatch);
        }

        // Laravel removes an excluded field only after its rule loop, so a rule
        // that reads the field still sees its `boolean` rule.
        return $rules === [] ? [] : $rules + ItemRuleCompiler::excludedBooleanRules(array_diff_key($conditionalFields, $rules), $itemRules);
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  array<string, mixed>  $itemRules
     * @param  array<string, mixed>  $itemData
     * @param  array<string, list<array{action: string, field: string, values: list<string>}>>  $conditionalFields
     * @param  array<string, string>  $itemMessages
     * @param  array<string, true>  $siblingFields
     * @param  array<string, array<string, mixed>>  $byDispatch
     * @return array<string, mixed>
     */
    private function withActiveConditionals(array $rules, array $itemRules, array $itemData, array $conditionalFields, array $itemMessages, array $siblingFields, ?string $dispatchValue, array &$byDispatch): array
    {
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
    public static function makeItemValidator(array $itemData, array $rules, array $messages, array $attributes): BaseValidator
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
