<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

/**
 * Laravel looks up the label and messages of a field with an escaped dot
 * (`rows.a\.b`) by its decoded name (`rows.a.b`). When another rule has that
 * decoded name, the escaped field must not give its metadata to that rule.
 *
 * @internal
 */
final class ShadowedEscapedFields
{
    /**
     * @template TRule
     *
     * @param  array<array-key, TRule>  $rules
     * @return array<array-key, TRule>
     */
    public static function remove(array $rules): array
    {
        foreach (array_keys($rules) as $field) {
            $decoded = str_replace('\\.', '.', (string) $field);

            if ($decoded !== (string) $field && array_key_exists($decoded, $rules)) {
                unset($rules[$field]);
            }
        }

        return $rules;
    }
}
