<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests\Fixtures;

use SanderMuller\FluentValidation\FluentFormRequest;
use SanderMuller\FluentValidation\FluentRule;

/**
 * A top-level exclude rule on `items.0` next to a top-level rule that fails.
 * Laravel still skips the rules below the excluded item.
 *
 * @internal
 */
final class ExcludedItemWithTopErrorFluentFormRequest extends FluentFormRequest implements DeclaresFluentRules
{
    /** @return array<string, mixed> */
    public static function fluentRules(): array
    {
        return [
            'flag' => FluentRule::integer()->nullable(),
            'note' => FluentRule::string()->nullable()->min(3),
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required(),
            ]),
            'items.0' => FluentRule::field()->excludeIf('flag', 1),
        ];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::fluentRules();
    }
}
