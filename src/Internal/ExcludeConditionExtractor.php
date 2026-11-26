<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

/**
 * Extracts `exclude_unless`/`exclude_if` conditions from a field's rule value,
 * regardless of the shape that field arrived in:
 *
 * - array tuple — `['exclude_unless', 'type', 'chapter']` (array-input form)
 * - string — `'exclude_unless:type,chapter'` (the fluent `->excludeUnless()`
 *   API and plain string rules compile to this)
 * - pipe-joined string — `'exclude_unless:type,chapter|required|string'`
 * - an array mixing any of the above
 *
 * The three exclude pre-evaluation sites — `preExcludeRules`,
 * {@see ConditionalEvaluationPhase}, and {@see ItemRuleCompiler} — historically
 * recognised only the tuple form, so the optimization silently skipped the
 * fluent/string API (the idiomatic one). Centralising extraction here makes all
 * three prune uniformly.
 *
 * @internal Not part of the public API.
 */
final class ExcludeConditionExtractor
{
    /** @var list<string> */
    private const ACTIONS = ['exclude_unless', 'exclude_if'];

    /**
     * Rules that fail only when their field holds one of their values, as
     * Laravel reads the values of an exclude rule.
     *
     * @var list<string>
     */
    private const CONDITIONAL_RULES = ['required_if', 'prohibited_if'];

    /**
     * Rules that may come before an exclude rule without a change to the
     * pre-evaluation, as they add no error.
     */
    private const ALLOWED_BEFORE_EXCLUDE = ['bail' => true, 'nullable' => true, 'sometimes' => true];

    /** The most results {@see self::$leading} keeps. */
    private const CACHE_MAX = 256;

    /**
     * {@see leading()} per string rule: RuleSet asks again on each validation.
     *
     * @var array<string, list<array{action: string, field: string, values: list<string>}>|null>
     */
    private static array $leading = [];

    /**
     * @return list<array{action: string, field: string, values: list<string>}>
     */
    public static function extract(mixed $ruleValue): array
    {
        $conditions = [];

        foreach (self::segments($ruleValue) as $segment) {
            $condition = self::parseSegment($segment, self::ACTIONS);
            if ($condition !== null) {
                $conditions[] = $condition;
            }
        }

        return $conditions;
    }

    /**
     * {@see extract()}, or none when a rule that can fail comes before an
     * exclude rule: Laravel must then run the rules in order.
     *
     * @return list<array{action: string, field: string, values: list<string>}>
     */
    public static function extractLeading(mixed $ruleValue): array
    {
        return self::leading($ruleValue) ?? [];
    }

    /**
     * Does a rule that can fail come before an exclude rule? Laravel runs the
     * rules in order, so that rule can add an error before the exclusion.
     */
    public static function followsOtherRule(mixed $ruleValue): bool
    {
        return self::leading($ruleValue) === null;
    }

    /**
     * The exclude conditions, or null when a rule before an exclude rule can
     * fail on an attribute that the exclusion removes.
     *
     * @return list<array{action: string, field: string, values: list<string>}>|null
     */
    private static function leading(mixed $ruleValue): ?array
    {
        if (! is_string($ruleValue)) {
            return self::scan($ruleValue);
        }

        if (array_key_exists($ruleValue, self::$leading)) {
            return self::$leading[$ruleValue];
        }

        if (count(self::$leading) >= self::CACHE_MAX) {
            self::$leading = [];
        }

        return self::$leading[$ruleValue] = self::scan($ruleValue);
    }

    /**
     * @return list<array{action: string, field: string, values: list<string>}>|null
     */
    private static function scan(mixed $ruleValue): ?array
    {
        $conditions = [];
        $conditionalRules = [];
        $pending = [];
        $other = false;

        foreach (self::segments($ruleValue) as $segment) {
            $condition = self::parseSegment($segment, self::ACTIONS);

            if ($condition !== null) {
                if ($other) {
                    return null;
                }

                $conditions[] = $condition;
                array_push($conditionalRules, ...$pending);
                $pending = [];

                continue;
            }

            if (isset(self::ALLOWED_BEFORE_EXCLUDE[self::ruleName($segment)])) {
                continue;
            }

            $conditionalRule = self::parseSegment($segment, self::CONDITIONAL_RULES);

            if ($conditionalRule === null) {
                $other = true;
            } else {
                $pending[] = $conditionalRule;
            }
        }

        foreach ($conditionalRules as $conditionalRule) {
            foreach ($conditions as $condition) {
                if (! self::excludesOnlyWhenPasses($condition, $conditionalRule)) {
                    return null;
                }
            }
        }

        return $conditions;
    }

    /**
     * Can the exclusion fire only when the conditional rule passes? Both
     * read one field, so the values decide: `required_if:type,a` fails only
     * for `a`, which `exclude_unless:type,a,b` keeps.
     *
     * @param  array{action: string, field: string, values: list<string>}  $condition
     * @param  array{action: string, field: string, values: list<string>}  $conditionalRule
     */
    private static function excludesOnlyWhenPasses(array $condition, array $conditionalRule): bool
    {
        if ($condition['field'] !== $conditionalRule['field']) {
            return false;
        }

        $shared = array_intersect($conditionalRule['values'], $condition['values']);

        return $condition['action'] === 'exclude_unless'
            ? count($shared) === count($conditionalRule['values'])
            : $shared === [];
    }

    private static function ruleName(mixed $segment): string
    {
        if (is_array($segment)) {
            $segment = $segment[0] ?? null;
        }

        if (! is_string($segment)) {
            return '';
        }

        $colon = strpos($segment, ':');

        return $colon === false ? $segment : substr($segment, 0, $colon);
    }

    /**
     * Flatten a field's rule value into individual rule segments. A pipe-joined
     * string is split on `|`; an array is walked element-by-element (each element
     * may itself be a pipe-joined string or a tuple). Splitting on `|` here is
     * detection-only — a regex token like `regex:/^(a|b)$/` simply won't match an
     * `exclude_*` prefix, and the original rule is never mutated from this split.
     *
     * @return list<mixed>
     */
    private static function segments(mixed $ruleValue): array
    {
        if (is_string($ruleValue)) {
            return explode('|', $ruleValue);
        }

        if (! is_array($ruleValue)) {
            return [];
        }

        $segments = [];

        foreach ($ruleValue as $element) {
            if (is_string($element) && str_contains($element, '|')) {
                foreach (explode('|', $element) as $part) {
                    $segments[] = $part;
                }

                continue;
            }

            $segments[] = $element;
        }

        return $segments;
    }

    /**
     * @param  list<string>  $actions
     * @return array{action: string, field: string, values: list<string>}|null
     */
    private static function parseSegment(mixed $segment, array $actions): ?array
    {
        if (is_array($segment)) {
            return self::parseTuple($segment, $actions);
        }

        if (is_string($segment)) {
            return self::parseString($segment, $actions);
        }

        return null;
    }

    /**
     * @param  array<int|string, mixed>  $segment
     * @param  list<string>  $actions
     * @return array{action: string, field: string, values: list<string>}|null
     */
    private static function parseTuple(array $segment, array $actions): ?array
    {
        if (count($segment) < 3) {
            return null;
        }

        $action = $segment[0] ?? null;
        $field = $segment[1] ?? null;

        if (! is_string($action) || ! in_array($action, $actions, true) || ! is_string($field)) {
            return null;
        }

        return [
            'action' => $action,
            'field' => $field,
            'values' => array_map(
                static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
                array_values(array_slice($segment, 2)),
            ),
        ];
    }

    /**
     * @param  list<string>  $actions
     * @return array{action: string, field: string, values: list<string>}|null
     */
    private static function parseString(string $segment, array $actions): ?array
    {
        foreach ($actions as $action) {
            $prefix = $action . ':';

            if (! str_starts_with($segment, $prefix)) {
                continue;
            }

            // Mirror Laravel's `ValidationRuleParser::parseParameters` CSV semantics.
            $params = str_getcsv(substr($segment, strlen($prefix)), ',', '"', '\\');

            // Laravel throws for a rule without a value; leave it to Laravel.
            if (! is_string($params[0]) || $params[0] === '' || count($params) < 2) {
                return null;
            }

            return [
                'action' => $action,
                'field' => $params[0],
                'values' => array_map(static fn (?string $v): string => (string) $v, array_slice($params, 1)),
            ];
        }

        return null;
    }
}
