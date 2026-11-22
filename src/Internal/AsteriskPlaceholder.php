<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Illuminate\Support\Str;
use Illuminate\Validation\Validator;
use ReflectionProperty;

/**
 * Laravel stores a `*` in a data key as a placeholder, and reads a `*` in a
 * rule key as a wildcard. A rule for a data key such as `c*d` must hold the
 * placeholder, or Laravel matches no data and drops the rule.
 *
 * @internal
 */
final class AsteriskPlaceholder
{
    /**
     * Not cached: Validator::flushState() clears the hash, and the next
     * validator makes a new one.
     */
    public static function value(): string
    {
        $hash = new ReflectionProperty(Validator::class, 'placeholderHash');

        // The first validator sets the hash in the same way.
        if (! is_string($hash->getValue())) {
            $hash->setValue(null, Str::random());
        }

        /** @var string $value */
        $value = $hash->getValue();

        return '__asterisk__' . $value;
    }
}
