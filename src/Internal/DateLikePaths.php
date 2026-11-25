<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Exception;
use Illuminate\Support\Facades\Date;

/**
 * Remembers which strings Laravel's getDateTime() reads as a date. A date
 * rule reads its field parameter as a date when the path that Laravel
 * resolves, such as `x.now.from`, parses as one. The answer depends on the
 * string alone, so it holds across validations.
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

    public static function parses(string $value): bool
    {
        if (isset(self::$parses[$value])) {
            return self::$parses[$value];
        }

        if (count(self::$parses) >= self::CACHE_MAX) {
            self::$parses = [];
        }

        return self::$parses[$value] = self::parse($value);
    }

    /** Mirrors Laravel's getDateTime() for a string. */
    private static function parse(string $value): bool
    {
        // Date::parse() reads the formats that strtotime() reads, and the
        // strings that Carbon changes before it parses them. A string that is
        // neither cannot be a date, and Date::parse() would throw for it.
        if (strtotime($value) === false && ! self::carbonChanges($value)) {
            return false;
        }

        try {
            return (bool) @Date::parse($value);
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Carbon reads `@…` and most numeric strings as timestamps, and an empty
     * string as now. When the parse fails, it trims the string, replaces
     * ` at `, `just now`, `after tomorrow` and `before yesterday`, and
     * rewrites `next 5pm`, then tries again.
     */
    private static function carbonChanges(string $value): bool
    {
        return $value === ''
            || $value[0] === '@'
            || is_numeric($value)
            || trim($value) !== $value
            || preg_match('/ at |just now|after tomorrow|before yesterday|^(?:next|previous|last)\s/i', $value) === 1;
    }
}
