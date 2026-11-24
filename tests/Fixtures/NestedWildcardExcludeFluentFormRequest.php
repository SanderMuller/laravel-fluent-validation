<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests\Fixtures;

use SanderMuller\FluentValidation\FluentFormRequest;
use SanderMuller\FluentValidation\FluentRule;

/**
 * An exclude rule below a nested wildcard whose condition reads a field of
 * the outer item: `items.*.tags.*` on `excludeIf('items.*.locked', 1)`.
 *
 * @internal
 */
final class NestedWildcardExcludeFluentFormRequest extends FluentFormRequest implements DeclaresFluentRules
{
    /** @return array<string, mixed> */
    public static function fluentRules(): array
    {
        return [
            'items' => FluentRule::array()->required()->each([
                'locked' => FluentRule::integer()->required(),
                'tags' => FluentRule::array()->nullable()->each(
                    FluentRule::integer()->excludeIf('items.*.locked', 1),
                ),
            ]),
        ];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::fluentRules();
    }
}
