<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests\Fixtures;

use SanderMuller\FluentValidation\FluentFormRequest;

/**
 * A rule on `items.*` alone with an exclude rule after a rule that can fail.
 * Laravel runs the rules in order, so the first rule can fail before the
 * exclude rule removes the item.
 *
 * @internal
 */
final class ItemExcludeAfterRuleFluentFormRequest extends FluentFormRequest implements DeclaresFluentRules
{
    /** @return array<string, mixed> */
    public static function fluentRules(): array
    {
        return [
            'items' => 'required|array',
            'items.*' => 'array|exclude_unless:items.*.status,keep',
        ];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::fluentRules();
    }
}
