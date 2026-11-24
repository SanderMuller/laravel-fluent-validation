<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests\Fixtures;

use SanderMuller\FluentValidation\FluentFormRequest;
use SanderMuller\FluentValidation\FluentRule;

/**
 * A rule on each item that reads a field of the item itself:
 * `each(FluentRule::array()->excludeIf('items.*.skip', 1))`. Pins that the
 * item is excluded, or kept, as Laravel decides it.
 *
 * @internal
 */
final class ItemFieldReferenceFluentFormRequest extends FluentFormRequest implements DeclaresFluentRules
{
    /** @return array<string, mixed> */
    public static function fluentRules(): array
    {
        return [
            'items' => FluentRule::array()->required()->each(
                FluentRule::array()->excludeIf('items.*.skip', 1)->min(2),
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::fluentRules();
    }
}
