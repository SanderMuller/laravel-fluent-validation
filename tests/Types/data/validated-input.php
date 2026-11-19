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

function nullableInteger(ValidatedInput $safe, int $fallback, ?int $maybe): void
{
    assertType('int|null', $safe->nullableInteger('age'));
    assertType('int|null', $safe->nullableInteger('age', $maybe));
    assertType('int', $safe->nullableInteger('age', 0));
    assertType('int', $safe->nullableInteger('age', $fallback));
}
