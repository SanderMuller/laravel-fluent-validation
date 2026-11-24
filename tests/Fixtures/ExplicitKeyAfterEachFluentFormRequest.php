<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests\Fixtures;

use SanderMuller\FluentValidation\FluentFormRequest;
use SanderMuller\FluentValidation\FluentRule;

/**
 * An explicit key written after the `each()` rule that also matches it.
 * Laravel then applies only the explicit rule to that element.
 *
 * @internal
 */
final class ExplicitKeyAfterEachFluentFormRequest extends FluentFormRequest implements DeclaresFluentRules
{
    /** @return array<string, mixed> */
    public static function fluentRules(): array
    {
        return [
            'items' => FluentRule::array()->required()->each(FluentRule::integer()),
            'items.1' => FluentRule::string()->nullable(),
        ];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::fluentRules();
    }
}
