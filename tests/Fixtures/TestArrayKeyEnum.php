<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests\Fixtures;

enum TestArrayKeyEnum: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
