<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Rules\Concerns;

use ArrayObject;
use Illuminate\Validation\Validator;
use ReflectionProperty;
use WeakMap;

/**
 * Gives an outer validator the keys of the nested rules that a rule object
 * validated with its own inner validator.
 *
 * With excludeUnvalidatedArrayKeys, Laravel's validated() keeps only the keys
 * it has rules for, and leaves out an `array` or `list` field that has rules
 * under it. Without the nested keys, the outer validator keeps the full array.
 *
 * The keys are added once, after the outer validator ran all its rules and
 * before its other after() callbacks. One write for each item would copy all
 * outer rules for each item.
 *
 * @internal Collaborator of {@see SelfValidates}.
 */
final class NestedRuleKeys
{
    /** @var WeakMap<Validator, ArrayObject<string, list<string>>>|null */
    private static ?WeakMap $pending = null;

    /** @param  array<string, list<mixed>>  $innerRules */
    public static function add(Validator $outer, array $innerRules): void
    {
        self::$pending ??= new WeakMap();

        if (! isset(self::$pending[$outer])) {
            /** @var ArrayObject<string, list<string>> $keys */
            $keys = new ArrayObject();
            self::$pending[$outer] = $keys;
            // First, so the caller's after() callbacks see the same
            // validated() as with native rules.
            $after = new ReflectionProperty($outer, 'after');

            /** @var list<callable(): mixed> $callbacks */
            $callbacks = $after->getValue($outer);
            $after->setValue($outer, [static fn () => self::apply($outer, $keys), ...$callbacks]);
        }

        $keys = self::$pending[$outer];

        foreach ($innerRules as $key => $rules) {
            $shapeRules = $keys[$key] ?? [];

            foreach ($rules as $rule) {
                if (($rule === 'array' || $rule === 'list') && ! in_array($rule, $shapeRules, true)) {
                    $shapeRules[] = $rule;
                }
            }

            $keys[$key] = $shapeRules;
        }
    }

    /** @param  ArrayObject<string, list<string>>  $keys */
    private static function apply(Validator $validator, ArrayObject $keys): void
    {
        $property = new ReflectionProperty($validator, 'rules');

        /** @var array<string, list<mixed>> $rules */
        $rules = $property->getValue($validator);

        foreach ($keys as $key => $shapeRules) {
            $existing = $rules[$key] ?? [];

            foreach ($shapeRules as $shapeRule) {
                if (! in_array($shapeRule, $existing, true)) {
                    $existing[] = $shapeRule;
                }
            }

            $rules[$key] = $existing;
        }

        $property->setValue($validator, $rules);

        // The next passes() collects the keys for its own data.
        $keys->exchangeArray([]);
    }
}
