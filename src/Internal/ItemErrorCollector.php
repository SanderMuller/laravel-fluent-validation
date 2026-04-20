<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

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
     * @param  list<\Closure(array<string, mixed>): bool>  $fastChecks
     * @param  array<string, mixed>  $itemData
     */
    public function passesAllFastChecks(array $fastChecks, array $itemData): bool
    {
        foreach ($fastChecks as $fastCheck) {
            if (! $fastCheck($itemData)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Append errors from a failed per-item validator into the accumulator,
     * keyed by full dotted path (`{parent}.{index}.{field}` or `{parent}.{index}` for scalar each).
     *
     * @param  array<string, list<string>>  $errors
     */
    public function collectErrors(Validator $validator, string $parent, int|string $index, bool $isScalar, array &$errors): void
    {
        /** @var array<string, list<string>> $itemErrors */
        $itemErrors = $validator->errors()->toArray();
        foreach ($itemErrors as $field => $fieldErrors) {
            $fullPath = $isScalar ? "{$parent}.{$index}" : "{$parent}.{$index}.{$field}";
            $errors[$fullPath] = $fieldErrors;
        }
    }
}
