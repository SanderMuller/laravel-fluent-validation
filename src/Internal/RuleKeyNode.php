<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

/**
 * One segment of the rule keys, for {@see RuleKeyPruner}. A node knows which
 * parts of a value its rules can keep before it sees the data.
 *
 * @internal Implementation detail of `RuleSet`.
 */
final readonly class RuleKeyNode
{
    /**
     * Whether this node keeps any value whole: a rule without `array` or
     * `list`, or one with it and no rule below.
     */
    public bool $keepsAnyValueWhole;

    /**
     * The keys of the named children when this node has no wildcard and each
     * child keeps its whole value, or false.
     *
     * @var array<array-key, true>|false
     */
    public array|false $wholeKeys;

    /**
     * The keys each item keeps when this node has only a wildcard, and the
     * items have no rule of their own and keep the whole value of each named
     * child, or false.
     *
     * @var array<array-key, true>|false
     */
    public array|false $itemKeys;

    /** Whether this node has only a wildcard that keeps each item whole. */
    public bool $keepsItemsWhole;

    /**
     * The named children that do not keep their whole value.
     *
     * @var array<array-key, self>
     */
    public array $nested;

    /**
     * Whether an array value without excluded fields goes to the named
     * children: no wildcard, a child that does not keep its whole value, and
     * no `array` rule that could keep the value whole.
     */
    public bool $prunesByName;

    /**
     * Each named child merged with the wildcard, for the key both match.
     *
     * @var array<array-key, self>
     */
    public array $merged;

    /**
     * For a node without a rule of its own whose children are flat fields
     * and flat lists: each list key mapped to the keys its items keep, and
     * whether it keeps a value that is not a filled array. False otherwise.
     *
     * @var array<array-key, array{array<array-key, true>, bool}>|false
     */
    public array|false $flatLists;

    /** Whether a named node below holds a rule, whatever the data. */
    public bool $namedRuleBelow;

    /** Whether this node or a named node below holds a rule, whatever the data. */
    public bool $holdsRule;

    /**
     * @param  ?bool  $arrayRule  whether a rule key ends here, and with an `array` or `list` rule
     * @param  array<array-key, self>  $children  PHP stores a numeric segment as an int key
     */
    public function __construct(
        public ?bool $arrayRule,
        public array $children,
        public ?self $wildcard,
    ) {
        $nested = [];
        $whole = [];
        $namedRuleBelow = false;

        foreach ($children as $key => $child) {
            if ($child->keepsAnyValueWhole) {
                $whole[$key] = true;
            } else {
                $nested[$key] = $child;
            }

            $namedRuleBelow = $namedRuleBelow || $child->holdsRule;
        }

        $this->keepsAnyValueWhole = $arrayRule === false || ($arrayRule === true && $children === [] && ! $wildcard instanceof self);
        $this->nested = $nested;
        $this->wholeKeys = $wildcard instanceof self || $whole === [] || $nested !== [] ? false : $whole;
        $this->itemKeys = $children === [] && $wildcard instanceof self && $wildcard->arrayRule === null ? $wildcard->wholeKeys : false;
        $this->keepsItemsWhole = $children === [] && $wildcard?->keepsAnyValueWhole === true;
        $this->namedRuleBelow = $namedRuleBelow;
        $this->holdsRule = $arrayRule !== null || $namedRuleBelow;
        $this->flatLists = $arrayRule === null && ! $wildcard instanceof self ? $this->flatLists($nested) : false;
        $this->merged = $wildcard instanceof self ? array_map(static fn (self $child): self => $child->mergedWith($wildcard), $children) : [];
        $this->prunesByName = ! $wildcard instanceof self && $nested !== [] && ($arrayRule === null || ($arrayRule && $namedRuleBelow));
    }

    /**
     * Does a rule name an attribute below this one? Laravel expands a
     * wildcard rule over the data after it fills in the missing named keys,
     * so a named key always exists and a wildcard needs an element. An
     * excluded attribute has no rule left, and nor has one below it.
     *
     * @param  ExcludedFieldTree|true|null  $excluded  the excluded fields below this attribute
     */
    public function hasRuleBelow(mixed $value, ExcludedFieldTree|true|null $excluded): bool
    {
        if ($excluded === null && ($this->namedRuleBelow || ($this->wildcard?->holdsRule === true && is_array($value) && $value !== []))) {
            return true;
        }

        foreach ($this->children as $key => $child) {
            $childValue = is_array($value) && array_key_exists($key, $value) ? $value[$key] : [];

            if ($child->ruleAtOrBelow($childValue, $excluded instanceof ExcludedFieldTree ? $excluded->below($key) : $excluded)) {
                return true;
            }
        }

        return $this->wildcard instanceof self && is_array($value) && $this->wildcard->ruleAtOrBelowAny($value, $excluded);
    }

    /**
     * Does a rule hold this attribute for any element of the value?
     *
     * @param  array<array-key, mixed>  $value
     */
    private function ruleAtOrBelowAny(array $value, ExcludedFieldTree|true|null $excluded): bool
    {
        foreach ($value as $key => $childValue) {
            if ($this->ruleAtOrBelow($childValue, $excluded instanceof ExcludedFieldTree ? $excluded->below($key) : $excluded)) {
                return true;
            }
        }

        return false;
    }

    private function ruleAtOrBelow(mixed $value, ExcludedFieldTree|true|null $excluded): bool
    {
        return $excluded !== true && ($this->arrayRule !== null || $this->hasRuleBelow($value, $excluded));
    }

    /**
     * @param  array<array-key, self>  $nested
     * @return array<array-key, array{array<array-key, true>, bool}>|false
     */
    private function flatLists(array $nested): array|false
    {
        $lists = [];

        foreach ($nested as $key => $child) {
            if ($child->itemKeys === false) {
                return false;
            }

            $lists[$key] = [$child->itemKeys, $child->arrayRule === true];
        }

        return $lists === [] ? false : $lists;
    }

    /**
     * The node for an attribute that both this node and the other match:
     * the rules of both, as Laravel merges them.
     */
    private function mergedWith(self $other): self
    {
        $children = $this->children;

        foreach ($other->children as $key => $child) {
            $children[$key] = isset($children[$key]) ? $children[$key]->mergedWith($child) : $child;
        }

        $wildcard = $this->wildcard instanceof self && $other->wildcard instanceof self
            ? $this->wildcard->mergedWith($other->wildcard)
            : $this->wildcard ?? $other->wildcard;
        $arrayRule = $this->arrayRule === null || $other->arrayRule === null
            ? $this->arrayRule ?? $other->arrayRule
            : $this->arrayRule || $other->arrayRule;

        return new self($arrayRule, $children, $wildcard);
    }

    /**
     * The tree of the rule keys: the segments of each key below this node,
     * and whether its rule holds `array` or `list`.
     *
     * @param  list<array{list<string>, bool}>  $rules
     */
    public static function of(array $rules): self
    {
        $arrayRule = null;
        $below = [];

        foreach ($rules as [$segments, $isArray]) {
            if ($segments === []) {
                $arrayRule = $isArray;

                continue;
            }

            $below[array_shift($segments)][] = [$segments, $isArray];
        }

        $wildcard = isset($below['*']) ? self::of($below['*']) : null;
        unset($below['*']);

        return new self($arrayRule, array_map(self::of(...), $below), $wildcard);
    }
}
