<?php declare(strict_types=1);

use SanderMuller\FluentValidation\FluentRule;

return [
    'age' => FluentRule::field()->min(5),
];
