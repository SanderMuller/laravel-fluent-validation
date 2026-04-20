<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests\Fixtures;

enum TestIntEnum: int
{
    case Low = 1;
    case Medium = 2;
    case High = 3;
}
