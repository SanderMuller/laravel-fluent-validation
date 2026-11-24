<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests\Fixtures;

use SanderMuller\FluentValidation\FluentFormRequest;
use SanderMuller\FluentValidation\FluentRule;

/**
 * A `list` parent with a rule on a field of each item. When the parent is
 * not a list, Laravel still validates the items. RuleSet skips the items
 * of a parent that failed, on purpose.
 *
 * @internal
 */
final class FailedListParentFluentFormRequest extends FluentFormRequest implements DeclaresFluentRules
{
    /** @return array<string, mixed> */
    public static function fluentRules(): array
    {
        return [
            'items' => FluentRule::array()->required()->list()->each([
                'a' => FluentRule::field()->required(),
            ]),
        ];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::fluentRules();
    }
}
