<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

/**
 * Laravel looks up the label and messages of a field with an escaped dot
 * (`rows.a\.b`) or an asterisk placeholder by its decoded name (`rows.a.b`,
 * `rows.c*d`).
 *
 * @internal
 */
final class EscapedFieldNames
{
    /**
     * Keys the rules by the decoded field names. When another rule already has
     * the decoded name, the escaped field is left out, so it does not give its
     * metadata to that rule.
     *
     * @template TRule
     *
     * @param  array<array-key, TRule>  $rules
     * @return array<array-key, TRule>
     */
    public static function decode(array $rules): array
    {
        $keys = implode("\n", array_keys($rules));
        $hasAsterisk = str_contains($keys, '__asterisk__');

        if (! $hasAsterisk && ! str_contains($keys, '\\.')) {
            return $rules;
        }

        $search = $hasAsterisk ? ['\\.', AsteriskPlaceholder::value()] : ['\\.'];
        $replace = $hasAsterisk ? ['.', '*'] : ['.'];

        foreach ($rules as $field => $rule) {
            $decoded = str_replace($search, $replace, (string) $field);

            if ($decoded !== (string) $field) {
                unset($rules[$field]);
                $rules[$decoded] ??= $rule;
            }
        }

        return $rules;
    }

    /**
     * The data keys of a rule key: the parts between the dots that are not
     * escaped, with each `\.` decoded to a dot.
     *
     * @return list<string>
     */
    public static function segments(string $ruleKey): array
    {
        if (! str_contains($ruleKey, '\\')) {
            return explode('.', $ruleKey);
        }

        return array_map(static fn (string $segment): string => str_replace("\0", '.', $segment), explode('.', str_replace('\\.', "\0", $ruleKey)));
    }
}
