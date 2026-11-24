<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Closure;
use Illuminate\Validation\Validator;

/**
 * Collaborator for {@see ItemValidator}. Runs fast-check closures across
 * an item and harvests errors from Laravel validators into the per-item
 * error map keyed by full dotted path.
 *
 * @internal
 */
final class ItemErrorCollector
{
    /**
     * @param iterable<Closure(array<string, mixed>): bool> $fastChecks
     * @param  array<string, mixed>  $itemData
     */
    public function passesAllFastChecks(iterable $fastChecks, array $itemData): bool
    {
        foreach ($fastChecks as $fastCheck) {
            if (! $fastCheck($itemData)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The fields whose fast check fails. A failed merged check of
     * {@see ItemRuleCompiler::passChecks()} gives the fields of the checks
     * it replaced that fail.
     *
     * @param  array<array-key, Closure(array<string, mixed>): bool>  $fastChecks
     * @param  array<string, mixed>  $itemData
     * @param  array<array-key, Closure(array<string, mixed>): bool>  $merged
     * @param  array<array-key, Closure(array<string, mixed>): bool>  $replaced
     * @return list<array-key>
     */
    public static function failedFields(array $fastChecks, array $itemData, array $merged, array $replaced): array
    {
        $failed = [];

        foreach ($fastChecks as $field => $check) {
            if ($check($itemData)) {
                continue;
            }

            if (($merged[$field] ?? null) !== $check) {
                $failed[] = $field;

                continue;
            }

            foreach ($replaced as $replacedField => $replacedCheck) {
                if (! $replacedCheck($itemData)) {
                    $failed[] = $replacedField;
                }
            }
        }

        return $failed;
    }

    /**
     * Append errors from a failed per-item validator into the accumulator,
     * keyed by full dotted path (`{parent}.{index}.{field}` or `{parent}.{index}` for scalar each).
     * Returns whether an error is on `$stopAt`, the group's first rule key:
     * Laravel reports no later item before it.
     *
     * @param  array<string, list<string>>  $errors
     */
    public function collectErrors(Validator $validator, string $parent, int|string $index, bool $isScalar, array &$errors, ?string $stopAt = null): bool
    {
        /** @var array<string, list<string>> $itemErrors */
        $itemErrors = $validator->errors()->toArray();
        $reached = $isScalar && $stopAt !== null;

        foreach ($itemErrors as $field => $fieldErrors) {
            $fullPath = $isScalar ? "{$parent}.{$index}" : "{$parent}.{$index}.{$field}";
            $errors[$fullPath] = $fieldErrors;
            $reached = $reached || ($stopAt !== null && $this->isRule((string) $field, $stopAt));
        }

        return $reached;
    }

    private function isRule(string $field, string $ruleKey): bool
    {
        return $field === $ruleKey
            || (str_contains($ruleKey, '*') && preg_replace('/(?<=^|\.)\d+(?=\.|$)/', '*', $field) === $ruleKey);
    }
}
