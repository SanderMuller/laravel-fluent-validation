<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests\Fixtures;

use SanderMuller\FluentValidation\FluentFormRequest;
use SanderMuller\FluentValidation\FluentRule;

/**
 * Top-level exclude rules on a whole item (`items.0`) and on a key deep
 * inside another item (`items.1.lines.0`). Laravel skips the rules below
 * an excluded attribute.
 *
 * @internal
 */
final class ExcludedItemKeyFluentFormRequest extends FluentFormRequest implements DeclaresFluentRules
{
    /** @return array<string, mixed> */
    public static function fluentRules(): array
    {
        return [
            'flag' => FluentRule::integer()->nullable(),
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required(),
                'lines' => FluentRule::array()->nullable()->each([
                    'sku' => FluentRule::string()->required(),
                ]),
            ]),
            'items.0' => FluentRule::field()->excludeIf('flag', 1),
            'items.1.lines.0' => FluentRule::field()->excludeIf('flag', 1),
        ];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::fluentRules();
    }
}
