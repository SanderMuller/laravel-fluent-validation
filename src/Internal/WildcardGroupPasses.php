<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Closure;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationRuleParser;

/**
 * Builds the per-item validation passes of one wildcard group.
 *
 * @internal Implementation detail of `RuleSet`.
 */
final class WildcardGroupPasses
{
    /**
     * The per-item passes of a group, or null when the group needs full
     * expansion. A rule on `items.*` validates the item itself, so a group
     * with that rule and field rules runs it in a pass of its own. Laravel
     * links the two only through an exclude rule on the item, a reference
     * from the item rule to a field, and stopOnFirstFailure; the group then
     * needs full expansion.
     *
     * @param  array<string, mixed>  $groupRules
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     * @param  array<string, mixed>  $data
     * @param  Closure(array<string, mixed>): array<string, mixed>  $compile  `RuleSet::compile()`
     * @return list<array{0: array<string, mixed>, 1: array<string, string>, 2: array<string, string>, 3: bool}>|null
     */
    public static function for(array $groupRules, string $parent, array $messages, array $attributes, array $data, bool $stopOnFirstFailure, Closure $compile): ?array
    {
        $passes = [];

        if (! isset($groupRules['*']) || count($groupRules) === 1) {
            $passes[] = self::groupPass($groupRules, $parent, $messages, $attributes, $compile);
        } else {
            $itemRule = ['*' => $groupRules['*']];

            if ($stopOnFirstFailure || WildcardValidatedData::excludes($itemRule) || OutsideItemReferences::fromItemRule($itemRule, $data)) {
                return null;
            }

            $fieldRules = array_diff_key($groupRules, $itemRule);
            $fieldMessages = ItemAttributeNames::fieldPassMessages($messages, $parent, array_map(strval(...), array_keys($fieldRules)), self::ruleNames($itemRule['*'], $compile));

            if ($fieldMessages === null) {
                return null;
            }

            $passes[] = self::groupPass($itemRule, $parent, $messages, $attributes, $compile);
            $passes[] = self::groupPass($fieldRules, $parent, $fieldMessages, $attributes, $compile);
        }

        foreach ($passes as [$itemRules]) {
            if (self::requiresFullExpansion($itemRules)) {
                return null;
            }
        }

        return $passes;
    }

    /**
     * The snake-case names of the string rules in a rule, as message keys
     * name them.
     *
     * @param  Closure(array<string, mixed>): array<string, mixed>  $compile
     * @return list<string>
     */
    private static function ruleNames(mixed $rule, Closure $compile): array
    {
        $compiled = $compile(['*' => $rule])['*'] ?? [];
        $parts = is_string($compiled) ? explode('|', $compiled) : (is_array($compiled) ? $compiled : []);
        $names = [];

        foreach ($parts as $part) {
            if (is_string($part)) {
                $name = ValidationRuleParser::parse($part)[0];
                $names[] = is_string($name) ? Str::snake($name) : '';
            }
        }

        return $names;
    }

    /**
     * @param  array<string, mixed>  $groupRules
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     * @param  Closure(array<string, mixed>): array<string, mixed>  $compile
     * @return array{0: array<string, mixed>, 1: array<string, string>, 2: array<string, string>, 3: bool}
     */
    private static function groupPass(array $groupRules, string $parent, array $messages, array $attributes, Closure $compile): array
    {
        $isScalar = array_keys($groupRules) === ['*'];

        // Extract metadata from the pre-rewrite rules: rewriteRulesForPerItem
        // may flatten object-form rules (FluentRule/FieldRule) into native
        // arrays to reach the conditional deps buried inside them, which
        // would otherwise drop the object's labels and per-rule messages.
        // Field keys are identical before and after the rewrite, so the
        // extracted metadata stays correctly keyed.
        $metadataRules = $isScalar ? ['_v' => $groupRules['*']] : $groupRules;
        [$itemMessages, $itemAttributes] = RuleMetadata::extract($metadataRules);
        $itemMessages = ItemAttributeNames::relativeToItem($messages, $parent, $isScalar) + $messages + $itemMessages;
        $itemAttributes = ItemAttributeNames::relativeToItem($attributes, $parent, $isScalar) + $attributes + $itemAttributes;

        $rawItemRules = $isScalar ? self::rewriteRulesForPerItem($metadataRules, $parent, '_v.') : self::rewriteRulesForPerItem($groupRules, $parent);

        return [$compile($rawItemRules), $itemMessages, $itemAttributes, $isScalar];
    }

    /**
     * Rewrite wildcard field references to item-relative paths so per-item
     * validation works. Transforms:
     *   ['exclude_unless', 'items.*.type', 'chapter'] → ['exclude_unless', 'type', 'chapter']
     *   'gte:items.*.start_time' → 'gte:start_time'
     *
     * @param  array<string, mixed>  $groupRules
     * @return array<string, mixed>
     */
    private static function rewriteRulesForPerItem(array $groupRules, string $parent, string $itemPrefix = ''): array
    {
        $rewriter = new WildcardReferenceRewriter($parent . '.*.', $itemPrefix);
        $rewritten = [];

        foreach ($groupRules as $field => $rule) {
            // FluentRule/FieldRule objects compile to either a pipe-joined string
            // or an array of native rules/objects. A conditional dep like
            // requiredUnless('items.*.type', …) keeps its `items.*.` prefix until
            // this point, so strip it here or the per-item reducer's data_get on
            // the relative item never resolves it.
            if (is_object($rule) && method_exists($rule, 'compiledRules')) {
                $rule = $rule->compiledRules();
            }

            $rewritten[$field] = $rewriter->rewrite($rule);
        }

        return $rewritten;
    }

    /** @param  array<string, mixed>  $compiledRules */
    private static function requiresFullExpansion(array $compiledRules): bool
    {
        // Cross-item rules like distinct need full expansion to compare across items.
        // Nested wildcards (chapters.*.title) are fine — the per-item validator
        // handles them within each item's scope. So is an exclude rule, unless a
        // rule that can fail comes before it: only Laravel runs those in order.
        foreach ($compiledRules as $compiledRule) {
            $strings = is_array($compiledRule) ? array_filter($compiledRule, is_string(...)) : [];
            $ruleString = is_string($compiledRule) ? $compiledRule : implode('|', $strings);

            if (str_contains($ruleString, 'distinct') || (self::mayExcludeLater($compiledRule, $strings, $ruleString) && ExcludeConditionExtractor::followsOtherRule($compiledRule))) {
                return true;
            }
        }

        return false;
    }

    /**
     * A quick test before {@see ExcludeConditionExtractor::followsOtherRule()}:
     * can an exclude rule come after the first rule? A rule that is not a
     * string is an exclude tuple or an object.
     *
     * @param  array<array-key, mixed>  $strings  the string rules of an array rule
     */
    private static function mayExcludeLater(mixed $compiledRule, array $strings, string $ruleString): bool
    {
        if (str_contains($ruleString, '|exclude_')) {
            return true;
        }

        if (! is_array($compiledRule)) {
            return false;
        }

        $others = count($compiledRule) - count($strings);

        return $others > 1 || ($others === 1 && is_string(reset($compiledRule)));
    }
}
