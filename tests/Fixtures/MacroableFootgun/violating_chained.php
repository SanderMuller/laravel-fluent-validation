<?php declare(strict_types=1);

use SanderMuller\FluentValidation\FluentRule;

return [
    'size' => FluentRule::field()->required()->nullable()->between(1, 10),
];
