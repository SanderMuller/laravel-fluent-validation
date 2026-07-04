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
     * @return array<string, list<string>>
     */
    public function validate(array $items, array $itemRules, array $itemMessages, array $itemAttributes, string $parent, bool $isScalar): array
    {
        $conditionalFields = $this->compiler->analyzeConditionals($itemRules);
        $hasPresenceConditionals = PresenceConditionalReducer::hasAny($itemRules);
        $hasValueConditionals = ValueConditionalReducer::hasAny($itemRules);
        $hasSiblingDependentConditionals = $hasPresenceConditionals || $hasValueConditionals;

        // Presence and value conditionals (required_with*, required_if, etc.)
        // depend on arbitrary sibling fields within each item, so two items
        // sharing the same dispatch-field value can still reduce to different
        // rule sets. Skip the dispatch cache when either is in play.
        $dispatchField = $hasSiblingDependentConditionals
            ? null
            : $this->compiler->findCommonDispatchField($conditionalFields);
        /** @var array<string, array<string, mixed>> $rulesByDispatch */
        $rulesByDispatch = [];
        /** @var array<string, array{0: array<string, Closure(array<string, mixed>): bool>, 1: array<string, mixed>}> $fastChecksByDispatch */
        $fastChecksByDispatch = [];
        /** @var array<string, array{0: list<Closure(array<string, mixed>): bool>, 1: array<string, mixed>}> $fastChecksByReduced */
        $fastChecksByReduced = [];

        [$fastChecks, $originalSlowRules] = $this->compiler->buildFastChecks($itemRules);
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

        foreach ($items as $index => $item) {
            /** @var array<string, mixed> $itemData */
            $itemData = $isScalar ? ['_v' => $item] : (is_array($item) ? $item : []);

            if ($dispatchField !== null) {
                $rawDispatch = $itemData[$dispatchField] ?? '';
                $dispatchValue = is_scalar($rawDispatch) ? (string) $rawDispatch : '';

                if (! isset($rulesByDispatch[$dispatchValue])) {
                    $rulesByDispatch[$dispatchValue] = $this->compiler->reduceRulesForItem($itemRules, $itemData, $conditionalFields, $itemMessages);
                    $fastChecksByDispatch[$dispatchValue] = $this->compiler->buildFastChecks($rulesByDispatch[$dispatchValue]);
                }

                $effectiveRules = $rulesByDispatch[$dispatchValue];
                [$dispatchFastChecks, $dispatchSlowRules] = $fastChecksByDispatch[$dispatchValue];
            } elseif ($conditionalFields !== [] || $hasSiblingDependentConditionals) {
                $effectiveRules = $this->compiler->reduceRulesForItem($itemRules, $itemData, $conditionalFields, $itemMessages);
                $reducedKey = $this->compiler->ruleCacheKey($effectiveRules);
                // Memoize compiled fast-checks per distinct reduced rule set —
                // many items reduce identically, so reuse beats recompiling.
                [$dispatchFastChecks, $dispatchSlowRules] = $fastChecksByReduced[$reducedKey]
                    ??= $this->compiler->buildFastChecks($effectiveRules);
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
                    $reducedSlowRules = $dispatchSlowRules;

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

                    if (! $validatorCache[$cacheKey]->passes()) {
                        $this->errors->collectErrors($validatorCache[$cacheKey], $parent, $index, $isScalar, $errors);

                        if ($this->stopOnFirstFailure) {
                            return $errors;
                        }
                    }

                    continue;
                }
            }

            $cacheKey = $this->compiler->ruleCacheKey($effectiveRules);

            if (! isset($validatorCache[$cacheKey])) {
                $validatorCache[$cacheKey] = $this->makeItemValidator($itemData, $effectiveRules, $itemMessages, $itemAttributes);

                if ($batchVerifier instanceof PrecomputedPresenceVerifier) {
                    $validatorCache[$cacheKey]->setPresenceVerifier($batchVerifier);
                }
            } else {
                $validatorCache[$cacheKey]->setData($itemData);
            }

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
