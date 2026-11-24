<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests\Fixtures;

/**
 * A fixture form request whose rules a test also reads without a request.
 */
interface DeclaresFluentRules
{
    /** @return array<string, mixed> */
    public static function fluentRules(): array;
}
