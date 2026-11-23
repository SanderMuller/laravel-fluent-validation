<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests\Fixtures;

use SanderMuller\FluentValidation\FluentFormRequest;
use SanderMuller\FluentValidation\FluentRule;

/**
 * An array of items with a rule on each item and on its fields:
 * `each(FluentRule::array()->children([...]))`. Pins that the form request
 * and `RuleSet` leave the item keys no rule validates out of `validated()`.
 *
 * @internal
 */
final class EachChildrenFluentFormRequest extends FluentFormRequest
{
    /** @return array<string, mixed> */
    public static function fluentRules(): array
    {
        return [
            'title' => FluentRule::string()->nullable(),
            'items' => FluentRule::array()->required()->each(FluentRule::array()->min(1)->children([
                'sku' => FluentRule::string()->required()->max(20),
                'qty' => FluentRule::integer()->nullable()->min(1),
                'meta' => FluentRule::array()->nullable()->children([
                    'color' => FluentRule::string()->nullable(),
                ]),
            ])),
        ];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::fluentRules();
    }
}
