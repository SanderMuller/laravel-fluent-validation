<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Traits\Macroable;
use Illuminate\Validation\Rules\AnyOf;
use SanderMuller\FluentValidation\Rules\AcceptedRule;
use SanderMuller\FluentValidation\Rules\ArrayRule;
use SanderMuller\FluentValidation\Rules\BooleanRule;
use SanderMuller\FluentValidation\Rules\DateRule;
use SanderMuller\FluentValidation\Rules\DeclinedRule;
use SanderMuller\FluentValidation\Rules\EmailRule;
use SanderMuller\FluentValidation\Rules\FieldRule;
use SanderMuller\FluentValidation\Rules\FileRule;
use SanderMuller\FluentValidation\Rules\ImageRule;
use SanderMuller\FluentValidation\Rules\NumericRule;
use SanderMuller\FluentValidation\Rules\PasswordRule;
use SanderMuller\FluentValidation\Rules\StringRule;

class FluentRule
{
    use Macroable;

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

    public static function accepted(?string $label = null): AcceptedRule
    {
        $acceptedRule = new AcceptedRule();

        return $label !== null ? $acceptedRule->label($label) : $acceptedRule;
    }

    public static function declined(?string $label = null): DeclinedRule
    {
        $declinedRule = new DeclinedRule();

        return $label !== null ? $declinedRule->label($label) : $declinedRule;
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

    public static function url(?string $label = null): StringRule
    {
        return self::string($label)->url();
    }

    public static function uuid(?string $label = null): StringRule
    {
        return self::string($label)->uuid();
    }

    public static function ulid(?string $label = null): StringRule
    {
        return self::string($label)->ulid();
    }

    public static function ip(?string $label = null): StringRule
    {
        return self::string($label)->ip();
    }

    public static function ipv4(?string $label = null): StringRule
    {
        return self::string($label)->ipv4();
    }

    public static function ipv6(?string $label = null): StringRule
    {
        return self::string($label)->ipv6();
    }

    public static function macAddress(?string $label = null): StringRule
    {
        return self::string($label)->macAddress();
    }

    public static function json(?string $label = null): StringRule
    {
        return self::string($label)->json();
    }

    public static function timezone(?string $label = null): StringRule
    {
        return self::string($label)->timezone();
    }

    public static function hexColor(?string $label = null): StringRule
    {
        return self::string($label)->hexColor();
    }

    public static function activeUrl(?string $label = null): StringRule
    {
        return self::string($label)->activeUrl();
    }

    public static function regex(string $pattern, ?string $label = null): StringRule
    {
        return self::string($label)->regex($pattern);
    }

    public static function list(?string $label = null): ArrayRule
    {
        return self::array(label: $label)->list();
    }

    /** @param  class-string  $type */
    public static function enum(string $type, ?\Closure $callback = null, ?string $label = null): FieldRule
    {
        return self::field($label)->enum($type, $callback);
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
