<?php declare(strict_types=1);

use SanderMuller\FluentValidation\FluentRule as Rule;

return [
    'age' => Rule::field()->min(5),
];
