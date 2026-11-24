<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests\Fixtures;

use SanderMuller\FluentValidation\FluentFormRequest;
use SanderMuller\FluentValidation\FluentRule;

/**
 * An explicit key that gives one item field its own rule: `items.1.a` is
 * required, the `a` of the other items is not. Pins that the fast check of
 * the `each()` field does not run in place of the explicit rule.
 *
 * @internal
 */
final class ExplicitItemKeyRuleFluentFormRequest extends FluentFormRequest implements DeclaresFluentRules
{
    /** @return array<string, mixed> */
    public static function fluentRules(): array
    {
        return [
            'items' => FluentRule::array()->required()->each([
                'a' => FluentRule::array()->nullable(),
            ]),
            'items.1.a' => FluentRule::field()->required(),
        ];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::fluentRules();
    }
}
