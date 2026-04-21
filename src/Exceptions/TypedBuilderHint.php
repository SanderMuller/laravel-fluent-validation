<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Exceptions;

use ReflectionClass;
use ReflectionMethod;
use SanderMuller\FluentValidation\Rules\AcceptedRule;
use SanderMuller\FluentValidation\Rules\ArrayRule;
use SanderMuller\FluentValidation\Rules\BooleanRule;
use SanderMuller\FluentValidation\Rules\DateRule;
use SanderMuller\FluentValidation\Rules\EmailRule;
use SanderMuller\FluentValidation\Rules\FieldRule;
use SanderMuller\FluentValidation\Rules\FileRule;
use SanderMuller\FluentValidation\Rules\ImageRule;
use SanderMuller\FluentValidation\Rules\NumericRule;
use SanderMuller\FluentValidation\Rules\PasswordRule;
use SanderMuller\FluentValidation\Rules\StringRule;

/**
 * Maps method names to hints pointing at the typed builder(s) that
 * actually expose them. Used by `UnknownFluentRuleMethod` to turn a
 * silent runtime fatal on `FluentRule::field()->{method}(...)` into
 * an actionable error, and by `BansFieldRuleTypeMethods` as the
 * banned call set for the opt-in Pest/PHPUnit arch helper.
 *
 * The primary method list is derived by reflection at runtime — every
 * public method on every typed builder (`StringRule`, `NumericRule`, …)
 * that is not already present on `FieldRule` is a footgun candidate.
 * That way, new methods added to any typed builder are automatically
 * covered without manual list maintenance.
 *
 * A small hand-curated list of Laravel-rule-string aliases
 * (`gt`, `lt`, `size`, `alphaNum`) is added on top — these method names
 * don't exist on any typed builder in this package (the fluent API
 * renames them to `greaterThan`, `lessThan`, `exactly`, `alphaNumeric`)
 * but users coming from Laravel's native rule syntax may try them.
 *
 * @api
 */
final class TypedBuilderHint
{
    /**
     * Typed builder class → factory method name on `FluentRule`.
     * The key set is authoritative for reflection; the value is the
     * factory name inserted into generated hints.
     */
    private const TYPED_BUILDERS = [
        StringRule::class => 'string',
        NumericRule::class => 'numeric',
        DateRule::class => 'date',
        ArrayRule::class => 'array',
        FileRule::class => 'file',
        ImageRule::class => 'image',
        BooleanRule::class => 'boolean',
        AcceptedRule::class => 'accepted',
        PasswordRule::class => 'password',
        EmailRule::class => 'email',
    ];

    /**
     * Laravel-native rule names with no direct method on any typed
     * builder (the fluent API renames them). Banned for arch purposes
     * so users migrating from rule strings get a clear hint rather
     * than an unhelpful generic fallback.
     */
    private const LARAVEL_ALIAS_ONLY = ['gt', 'gte', 'lt', 'lte', 'size', 'alphaNum'];

    /** @var array<string, list<string>>|null cached method → factory names */
    private static ?array $methodMap = null;

    /**
     * Every method name that belongs on a typed builder (not on
     * `FieldRule`), plus Laravel-rule-string aliases that the fluent API
     * renames. Used by the arch helper as the banned call set.
     *
     * @return list<string>
     */
    public static function knownMethods(): array
    {
        $derived = array_keys(self::methodMap());
        $extras = array_diff(self::LARAVEL_ALIAS_ONLY, $derived);

        return [...$derived, ...$extras];
    }

    public static function for(string $method): ?string
    {
        $special = self::specialCase($method);
        if ($special !== null) {
            return $special;
        }

        $factories = self::methodMap()[$method] ?? null;
        if ($factories === null) {
            return null;
        }

        return sprintf(
            'Use %s and chain `->%s(...)`.',
            self::formatFactoryList($factories),
            $method,
        );
    }

    /**
     * Hints that deviate from the generic "Use FluentRule::X()->method(...)"
     * template — either because the package renames the Laravel rule
     * (`size` → `exactly`) or because the typed builder has a documented
     * footgun that a naive hint would send users into (`accepted`).
     */
    private static function specialCase(string $method): ?string
    {
        return match ($method) {
            'accepted' => 'Use `FluentRule::accepted()` for permissive accepted values (`\'yes\'`, `\'on\'`, `\'1\'`, `true`). Avoid `FluentRule::boolean()->accepted()` — the `boolean` base rule rejects `\'yes\'`/`\'on\'`.',
            'declined' => "Use `FluentRule::boolean()->declined(...)` for strict boolean-only input. If HTML form values (`'no'`, `'off'`) need to pass, use `->rule('declined')` on the untyped builder instead.",
            'size' => 'No `size()` method — use `->exactly(...)` on a typed builder. (This package renames Laravel\'s `size:` rule to `exactly()` for clarity.)',
            'gt', 'gte', 'lt', 'lte' => 'No `gt()`/`lt()` methods — use `FluentRule::numeric()->greaterThan(FIELD)`, `->greaterThanOrEqualTo(FIELD)`, `->lessThan(FIELD)`, `->lessThanOrEqualTo(FIELD)`.',
            'alphaNum' => 'No `alphaNum()` method — use `FluentRule::string()->alphaNumeric(...)`.',
            'contains' => 'Use `FluentRule::array()->contains(...)` (this package exposes `contains` on `array()`, not `string()`).',
            'format' => 'Use `FluentRule::date()->format(...)` for date format; for string-format regex use `FluentRule::string()->regex(...)`.',
            default => null,
        };
    }

    /**
     * method name → list of factory names (e.g. "numeric", "string") that
     * expose it on the corresponding typed builder. Excludes methods
     * already available on `FieldRule` (shared modifiers, embedded-rule
     * factories, `same`/`different`/`confirmed`, etc.).
     *
     * @return array<string, list<string>>
     */
    private static function methodMap(): array
    {
        if (self::$methodMap !== null) {
            return self::$methodMap;
        }

        $fieldMethods = self::publicInstanceMethods(FieldRule::class);

        $map = [];
        foreach (self::TYPED_BUILDERS as $class => $factory) {
            foreach (array_keys(self::publicInstanceMethods($class)) as $method) {
                if (isset($fieldMethods[$method])) {
                    continue;
                }

                $map[$method][] = $factory;
            }
        }

        ksort($map);

        return self::$methodMap = $map;
    }

    /**
     * @param  class-string  $class
     * @return array<string, true>
     */
    private static function publicInstanceMethods(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $names = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic()) {
                continue;
            }
            if ($method->isConstructor()) {
                continue;
            }
            $name = $method->getName();
            if (str_starts_with($name, '__')) {
                continue;
            }

            $names[$name] = true;
        }

        return $names;
    }

    /**
     * @param  list<string>  $factories
     */
    private static function formatFactoryList(array $factories): string
    {
        $formatted = array_map(
            static fn (string $factory): string => sprintf('`FluentRule::%s()`', $factory),
            $factories,
        );

        if (count($formatted) === 1) {
            return $formatted[0];
        }

        $last = array_pop($formatted);

        return implode(', ', $formatted) . ', or ' . $last;
    }
}
