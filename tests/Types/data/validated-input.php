<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests\Types\Data;

use SanderMuller\FluentValidation\ValidatedInput;

use function PHPStan\Testing\assertType;

function nullableString(ValidatedInput $safe, string $fallback, ?string $maybe): void
{
    assertType('string|null', $safe->nullableString('name'));
    assertType('string|null', $safe->nullableString('name', $maybe));
    assertType('string', $safe->nullableString('name', 'guest'));
    assertType('string', $safe->nullableString('name', $fallback));
}
