<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

/**
 * Per-item validator cache key generator. The key must capture field names
 * AND effective rule content — per-item reducers (`PresenceConditionalReducer`,
 * `ValueConditionalReducer`) can produce different pipe-string content across
 * items that share the same field set (e.g. one item keeps
 * `required|exists:users,id`, the next has the conditional dropped to just
 * `exists:users,id`). Field-name-only keying would collide, causing the
 * second item to reuse the first's (immutable) `Validator` and apply the
 * wrong rule chain.
 *
 * @internal Implementation detail of `ItemValidator`.
 */
final class RuleCacheKey
{
    /** @param  array<string, mixed>  $rules */
    public static function for(array $rules): string
    {
        $parts = [];
        foreach ($rules as $field => $rule) {
            $parts[] = is_string($rule)
                ? $field . '=' . $rule
                : $field . '=' . self::nonStringFingerprint($rule);
        }

        return implode("\x1f", $parts);
    }

    /**
     * Fingerprint for non-string rules (ValidationRule objects, tuple arrays).
     * Arrays are walked one level so `['required_if', 'flag', 'admin']`-style
     * tuples fingerprint on their string slots; object entries fall back to
     * `spl_object_id` so two distinct instances don't collide.
     */
    private static function nonStringFingerprint(mixed $rule): string
    {
        if (is_array($rule)) {
            $parts = [];
            foreach ($rule as $item) {
                $parts[] = is_string($item)
                    ? $item
                    : (is_object($item) ? '#' . spl_object_id($item) : gettype($item));
            }

            return '[' . implode(',', $parts) . ']';
        }

        if (is_object($rule)) {
            return '#' . spl_object_id($rule);
        }

        return gettype($rule);
    }
}
