<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Closure;

/**
 * Wraps a compiled fast check so it reads its value from a path inside the
 * item: `['_v']` for a scalar each, `['style', 'top']` for a nested field.
 * Laravel escapes literal dots in data keys, so a dotted rule key only ever
 * matches the nested path; a missing segment reads as null, as in Laravel.
 *
 * @internal Implementation detail of `ItemRuleCompiler::buildFastChecks`.
 */
final class PathFastCheck
{
    /**
     * @param  list<string>  $path
     * @param  (Closure(mixed): bool)|null  $valueCheck
     * @param  (Closure(mixed, array<string, mixed>): bool)|null  $itemAwareCheck
     * @return Closure(array<string, mixed>): bool
     */
    public static function build(array $path, ?Closure $valueCheck, ?Closure $itemAwareCheck): Closure
    {
        if ($itemAwareCheck instanceof Closure) {
            return static fn (array $data): bool => $itemAwareCheck(self::read($data, $path), $data);
        }

        if (! $valueCheck instanceof Closure) {
            return static fn (array $data): bool => false;
        }

        return static fn (array $data): bool => $valueCheck(self::read($data, $path));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $path
     */
    private static function read(array $data, array $path): mixed
    {
        $value = $data;

        foreach ($path as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
