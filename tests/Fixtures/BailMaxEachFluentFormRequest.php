<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests\Fixtures;

use SanderMuller\FluentValidation\FluentFormRequest;
use SanderMuller\FluentValidation\FluentRule;

/**
 * Mirrors a real downstream shape: outer array with bail+required+max plus
 * keyed each() children. Used to pin the regression where Max on the parent
 * was absent from Validator::failed() under the FormRequest path.
 *
 * @internal
 */
final class BailMaxEachFluentFormRequest extends FluentFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'actions' => FluentRule::array()->bail()->required()->max(50)->each([
                'action' => FluentRule::string()->bail()->required()->in(['bookmark', 'favorite', 'rate']),
                'article_id' => FluentRule::integer()->bail()->required(),
            ]),
        ];
    }
}
