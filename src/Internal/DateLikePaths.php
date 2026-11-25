<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

/**
 * Remembers which attribute paths `strtotime()` reads as a date. A date rule
 * reads its field parameter as a date when the path that Laravel resolves,
 * such as `x.now.from`, parses as one. The answer depends on the path alone,
 * so it holds across validations.
 *
 * @internal Implementation detail of `OutsideItemReferences` and `WildcardFastChecks`.
 */
final class DateLikePaths
{
    /**
     * Soft cap on the cache. Paths built from request keys could otherwise
     * grow it without bound on a long-lived worker.
     */
    private const CACHE_MAX = 4096;

    /** @var array<string, bool> */
    private static array $parses = [];

    public static function parses(string $path): bool
    {
        if (isset(self::$parses[$path])) {
            return self::$parses[$path];
        }

        if (count(self::$parses) >= self::CACHE_MAX) {
            self::$parses = [];
        }

        return self::$parses[$path] = strtotime($path) !== false;
    }
}
