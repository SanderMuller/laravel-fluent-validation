<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Illuminate\Validation\Validator;
use ReflectionObject;

/**
 * Copies factory-applied state from a base {@see Validator} (built through the
 * ValidationFactory) onto a target subclass built with `new`.
 *
 * This lets the package instantiate its own validator subclasses
 * (OptimizedValidator, MemoizingValidator) directly —
 * bypassing the factory's resolver — while still inheriting the exact setup the
 * factory applies: the pre-exploded rules, the container (so `exists`/`unique`
 * can lazily resolve a presence verifier), any eagerly-set presence verifier,
 * registered extensions/replacers, `excludeUnvalidatedArrayKeys`, and fallback
 * messages.
 *
 * Not mutating the shared factory resolver is deliberate — it keeps the swap
 * Octane-safe, where a resolver rebind would leak across requests.
 *
 * @internal
 */
final class ValidatorStateCopier
{
    /**
     * Properties declared on {@see Validator} that carry factory-applied setup.
     * The pre-exploded `rules`/`initialRules` are copied so the target skips
     * re-parsing (and `setData()` re-explodes from the copied `initialRules`).
     * `implicitAttributes` is the wildcard-expansion metadata that pairs with
     * those rules — dropping it would break `:attribute` naming and column
     * derivation for a base built from an unexpanded (nested-wildcard) rule set.
     *
     * @var list<string>
     */
    private const COPIED_PROPERTIES = [
        'rules',
        'initialRules',
        'implicitAttributes',
        'container',
        'presenceVerifier',
        'excludeUnvalidatedArrayKeys',
        'extensions',
        'implicitExtensions',
        'dependentExtensions',
        'replacers',
        'fallbackMessages',
    ];

    public static function copy(Validator $from, Validator $to): void
    {
        $reflection = new ReflectionObject($from);

        foreach (self::COPIED_PROPERTIES as $property) {
            if (! $reflection->hasProperty($property)) {
                continue;
            }

            $reflected = $reflection->getProperty($property);
            $value = $reflected->getValue($from);

            // Skip empty defaults so the target keeps its own (identical)
            // initial value rather than being handed an equivalent blank.
            if (! in_array($value, [null, [], false], true)) {
                $reflected->setValue($to, $value);
            }
        }
    }
}
