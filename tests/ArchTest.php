<?php declare(strict_types=1);
use SanderMuller\FluentValidation\Rules\Concerns\SelfValidates;
use SanderMuller\FluentValidation\RuleSet;

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->not->toBeUsed()
    ->ignoring(RuleSet::class)
    ->ignoring(SelfValidates::class);
