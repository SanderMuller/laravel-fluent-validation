<?php declare(strict_types=1);

use SanderMuller\FluentValidation\FluentRule;

return [
    'age' => FluentRule::numeric()->min(5),
    'name' => FluentRule::string()->between(1, 10),
];
