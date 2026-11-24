<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests\Fixtures;

use Illuminate\Validation\Rule;
use SanderMuller\FluentValidation\FluentFormRequest;
use SanderMuller\FluentValidation\FluentRule;

/**
 * Fields that `requiredIf()` and `excludeUnless()` tie to one field of the
 * item, with Laravel's `in` rule on that field. Pins that the rules reduced
 * for one request do not leak into the next request with the same rules.
 *
 * @internal
 */
final class DispatchedFieldsFluentFormRequest extends FluentFormRequest implements DeclaresFluentRules
{
    /** @return array<string, mixed> */
    public static function fluentRules(): array
    {
        $type = 'entries.*.type';

        return [
            'entries' => FluentRule::array()->required()->each([
                'type' => FluentRule::string()->required()->rule(Rule::in(['note', 'link', 'scroll'])),
                'text' => FluentRule::string()->nullable()->requiredIf($type, 'note')->excludeUnless($type, 'note'),
                'url' => FluentRule::string()->nullable()->requiredIf($type, 'link')->excludeUnless($type, 'link')->max(20),
                'from' => FluentRule::integer()->nullable()->requiredIf($type, 'scroll')->excludeUnless($type, 'scroll'),
                'at' => FluentRule::integer()->nullable(),
            ]),
        ];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::fluentRules();
    }
}
