<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests\Fixtures;

use SanderMuller\FluentValidation\FluentFormRequest;
use SanderMuller\FluentValidation\FluentRule;

/**
 * An explicit key inside an item that the rule on each item can exclude.
 * Pins that `validated()` keeps the parent array when every item is
 * excluded, as Laravel does.
 *
 * @internal
 */
final class ExplicitKeyInExcludedItemFluentFormRequest extends FluentFormRequest implements DeclaresFluentRules
{
    /** @return array<string, mixed> */
    public static function fluentRules(): array
    {
        return [
            'items' => FluentRule::array()->required()->each(
                FluentRule::array()->excludeUnless('items.*.status', 'keep'),
            ),
            'items.0.note' => FluentRule::string()->nullable(),
        ];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::fluentRules();
    }
}
