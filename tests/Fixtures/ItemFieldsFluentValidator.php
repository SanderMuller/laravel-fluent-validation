<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests\Fixtures;

use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\FluentValidator;

/**
 * A FluentValidator with one validated field per item. It is not made by
 * the validation factory, so it does not get the factory's default that
 * leaves unvalidated array keys out of `validated()`.
 *
 * @internal
 */
final class ItemFieldsFluentValidator extends FluentValidator
{
    /** @param  array<string, mixed>  $data */
    public function __construct(array $data)
    {
        parent::__construct($data, [
            'items' => FluentRule::array()->required()->each([
                'a' => FluentRule::integer()->required(),
            ]),
        ]);
    }
}
