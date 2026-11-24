<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Closure;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\NotIn;
use SanderMuller\FluentValidation\FastCheckCompiler;
use SanderMuller\FluentValidation\PresenceConditionalReducer;
use SanderMuller\FluentValidation\ValueConditionalReducer;

/**
 * What {@see ItemValidator} derives from a group's item rules before it reads
 * an item: the fast checks, the slow rules, the dispatch field, and the rules
 * reduced for each dispatch value. None of it reads the items, so a plan for
 * rules without objects is kept for the next validation of the same rules.
 *
 * @internal Implementation detail of `ItemValidator`.
 *
 * @phpstan-import-type ExcludedFields from WildcardValidatedData
 */
final class ItemRulePlan
{
    /**
     * Soft caps: a rule set or a dispatch value built from request data
     * could otherwise grow the caches without bound on a long-lived worker.
     */
    private const PLANS_MAX = 64;

    private const DISPATCH_MAX = 256;

    /** A longer dispatch value is request data the plan does not keep. */
    private const DISPATCH_KEY_MAX = 128;

    /** @var array<string, self> */
    private static array $plans = [];

    /** @var array<string, array{0: array<string, mixed>, 1: array<string, Closure(array<string, mixed>): bool>, 2: array<string, mixed>, 3: ExcludedFields, 4: Closure(array<string, mixed>): bool}> */
    private array $byDispatch = [];

    /** @var (Closure(array<string, mixed>): bool)|null */
    private ?Closure $passAll = null;

    /** Is the plan kept for the next call? */
    private bool $kept = false;

    /**
     * @param  array<string, mixed>  $itemRules  the item rules, with plain rule lists joined
     * @param  array<string, list<array{action: string, field: string, values: list<string>}>>  $conditionalFields
     * @param  array<string, string>  $itemMessages
     * @param  array<string, true>  $siblingFields  the fields with a presence or value conditional
     * @param  array<array-key, Closure(array<string, mixed>): bool>  $passChecks  {@see ItemRuleCompiler::passChecks()}
     * @param  array<string, mixed>  $slowRules
     * @param  Closure(array<string, mixed>, array<array-key, Closure(array<string, mixed>): bool>, array<string, mixed>, array<string, mixed>): array<string, mixed>  $laravelFields
     */
    private function __construct(
        private readonly ItemRuleCompiler $compiler,
        public readonly array $itemRules,
        public readonly array $conditionalFields,
        public readonly array $itemMessages,
        public readonly array $siblingFields,
        public readonly ?string $dispatchField,
        public readonly array $passChecks,
        public readonly array $slowRules,
        public readonly Closure $laravelFields,
    ) {}

    /**
     * @param  array<string, mixed>  $itemRules
     * @param  array<string, list<array{action: string, field: string, values: list<string>}>>  $conditionalFields
     * @param  array<string, string>  $itemMessages
     */
    public static function for(ItemRuleCompiler $compiler, array $itemRules, array $conditionalFields, array $itemMessages, bool $isScalar): self
    {
        $key = self::cacheKey($itemRules, $conditionalFields, $itemMessages, $isScalar);

        if ($key === null) {
            return self::build($compiler, $itemRules, $conditionalFields, $itemMessages, $isScalar);
        }

        if (! isset(self::$plans[$key]) && count(self::$plans) >= self::PLANS_MAX) {
            self::$plans = [];
        }

        if (! isset(self::$plans[$key])) {
            self::$plans[$key] = self::build($compiler, $itemRules, $conditionalFields, $itemMessages, $isScalar);
            self::$plans[$key]->kept = true;
        }

        return self::$plans[$key];
    }

    /**
     * The reduced rules, their fast checks, their slow rules, the fields an
     * exclude rule removed and one check for all fast checks, for the items
     * with this dispatch value.
     *
     * @param  array<string, mixed>  $itemData  an item with this dispatch value
     * @return array{0: array<string, mixed>, 1: array<string, Closure(array<string, mixed>): bool>, 2: array<string, mixed>, 3: ExcludedFields, 4: Closure(array<string, mixed>): bool}
     */
    public function forDispatch(string $value, array $itemData): array
    {
        if (isset($this->byDispatch[$value])) {
            return $this->byDispatch[$value];
        }

        $reduced = $this->reduced(
            $this->compiler->reduceRulesForItem($this->itemRules, $itemData, $this->conditionalFields, $this->itemMessages, $this->siblingFields),
        );

        if (strlen($value) > self::DISPATCH_KEY_MAX) {
            return $reduced;
        }

        if (count($this->byDispatch) >= self::DISPATCH_MAX) {
            $this->byDispatch = [];
        }

        return $this->byDispatch[$value] = $reduced;
    }

    /**
     * Does an item pass every check of {@see $passChecks}?
     *
     * @return Closure(array<string, mixed>): bool
     */
    public function passAll(): Closure
    {
        return $this->passAll ??= $this->passAllOf($this->itemRules, $this->passChecks);
    }

    /**
     * {@see forDispatch()} for rules reduced without a dispatch value.
     *
     * @param  array<string, mixed>  $rules
     * @return array{0: array<string, mixed>, 1: array<string, Closure(array<string, mixed>): bool>, 2: array<string, mixed>, 3: ExcludedFields, 4: Closure(array<string, mixed>): bool}
     */
    public function reduced(array $rules): array
    {
        [$fastChecks, $slowRules] = $this->compiler->buildFastChecks($rules);

        return [$rules, $fastChecks, $slowRules, WildcardValidatedData::excludedTree(array_diff_key($this->conditionalFields, $rules)), $this->passAllOf($rules, $fastChecks)];
    }

    /**
     * One check for the question whether an item passes all the checks. In
     * a kept plan, a field's check that only reads the field's value runs
     * without the closure around it, which saves a call per field and item.
     * A plan for one call does not repay the extra arrays and closures.
     *
     * @param  array<string, mixed>  $rules
     * @param  array<array-key, Closure(array<string, mixed>): bool>  $checks
     * @return Closure(array<string, mixed>): bool
     */
    private function passAllOf(array $rules, array $checks): Closure
    {
        if (! $this->kept) {
            return $this->allPass([], $checks);
        }

        $valueChecks = [];
        $others = [];

        foreach ($checks as $field => $check) {
            $valueCheck = $this->valueCheck($rules, $field);

            if ($valueCheck instanceof Closure) {
                $valueChecks[$field] = $valueCheck;
            } else {
                $others[] = $check;
            }
        }

        return $this->allPass($valueChecks, $others);
    }

    /**
     * The check of the field's value alone, or null when the field's check
     * reads more. A date rule compiles anew on each call, so it keeps its
     * own closure.
     *
     * @param  array<string, mixed>  $rules
     * @return (Closure(mixed): bool)|null
     */
    private function valueCheck(array $rules, int|string $field): ?Closure
    {
        $rule = is_string($field) && $field !== '*' && ! str_contains($field, '.') ? $rules[$field] ?? null : null;

        return is_string($rule) && ! self::readsTheClock($rule) ? FastCheckCompiler::compile($rule) : null;
    }

    /**
     * @param  array<array-key, Closure(mixed): bool>  $valueChecks  per field
     * @param  array<array-key, Closure(array<string, mixed>): bool>  $checks
     * @return Closure(array<string, mixed>): bool
     */
    private function allPass(array $valueChecks, array $checks): Closure
    {
        return static function (array $data) use ($valueChecks, $checks): bool {
            foreach ($valueChecks as $field => $valueCheck) {
                if (! $valueCheck($data[$field] ?? null)) {
                    return false;
                }
            }

            foreach ($checks as $check) {
                if (! $check($data)) {
                    return false;
                }
            }

            return true;
        };
    }

    /**
     * @param  array<string, mixed>  $itemRules
     * @param  array<string, list<array{action: string, field: string, values: list<string>}>>  $conditionalFields
     * @param  array<string, string>  $itemMessages
     */
    private static function build(ItemRuleCompiler $compiler, array $itemRules, array $conditionalFields, array $itemMessages, bool $isScalar): self
    {
        $itemRules = PlainRuleJoiner::joinAll($itemRules, $conditionalFields);
        $siblingFields = PresenceConditionalReducer::fields($itemRules) + ValueConditionalReducer::fields($itemRules);
        [$fastChecks, $slowRules] = $compiler->buildFastChecks($itemRules);
        $passChecks = $compiler->passChecks($itemRules, $fastChecks);

        return new self(
            $compiler,
            $itemRules,
            $conditionalFields,
            $itemMessages,
            $siblingFields,
            DispatchField::find($compiler, $conditionalFields, $itemRules),
            $passChecks,
            $slowRules,
            self::laravelFieldFilter($isScalar, $itemRules, $fastChecks, $passChecks),
        );
    }

    /**
     * The key of a plan, or null when the plan must not outlive the call: an
     * object id can return for another object once the first one is freed,
     * and a date rule such as `after:today` compiles the current time into
     * its fast check, see {@see FastCheckCompiler::compile()}.
     *
     * @param  array<string, mixed>  $itemRules
     * @param  array<string, list<array{action: string, field: string, values: list<string>}>>  $conditionalFields
     * @param  array<string, string>  $itemMessages
     */
    private static function cacheKey(array $itemRules, array $conditionalFields, array $itemMessages, bool $isScalar): ?string
    {
        foreach ($itemRules as $rule) {
            if (! is_string($rule) && (! is_array($rule) || ! self::holdsScalarsOnly($rule))) {
                return null;
            }
        }

        $key = serialize([$itemRules, $conditionalFields, $itemMessages, $isScalar]);

        return self::readsTheClock($key) ? null : $key;
    }

    /**
     * Can the text hold a date rule? A false match only costs speed.
     */
    private static function readsTheClock(string $text): bool
    {
        return str_contains($text, 'after') || str_contains($text, 'before') || str_contains($text, 'date_equals');
    }

    /**
     * Does the rule hold only values that serialize() writes in full? That
     * is a scalar, or Laravel's own `in` or `not_in` rule: its values decide
     * all it does.
     *
     * @param  array<array-key, mixed>  $rule
     */
    private static function holdsScalarsOnly(array $rule): bool
    {
        foreach ($rule as $part) {
            $plain = match (true) {
                is_array($part) => self::holdsScalarsOnly($part),
                is_object($part) => $part::class === In::class || $part::class === NotIn::class,
                default => true,
            };

            if (! $plain) {
                return false;
            }
        }

        return true;
    }

    /**
     * Picks the rules Laravel runs for an item that failed a fast check. A
     * field whose fast check passed has no errors, so Laravel gets the failed
     * fields, the slow rules, and the fields whose rules those read. When a
     * rule can read any field, all rules go.
     *
     * @param  array<string, mixed>  $itemRules
     * @param  array<array-key, Closure(array<string, mixed>): bool>  $fieldChecks
     * @param  array<array-key, Closure(array<string, mixed>): bool>  $passChecks
     * @return Closure(array<string, mixed>, array<array-key, Closure(array<string, mixed>): bool>, array<string, mixed>, array<string, mixed>): array<string, mixed>
     */
    private static function laravelFieldFilter(bool $isScalar, array $itemRules, array $fieldChecks, array $passChecks): Closure
    {
        if ($isScalar || FieldLocalRules::canReadAnyField($itemRules)) {
            return static fn (array $rules): array => $rules;
        }

        $fieldLocalRules = new FieldLocalRules();
        $merged = array_diff_key($passChecks, $fieldChecks);
        $replaced = array_diff_key($fieldChecks, $passChecks);

        return static function (array $rules, array $fastChecks, array $slowRules, array $itemData) use ($fieldLocalRules, $merged, $replaced): array {
            $pending = [...array_keys($slowRules), ...ItemErrorCollector::failedFields($fastChecks, $itemData, $merged, $replaced)];

            $keep = [];

            while ($pending !== []) {
                $field = array_pop($pending);

                if (isset($keep[$field]) || ! array_key_exists($field, $rules)) {
                    continue;
                }

                $keep[$field] = true;
                $pending = [...$pending, ...$fieldLocalRules->references($rules[$field])];
            }

            return array_intersect_key($rules, $keep);
        };
    }
}
