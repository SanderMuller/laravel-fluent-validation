<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

/**
 * What the rule keys do at one level of the validated data, see
 * {@see RuleOrderedData}. It depends on the rules alone, so the items of one
 * array share it.
 *
 * @internal Implementation detail of `RuleOrderedData`.
 */
final readonly class RuleOrderPlan
{
    /**
     * @param  list<string>|null  $fields  the fields in rule order, when each rule key names one field and ends there
     * @param  bool  $whole  whether a rule key sets the whole array, in the order of the input
     * @param  list<array{0: string, 1: list<string>, 2: bool}>  $touches  the segment, the rest and whether Laravel skips the array, of each rule key in rule order
     * @param  RuleOrderPlan|null  $star  the plan of each key that `*` reaches
     * @param  array<array-key, RuleOrderPlan>  $own  the plan of each key that a rule key names
     * @param  list<string>|null  $keys  the keys in the order Laravel sets them, when no rule key holds `*` here and the rule keys of each key follow each other
     */
    public function __construct(
        public ?array $fields,
        public bool $whole = false,
        public array $touches = [],
        public ?RuleOrderPlan $star = null,
        public array $own = [],
        public ?array $keys = null,
    ) {}
}
