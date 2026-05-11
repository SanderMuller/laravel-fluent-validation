<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests\Fixtures;

use SanderMuller\FluentValidation\FluentFormRequest;
use SanderMuller\FluentValidation\FluentRule;

/**
 * Mirrors collectiq's reported shape: outer array with bail+required+max,
 * inner each() carrying a batched `exists` rule. Drives the parent-max
 * short-circuit through BatchLimitRemap so the FluentRulesTester surface
 * can assert that Validator::failed() carries the Max key end-to-end.
 *
 * Depends on the `testing.widgets` table created by setupGuardsDatabase().
 *
 * @internal
 */
final class BailMaxExistsFluentFormRequest extends FluentFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'actions' => FluentRule::array()->bail()->required()->max(5)->each([
                'id' => FluentRule::integer()->bail()->required()->exists('testing.widgets', 'id'),
            ]),
        ];
    }
}
