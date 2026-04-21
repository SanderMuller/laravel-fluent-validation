<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Exceptions;

/**
 * Maps common type-specific rule method names to a hint pointing at the
 * typed builder that actually exposes them. Used by `UnknownFluentRuleMethod`
 * to turn a silent runtime fatal into an actionable error.
 *
 * Returns `null` when the called method does not match any known
 * type-specific rule; callers fall back to a generic message.
 *
 * @internal
 */
final class TypedBuilderHint
{
    /**
     * Every method name covered by the hint table. Used both by `for()`
     * and by the arch-check helper to know which calls on the untyped
     * `FluentRule::field()` builder should be flagged as likely footguns.
     *
     * @return list<string>
     */
    public static function knownMethods(): array
    {
        return [
            'min', 'max', 'between',
            'exactly', 'size',
            'gt', 'gte', 'lt', 'lte',
            'digits', 'digitsBetween', 'decimal', 'multipleOf', 'integer',
            'email', 'url', 'uuid', 'ulid', 'ip', 'regex',
            'alpha', 'alphaDash', 'alphaNum',
            'startsWith', 'endsWith', 'lowercase', 'uppercase',
            'json', 'ascii', 'dateFormat',
            'contains',
            'before', 'after', 'beforeOrEqual', 'afterOrEqual',
            'nowOrFuture', 'nowOrPast', 'format',
            'accepted', 'declined',
            'mimes', 'mimetypes', 'extensions', 'dimensions',
        ];
    }

    public static function for(string $method): ?string
    {
        return match ($method) {
            'min', 'max', 'between' => sprintf(
                'Use `FluentRule::numeric()`, `::string()`, `::array()`, or `::file()` and chain `->%s(...)`.',
                $method,
            ),
            'exactly' => "Use `FluentRule::string()`, `::numeric()`, `::array()`, or `::file()` and chain `->exactly(...)`. (This is the fluent equivalent of Laravel's `size:` rule.)",
            'size' => 'No `size()` method — use `->exactly(...)` on a typed builder. (This package renames Laravel\'s `size:` rule to `exactly()` for clarity.)',
            'gt', 'gte', 'lt', 'lte' => 'No `gt()`/`lt()` methods — use `FluentRule::numeric()->greaterThan(FIELD)`, `->greaterThanOrEqualTo(FIELD)`, `->lessThan(FIELD)`, `->lessThanOrEqualTo(FIELD)`.',
            'digits', 'digitsBetween', 'decimal', 'multipleOf', 'integer' => sprintf(
                'Use `FluentRule::numeric()->%s(...)`.',
                $method,
            ),
            'email', 'url', 'uuid', 'ulid', 'ip', 'regex', 'alpha', 'alphaDash', 'startsWith', 'endsWith', 'lowercase', 'uppercase', 'json', 'ascii', 'dateFormat' => sprintf(
                'Use `FluentRule::string()->%s(...)`.',
                $method,
            ),
            'alphaNum' => 'No `alphaNum()` method — use `FluentRule::string()->alphaNumeric(...)`.',
            'contains' => 'Use `FluentRule::array()->contains(...)` (this package exposes `contains` on `array()`, not `string()`).',
            'before', 'after', 'beforeOrEqual', 'afterOrEqual', 'nowOrFuture', 'nowOrPast' => sprintf(
                'Use `FluentRule::date()->%s(...)`.',
                $method,
            ),
            'format' => 'Use `FluentRule::date()->format(...)` for date format; for string-format regex use `FluentRule::string()->regex(...)`.',
            'accepted' => 'Use `FluentRule::accepted()` for permissive accepted values (`\'yes\'`, `\'on\'`, `\'1\'`, `true`). Avoid `FluentRule::boolean()->accepted()` — the `boolean` base rule rejects `\'yes\'`/`\'on\'`.',
            'declined' => "Use `FluentRule::boolean()->declined(...)` for strict boolean-only input. If HTML form values (`'no'`, `'off'`) need to pass, use `->rule('declined')` on the untyped builder instead.",
            'mimes', 'mimetypes', 'extensions' => sprintf(
                'Use `FluentRule::file()->%s(...)`.',
                $method,
            ),
            'dimensions' => 'Use `FluentRule::image()->dimensions(...)`.',
            default => null,
        };
    }
}
