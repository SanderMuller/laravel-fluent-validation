<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests\Fixtures;

use SanderMuller\FluentValidation\FluentFormRequest;
use SanderMuller\FluentValidation\FluentRule;

/**
 * An explicit key before the `each()` rule that also matches it. Laravel
 * adds the item rules after the explicit rule, so `string` runs before the
 * exclude rule and can fail although the item is then excluded.
 *
 * @internal
 */
final class ExcludeAfterOtherRuleFluentFormRequest extends FluentFormRequest implements DeclaresFluentRules
{
    /** @return array<string, mixed> */
    public static function fluentRules(): array
    {
        return [
            'items.0' => 'string',
            'items' => FluentRule::array()->required()->each(
                FluentRule::field()->excludeUnless('items.*.status', 'keep')->required(),
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::fluentRules();
    }
}
