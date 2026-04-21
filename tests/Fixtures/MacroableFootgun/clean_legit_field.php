<?php declare(strict_types=1);

use SanderMuller\FluentValidation\FluentRule;

return [
    'email' => FluentRule::field()->required()->nullable()->exists('users', 'email'),
    'tags' => FluentRule::field()->present()->children([]),
];
