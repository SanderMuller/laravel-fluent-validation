<?php

declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Illuminate\Contracts\Support\Arrayable;
use SanderMuller\FluentValidation\Rules\ArrayRule;
use SanderMuller\FluentValidation\Rules\BooleanRule;
use SanderMuller\FluentValidation\Rules\DateRule;
use SanderMuller\FluentValidation\Rules\NumericRule;
use SanderMuller\FluentValidation\Rules\StringRule;

class Rule
{
    public static function string(): StringRule
    {
        return new StringRule();
    }

    public static function numeric(): NumericRule
    {
        return new NumericRule();
    }

    public static function date(): DateRule
    {
        return new DateRule();
    }

    public static function dateTime(): DateRule
    {
        return (new DateRule())->format('Y-m-d H:i:s');
    }

    public static function boolean(): BooleanRule
    {
        return new BooleanRule();
    }

    /** @param  Arrayable<array-key, string|\BackedEnum>|list<string|\BackedEnum>|null  $keys */
    public static function array(Arrayable|array|null $keys = null): ArrayRule
    {
        return new ArrayRule($keys);
    }
}
