<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests\Fixtures;

use SanderMuller\FluentValidation\FluentSchema;

/**
 * Supplies schema() so a consuming request can define rules() in its own body.
 * The body definition is more specific than a trait import, so it must win the
 * shared key. Kept in its own file so the trait-origin file heuristic in
 * HasFluentRules::methodImportedFromTrait() can tell it apart from a body
 * definition.
 */
trait ProvidesMergeSchemaViaTrait
{
    /** @return array<string, mixed> */
    public function schema(FluentSchema $rules): array
    {
        return [
            'shared' => $rules->string()->required()->in(['trait']),
            'trait_only' => $rules->string()->required(),
        ];
    }
}
