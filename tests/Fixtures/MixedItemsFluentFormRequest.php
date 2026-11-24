<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests\Fixtures;

use SanderMuller\FluentValidation\FluentFormRequest;
use SanderMuller\FluentValidation\FluentRule;

/**
 * A rule on each item, for items that mix arrays and scalars. Laravel
 * orders the errors as `Arr::dot()` orders the keys: a non-empty array
 * after the scalars. The package reports them in item order.
 *
 * @internal
 */
final class MixedItemsFluentFormRequest extends FluentFormRequest implements DeclaresFluentRules
{
    /** @return array<string, mixed> */
    public static function fluentRules(): array
    {
        return [
            'items' => FluentRule::array()->required()->each(FluentRule::integer()),
        ];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::fluentRules();
    }
}
