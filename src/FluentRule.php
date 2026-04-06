<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Validation\Rules\AnyOf;
use SanderMuller\FluentValidation\Rules\ArrayRule;
use SanderMuller\FluentValidation\Rules\BooleanRule;
use SanderMuller\FluentValidation\Rules\DateRule;
use SanderMuller\FluentValidation\Rules\EmailRule;
use SanderMuller\FluentValidation\Rules\FieldRule;
use SanderMuller\FluentValidation\Rules\FileRule;
use SanderMuller\FluentValidation\Rules\ImageRule;
use SanderMuller\FluentValidation\Rules\NumericRule;
use SanderMuller\FluentValidation\Rules\PasswordRule;
use SanderMuller\FluentValidation\Rules\StringRule;

class FluentRule
{
    public static function string(?string $label = null): StringRule
    {
        $stringRule = new StringRule();

        return $label !== null ? $stringRule->label($label) : $stringRule;
    }

    public static function numeric(?string $label = null): NumericRule
    {
        $numericRule = new NumericRule();

        return $label !== null ? $numericRule->label($label) : $numericRule;
    }

    public static function integer(?string $label = null): NumericRule
    {
        return self::numeric($label)->integer();
    }

    public static function date(?string $label = null): DateRule
    {
        $dateRule = new DateRule();

        return $label !== null ? $dateRule->label($label) : $dateRule;
    }

    public static function dateTime(?string $label = null): DateRule
    {
        return self::date($label)->format('Y-m-d H:i:s');
    }

    public static function boolean(?string $label = null): BooleanRule
    {
        $booleanRule = new BooleanRule();

        return $label !== null ? $booleanRule->label($label) : $booleanRule;
    }

    /** @param  Arrayable<array-key, string|\BackedEnum>|list<string|\BackedEnum>|null  $keys */
    public static function array(Arrayable|array|null $keys = null, ?string $label = null): ArrayRule
    {
        $arrayRule = new ArrayRule($keys);

        return $label !== null ? $arrayRule->label($label) : $arrayRule;
    }

    public static function file(?string $label = null): FileRule
    {
        $fileRule = new FileRule();

        return $label !== null ? $fileRule->label($label) : $fileRule;
    }

    public static function email(?string $label = null, bool $defaults = true): EmailRule
    {
        $emailRule = new EmailRule($defaults);

        return $label !== null ? $emailRule->label($label) : $emailRule;
    }

    public static function image(?string $label = null): ImageRule
    {
        $imageRule = new ImageRule();

        return $label !== null ? $imageRule->label($label) : $imageRule;
    }

    public static function password(?int $min = null, ?string $label = null, bool $defaults = true): PasswordRule
    {
        $passwordRule = new PasswordRule($min, $defaults);

        return $label !== null ? $passwordRule->label($label) : $passwordRule;
    }

    public static function field(?string $label = null): FieldRule
    {
        $fieldRule = new FieldRule();

        return $label !== null ? $fieldRule->label($label) : $fieldRule;
    }

    /**
     * @param  array<int, mixed>  $rules
     *
     * @throws \RuntimeException If AnyOf is not available (requires Laravel 13+)
     */
    public static function anyOf(array $rules): AnyOf
    {
        if (! class_exists(AnyOf::class)) {
            throw new \RuntimeException('FluentRule::anyOf() requires Laravel 13+.');
        }

        return new AnyOf($rules);
    }
}
