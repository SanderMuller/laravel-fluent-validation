<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Closure;
use Stringable;

/**
 * Derives value-predicates from string-form type rules (integer, numeric,
 * uuid, ulid, string) and applies them to batched values.
 *
 * Extracted from `BatchDatabaseChecker` to keep that class focused on
 * query batching. See `BatchDatabaseChecker::filterValuesByType()` for
 * the public entry point.
 *
 * @internal
 */
final class ValueTypePredicates
{
    /**
     * @param  array<mixed>  $values
     * @param  array<mixed>|string  $itemRules
     * @return array<int, mixed>
     */
    public static function filter(array $values, array|string $itemRules): array
    {
        $predicates = self::derive($itemRules);

        if ($predicates === []) {
            return array_values($values);
        }

        return array_values(array_filter($values, static function (mixed $v) use ($predicates): bool {
            foreach ($predicates as $predicate) {
                if (! $predicate($v)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * @param  array<mixed>|string  $itemRules
     * @return list<Closure(mixed): bool>
     */
    private static function derive(array|string $itemRules): array
    {
        $predicates = [];

        foreach (self::stringRules($itemRules) as $rule) {
            $predicate = self::predicateFor($rule);

            if ($predicate instanceof Closure) {
                $predicates[] = $predicate;
            }
        }

        return $predicates;
    }

    /**
     * @param  array<mixed>|string  $itemRules
     * @return list<string>
     */
    private static function stringRules(array|string $itemRules): array
    {
        if (is_string($itemRules)) {
            return explode('|', $itemRules);
        }

        $strings = [];

        foreach ($itemRules as $rule) {
            if (is_string($rule)) {
                $strings[] = $rule;
            }
        }

        return $strings;
    }

    private static function predicateFor(string $rule): ?Closure
    {
        // `integer:strict` requires `is_int($v)` semantics — numeric strings
        // like "42" must NOT pass the type filter so they don't enter the
        // batched whereIn group on Laravel 12.23+ (where the outer validator
        // rejects them anyway). Match the strict variant before the plain
        // `integer`/`int` branch, which collapses params via `:` split.
        if ($rule === 'integer:strict') {
            return is_int(...);
        }

        $token = str_contains($rule, ':') ? substr($rule, 0, (int) strpos($rule, ':')) : $rule;

        return match ($token) {
            'integer', 'int' => static fn (mixed $v): bool => filter_var($v, FILTER_VALIDATE_INT) !== false,
            'numeric' => is_numeric(...),
            'uuid' => static fn (mixed $v): bool => is_string($v) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v) === 1,
            'ulid' => static fn (mixed $v): bool => is_string($v) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $v) === 1,
            'string' => static fn (mixed $v): bool => is_scalar($v) || $v instanceof Stringable,
            default => null,
        };
    }
}
