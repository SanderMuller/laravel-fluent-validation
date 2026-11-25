<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ConditionalRules;
use Illuminate\Validation\Factory;
use Illuminate\Validation\NestedRules;
use Stringable;

/**
 * Gives the keys and values of Laravel's `Validator::validated()` when it
 * leaves out the array keys no rule validates, without running a rule.
 * Laravel expands each wildcard rule over the data first, which costs more
 * than the per-item validation of a large array. The keys keep the order of
 * the input.
 *
 * @internal Implementation detail of `RuleSet`.
 */
final class NativeValidatedData
{
    /** The most rule trees {@see self::tree()} keeps. */
    private const TREE_CACHE_SIZE = 64;

    /** @var array<string, RuleKeyNode> */
    private static array $trees = [];

    /**
     * The validated data, or null when the factory keeps unvalidated array
     * keys, or a rule or data key takes a form this class does not read: an
     * escaped dot, a dot in a data key below a wildcard, a conditional rule
     * or nested rules.
     *
     * @param  array<string, mixed>  $rules  compiled, the rules Laravel ran
     * @param  array<string, mixed>  $data
     * @param  list<string>  $topLevelExcluded  the attributes the top-level validator excluded
     * @param  array<string, array<int|string, ExcludedFieldTree|bool>>  $excludedFields  per parent, per item index; true when the whole item is excluded
     * @return array<string, mixed>|null
     */
    public static function of(array $rules, array $data, array $topLevelExcluded, array $excludedFields): ?array
    {
        if (! self::factoryExcludesUnvalidatedKeys()) {
            return null;
        }

        $tree = self::tree($rules);

        if (! $tree instanceof RuleKeyNode) {
            return null;
        }

        // Laravel removes an excluded attribute and its rule first.
        $data = self::without(WildcardValidatedData::withoutExcluded($data, $excludedFields), $topLevelExcluded);

        return RuleKeyPruner::prune($tree, $data, ExcludedFieldTree::forData($topLevelExcluded, $excludedFields));
    }

    /**
     * The tree of the rule keys, kept per key and `array` flag, or null for
     * a rule this class does not read.
     *
     * @param  array<string, mixed>  $rules
     */
    private static function tree(array $rules): ?RuleKeyNode
    {
        $signature = '';

        foreach ($rules as $key => $rule) {
            $isArray = self::isArrayRule($rule);

            if ($isArray === null || str_contains((string) $key, '\\')) {
                return null;
            }

            $signature .= $key . ($isArray ? "\0a\0" : "\0f\0");
        }

        if (isset(self::$trees[$signature])) {
            return self::$trees[$signature];
        }

        if (count(self::$trees) >= self::TREE_CACHE_SIZE) {
            self::$trees = [];
        }

        $keys = [];

        foreach ($rules as $key => $rule) {
            $keys[] = [explode('.', (string) $key), self::isArrayRule($rule) === true];
        }

        return self::$trees[$signature] = RuleKeyNode::of($keys);
    }

    /**
     * The data without the attributes, as `Arr::forget()` removes them.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $attributes
     * @return array<string, mixed>
     */
    private static function without(array $data, array $attributes): array
    {
        foreach ($attributes as $attribute) {
            $dot = strpos($attribute, '.');

            if ($dot === false || array_key_exists($attribute, $data)) {
                unset($data[$attribute]);

                continue;
            }

            $head = substr($attribute, 0, $dot);
            $value = $data[$head] ?? null;

            if (is_array($value)) {
                Arr::forget($value, substr($attribute, $dot + 1));
                $data[$head] = $value;
            }
        }

        return $data;
    }

    /**
     * Does the validation factory leave out unvalidated array keys? That is
     * Laravel's default.
     */
    public static function factoryExcludesUnvalidatedKeys(): bool
    {
        $factory = Validator::getFacadeRoot();

        if ($factory instanceof Factory) {
            return (fn (): bool => $this->excludeUnvalidatedArrayKeys)->call($factory);
        }

        return Validator::make([], [])->excludeUnvalidatedArrayKeys;
    }

    /**
     * Does the rule hold `array` or `list` as Laravel reads it? Null for a
     * rule this class does not read.
     */
    private static function isArrayRule(mixed $rule): ?bool
    {
        if ($rule instanceof ConditionalRules || $rule instanceof NestedRules) {
            return null;
        }

        $parts = is_string($rule) ? explode('|', $rule) : (is_array($rule) ? $rule : [$rule]);

        foreach ($parts as $part) {
            if ($part instanceof ConditionalRules || $part instanceof NestedRules) {
                return null;
            }

            $name = is_string($part) ? $part : ($part instanceof Stringable ? (string) $part : null);

            if ($name === 'array' || $name === 'list') {
                return true;
            }
        }

        return false;
    }
}
