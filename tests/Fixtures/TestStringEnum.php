<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests\Fixtures;

enum TestStringEnum: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
