<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests\Fixtures;

use SanderMuller\FluentValidation\FluentFormRequest;
use SanderMuller\FluentValidation\FluentRule;

/**
 * Unauthorized FormRequest fixture — `authorize()` returns false, so
 * validateResolved() raises AuthorizationException. Exercises the
 * tester's recorded-exception path.
 *
 * @internal
 */
final class UnauthorizedFluentFormRequest extends FluentFormRequest
{
    public function authorize(): bool
    {
        return false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => FluentRule::string()->required(),
        ];
    }
}
