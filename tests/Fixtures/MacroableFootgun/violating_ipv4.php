<?php declare(strict_types=1);

use SanderMuller\FluentValidation\FluentRule;

return [
    'ip' => FluentRule::field()->ipv4(),
];
