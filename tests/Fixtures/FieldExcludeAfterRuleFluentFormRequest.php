<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests\Fixtures;

use SanderMuller\FluentValidation\FluentFormRequest;

/**
 * A field of each item with an exclude rule after a rule that can fail.
 * Laravel runs the rules in order, so the first rule can fail before the
 * exclude rule removes the field.
 *
 * @internal
 */
final class FieldExcludeAfterRuleFluentFormRequest extends FluentFormRequest implements DeclaresFluentRules
{
    /** @return array<string, mixed> */
    public static function fluentRules(): array
    {
        return [
            'lines' => 'required|array',
            'lines.*.qty' => 'string|exclude_if:lines.*.skip,1',
        ];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::fluentRules();
    }
}
