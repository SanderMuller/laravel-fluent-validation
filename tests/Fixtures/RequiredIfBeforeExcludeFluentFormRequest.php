<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests\Fixtures;

use SanderMuller\FluentValidation\FluentFormRequest;
use SanderMuller\FluentValidation\FluentRule;

/**
 * `requiredIf()` and `excludeUnless()` on the same field, with values where
 * both fire. FluentRule writes `required_if` first, so Laravel reports the
 * required error before it excludes the field. The package evaluates the
 * exclude rule first and reports nothing.
 *
 * @internal
 */
final class RequiredIfBeforeExcludeFluentFormRequest extends FluentFormRequest implements DeclaresFluentRules
{
    /** @return array<string, mixed> */
    public static function fluentRules(): array
    {
        $type = 'items.*.type';

        return [
            'items' => FluentRule::array()->required()->each([
                'type' => FluentRule::string()->required(),
                'email' => FluentRule::string()->nullable()->requiredIf($type, 'share', 'link')->excludeUnless($type, 'share'),
            ]),
        ];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::fluentRules();
    }
}
