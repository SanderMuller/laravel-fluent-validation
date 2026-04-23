<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Illuminate\Support\Facades\Validator;
use SanderMuller\FluentValidation\BatchDatabaseChecker;
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
        /** @var array<string, array{0: array<string, \Closure(array<string, mixed>): bool>, 1: array<string, mixed>}> $fastChecksByDispatch */
        $fastChecksByDispatch = [];

        [$fastChecks, $originalSlowRules] = $this->compiler->buildFastChecks($itemRules);
        /** @var array<string, \Illuminate\Validation\Validator> $validatorCache */
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
                [$dispatchFastChecks, $dispatchSlowRules] = $this->compiler->buildFastChecks($effectiveRules);
            } else {
                $effectiveRules = $itemRules;
                $dispatchFastChecks = $fastChecks;
                $dispatchSlowRules = $originalSlowRules;
            }

            if ($dispatchFastChecks !== []) {
                $fastPass = $this->errors->passesAllFastChecks(array_values($dispatchFastChecks), $itemData);

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
                        $validatorCache[$cacheKey] = Validator::make($itemData, $reducedSlowRules, $itemMessages, $itemAttributes);

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
                $validatorCache[$cacheKey] = Validator::make($itemData, $effectiveRules, $itemMessages, $itemAttributes);

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
}
