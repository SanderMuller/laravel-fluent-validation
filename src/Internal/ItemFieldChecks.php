<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Closure;
use SanderMuller\FluentValidation\FastCheckCompiler;
use SanderMuller\FluentValidation\OptimizedValidator;

/**
 * Builds fast checks for wildcard patterns whose rules compare with another
 * field of the same item, such as `gte:items.*.min` on `items.*.max`. The
 * check reads the other field from the item, where Laravel replaces the `*`
 * with the keys of the attribute.
 *
 * @internal Implementation detail of `PreparesOptimizedRules`.
 */
final class ItemFieldChecks
{
    /** The rules whose field parameter Laravel resolves per attribute, and the check reads from the item. */
    private const FIELD_RULES = ['same', 'different', 'gt', 'gte', 'lt', 'lte', 'after', 'after_or_equal', 'before', 'before_or_equal'];

    private const DATE_RULES = ['after', 'after_or_equal', 'before', 'before_or_equal'];

    private const SIZE_RULES = ['gt', 'gte', 'lt', 'lte'];

    /**
     * @param  array<string, mixed>  $patternRules  the rules of each wildcard pattern
     * @param  array<string, mixed>  $rules  all expanded rules
     * @return array<string, array{0: Closure(mixed, array<string, mixed>): bool, 1: list<string>}> the check and the fields it reads, per pattern
     */
    public static function build(array $patternRules, array $rules): array
    {
        $checks = [];
        $sizeComparisonIsSafe = null;

        foreach ($patternRules as $pattern => $rule) {
            $rule = OptimizedValidator::plainRuleString($rule);
            $itemRule = $rule === null ? null : self::itemRule($pattern, $rule);

            if ($itemRule === null) {
                continue;
            }

            // Laravel's size comparisons add their rule to the numeric rules of the
            // validator, which changes the size of a later attribute without a
            // numeric rule. A rule set without such an attribute is safe.
            if ($itemRule['size'] && ! ($sizeComparisonIsSafe ??= ! self::hasSizeComparisonWithoutNumericRule($rules))) {
                continue;
            }

            $check = FastCheckCompiler::compileWithItemContext($itemRule['rule']);

            if ($check instanceof Closure) {
                $checks[$pattern] = [$check, $itemRule['fields']];
            }
        }

        return $checks;
    }

    /**
     * The rule with each field parameter relative to the item, or null when a
     * parameter names a field outside the item, or the rule names no field.
     *
     * @return array{rule: string, fields: list<string>, size: bool}|null
     */
    private static function itemRule(string $pattern, string $rule): ?array
    {
        $dot = strrpos($pattern, '.');

        if ($dot === false) {
            return null;
        }

        $prefix = substr($pattern, 0, $dot + 1);
        $parts = explode('|', $rule);
        $fields = [];
        $size = false;

        foreach ($parts as $i => $part) {
            [$name, $parameter] = array_pad(explode(':', $part, 2), 2, null);

            // Laravel does not resolve a field of `date_equals` per attribute.
            if ($name === 'date_equals' && ($parameter === null || strtotime($parameter) === false)) {
                return null;
            }

            if (! in_array($name, self::FIELD_RULES, true)) {
                continue;
            }

            $field = self::fieldInItem($name, $parameter, $prefix);

            if ($field === false) {
                return null;
            }

            if ($field !== null) {
                $parts[$i] = $name . ':' . $field;
                $fields[] = $field;
                $size = $size || in_array($name, self::SIZE_RULES, true);
            }
        }

        return $fields === [] ? null : ['rule' => implode('|', $parts), 'fields' => array_values(array_unique($fields)), 'size' => $size];
    }

    /**
     * The field name in the item, null for a date that Laravel reads as a
     * date, or false for a parameter the check cannot read.
     */
    private static function fieldInItem(string $name, ?string $parameter, string $prefix): string|false|null
    {
        $isDateRule = in_array($name, self::DATE_RULES, true);

        if ($parameter === null || $isDateRule && strtotime($parameter) !== false) {
            return $isDateRule && $parameter !== null ? null : false;
        }

        if (! str_starts_with($parameter, $prefix) || $isDateRule && self::resolvedPathCanReadAsDate($parameter)) {
            return false;
        }

        $field = substr($parameter, strlen($prefix));

        // The check reads a date-like name as a date, where Laravel reads the field.
        if (preg_match('/\A[a-zA-Z_]\w*\z/', $field) !== 1 || $isDateRule && strtotime($field) !== false) {
            return false;
        }

        return $field;
    }

    /**
     * Laravel parses the parameter as a date after it replaces each `*` with a
     * key, and some of those paths parse, such as `x.1.2.from` or `x.1000.from`.
     */
    private static function resolvedPathCanReadAsDate(string $parameter): bool
    {
        if (substr_count($parameter, '*') > 1 || preg_match('/(?:\A|\.)\d+\./', $parameter) === 1) {
            return true;
        }

        foreach (['0', '1', '12', '31', '2025'] as $key) {
            if (strtotime(str_replace('*', $key, $parameter)) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private static function hasSizeComparisonWithoutNumericRule(array $rules): bool
    {
        foreach ($rules as $rule) {
            $names = self::ruleNames($rule);

            if (array_intersect($names, self::SIZE_RULES) !== [] && array_intersect($names, ['numeric', 'integer', 'decimal']) === []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The lowercase names of the string rules and the array-form rules.
     *
     * @return list<string>
     */
    private static function ruleNames(mixed $rule): array
    {
        $names = [];

        foreach (is_string($rule) ? explode('|', $rule) : (is_array($rule) ? $rule : []) as $part) {
            $name = is_array($part) ? ($part[0] ?? null) : $part;

            if (is_string($name)) {
                $names[] = strtolower(trim(explode(':', $name, 2)[0]));
            }
        }

        return $names;
    }
}
