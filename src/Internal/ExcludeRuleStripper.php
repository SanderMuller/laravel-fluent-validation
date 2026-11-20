<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Stringable;

/**
 * Removes the exclude rules from a field that an item keeps, so the rules
 * that remain can compile to a fast check.
 *
 * @internal Implementation detail of {@see ItemRuleCompiler::reduceRulesForItem()}.
 */
final class ExcludeRuleStripper
{
    /**
     * Strip exclude_unless/exclude_if rules, in tuple or string form, leaving
     * the actual validation rules. Joins remaining strings into a
     * pipe-delimited string when possible.
     *
     * Only the exclude rules are removed — required_if/required_unless tuples
     * must survive so the field's presence conditional still validates; the
     * presence and value reducers handle them next.
     */
    public static function strip(mixed $rules): mixed
    {
        if (is_string($rules)) {
            return self::stripExcludeParts($rules);
        }

        if (! is_array($rules)) {
            return $rules;
        }

        $stripped = [];

        foreach ($rules as $rule) {
            if (is_array($rule) && isset($rule[0]) && is_string($rule[0])
                && in_array($rule[0], ['exclude_unless', 'exclude_if'], true)) {
                continue;
            }

            // Laravel reads an array element as one rule, so a pipe in it is no separator.
            if (is_string($rule) && ! str_contains($rule, '|') && self::isExcludePart($rule)) {
                continue;
            }

            // Stringify Stringable objects (Rule::in, Rule::notIn) so the
            // result can be fast-checked as a pipe-joined string.
            $stripped[] = $rule instanceof Stringable ? (string) $rule : $rule;
        }

        return self::join($stripped);
    }

    /**
     * @param  list<mixed>  $stripped
     */
    private static function join(array $stripped): mixed
    {
        // If all remaining rules are strings, join them for faster parsing —
        // unless a token contains a literal `|` (e.g. `regex:/^(a|b)$/`), which
        // Laravel's parser would split. Keep the array form in that case.
        $allStrings = true;
        $anyContainsPipe = false;
        foreach ($stripped as $rule) {
            if (! is_string($rule)) {
                $allStrings = false;
                break;
            }

            if (str_contains($rule, '|')) {
                $anyContainsPipe = true;
            }
        }

        if ($allStrings && ! $anyContainsPipe && $stripped !== []) {
            return implode('|', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $stripped));
        }

        return $stripped;
    }

    /**
     * Remove the `exclude_unless:`/`exclude_if:` parts from a pipe-joined
     * rule string, as the fluent `->excludeUnless()` compiles to. A regex
     * can hold a `|`, so a string with a regex stays as it is.
     */
    private static function stripExcludeParts(string $rules): string
    {
        if (str_contains($rules, 'regex:')) {
            return $rules;
        }

        $parts = array_filter(explode('|', $rules), static fn (string $part): bool => ! self::isExcludePart($part));

        return implode('|', $parts);
    }

    private static function isExcludePart(string $rule): bool
    {
        return str_starts_with($rule, 'exclude_unless:') || str_starts_with($rule, 'exclude_if:');
    }
}
