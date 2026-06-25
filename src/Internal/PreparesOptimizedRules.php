<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use SanderMuller\FluentValidation\BatchDatabaseChecker;
use SanderMuller\FluentValidation\Exceptions\BatchLimitExceededException;
use SanderMuller\FluentValidation\OptimizedValidator;
use SanderMuller\FluentValidation\PrecomputedPresenceVerifier;
use SanderMuller\FluentValidation\PreparedRules;

/**
 * Shared rule-preparation steps for the optimized validation entry points.
 *
 * Both {@see HasFluentRules} (FormRequest /
 * Livewire trait) and {@see FluentValidator}
 * (standalone base) turn prepared rules into an {@see OptimizedValidator}
 * setup: pre-excluding conditional rules whose `exclude_unless`/`exclude_if`
 * conditions don't match the data, and compiling fast-check closures for
 * wildcard patterns. Kept here so the two entry points stay behaviourally
 * identical — a divergence between them was the source of a perf/correctness
 * gap (FluentValidator previously skipped this preparation entirely).
 *
 * @internal Not part of the public API.
 */
trait PreparesOptimizedRules
{
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
        foreach ($rules as $attribute => $ruleSet) {
            // Recognise both the array-tuple and the string/pipe-joined forms of
            // exclude_unless/exclude_if — the fluent `->excludeUnless()` API and
            // plain string rules compile to the string form, which an
            // array-tuple-only check would silently skip (leaving the rule set
            // fully expanded and the validator quadratic).
            foreach (ExcludeConditionExtractor::extract($ruleSet) as $condition) {
                $conditionField = ConditionalEvaluationPhase::resolveWildcard((string) $attribute, $condition['field']);

                // Unresolved wildcard (the dep position has no matching segment) —
                // leave the rule for the validator to resolve rather than guess.
                if (str_contains($conditionField, '*')) {
                    continue;
                }

                // exclude_if is inactive when the dependent field is absent
                // (mirrors Laravel's Arr::has short-circuit), regardless of value.
                if ($condition['action'] === 'exclude_if' && ! Arr::has($data, $conditionField)) {
                    continue;
                }

                // Pass the full rule set so a `boolean`-declared dependent sent as
                // raw '0'/'1' coerces like Laravel.
                $match = ConditionalValueMatcher::matches($conditionField, $condition['values'], $data, $rules);
                $shouldExclude = $condition['action'] === 'exclude_unless' ? ! $match : $match;

                if ($shouldExclude) {
                    unset($rules[$attribute]);

                    break;
                }
            }
        }

        return $rules;
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
     * Batch exists/unique queries for wildcard-expanded attributes only, then
     * install the resulting verifier on the validator. Non-wildcard rules are
     * left to the original verifier to avoid interfering with scoped
     * exists/unique checks on single fields. No-op when there are no wildcard
     * attributes or the batch checker isn't available (no DB bound).
     *
     * @param  array<string, mixed>  $preparedRules
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException  when a parent array exceeds its max:N budget
     */
    private function applyBatchPresenceVerifier(Validator $validator, PreparedRules $prepared, array $preparedRules, array $data): void
    {
        if ($prepared->implicitAttributes === [] || ! BatchDatabaseChecker::isAvailable()) {
            return;
        }

        $wildcardAttributes = array_merge(...array_values($prepared->implicitAttributes));

        try {
            $batchVerifier = $this->buildWildcardBatchVerifier($preparedRules, $data, $wildcardAttributes);
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
     *   — the caller's catch remaps it to `ValidationException` so callers
     *   see the package's standard exception type. Note: because the throw
     *   happens before the validator runs, `failedValidation()` is NOT invoked.
     * - Hard cap (Phase 2): enforced inside `BatchDatabaseChecker::buildVerifier`.
     *
     * @param  array<string, mixed>  $preparedRules  Full prepared rules (NOT pre-intersected).
     * @param  array<string, mixed>  $data
     * @param  list<string>  $wildcardAttributes  Concrete expanded wildcard paths (e.g. `items.0.id`).
     *
     * @throws BatchLimitExceededException
     */
    private function buildWildcardBatchVerifier(array $preparedRules, array $data, array $wildcardAttributes): ?PrecomputedPresenceVerifier
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

        foreach (BatchDatabaseChecker::findBatchableRules($wildcardRules) as $attribute => $rule) {
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

            if ($cache[$parent] <= $max) {
                continue;
            }

            throw new BatchLimitExceededException(
                table: BatchDatabaseChecker::getVerifierTable($rule) ?? '',
                column: BatchDatabaseChecker::getVerifierColumn($rule) ?? '',
                ruleType: $rule instanceof Unique ? 'unique' : 'exists',
                reason: BatchLimitExceededException::REASON_PARENT_MAX,
                valueCount: $cache[$parent],
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
}
