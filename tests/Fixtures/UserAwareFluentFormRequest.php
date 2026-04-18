<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests\Fixtures;

use SanderMuller\FluentValidation\FluentFormRequest;
use SanderMuller\FluentValidation\FluentRule;

/**
 * FormRequest fixture whose `authorize()` gates on the resolved user. Used to
 * exercise the tester's chained `actingAs()` helper against a real auth-aware
 * request.
 *
 * @internal
 */
final class UserAwareFluentFormRequest extends FluentFormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->getAuthIdentifier() === 1;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => FluentRule::string()->required(),
        ];
    }
}
