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

    public static function string(?string $label = null, ?string $message = null): StringRule
    {
        $stringRule = new StringRule();
        if ($label !== null) {
            $stringRule->label($label);
        }

        if ($message !== null) {
            $stringRule->message($message);
        }

        return $stringRule;
    }

    public static function numeric(?string $label = null, ?string $message = null): NumericRule
    {
        $numericRule = new NumericRule();
        if ($label !== null) {
            $numericRule->label($label);
        }

        if ($message !== null) {
            $numericRule->message($message);
        }

        return $numericRule;
    }

    public static function integer(?string $label = null, ?string $message = null): NumericRule
    {
        return self::numeric($label)->integer(message: $message);
    }

    /**
     * `message:` is deferred — DateRule's error-lookup key varies at
     * build time (`'date'` vs `'date_format:...'`). Use `->messageFor()`
     * or `->message()` after calling a specific method like `before()`.
     */
    public static function date(?string $label = null): DateRule
    {
        $dateRule = new DateRule();

        return $label !== null ? $dateRule->label($label) : $dateRule;
    }

    /** Deferred for the same reason as `::date()`. */
    public static function dateTime(?string $label = null): DateRule
    {
        return self::date($label)->format('Y-m-d H:i:s');
    }

    public static function boolean(?string $label = null, ?string $message = null): BooleanRule
    {
        $booleanRule = new BooleanRule();
        if ($label !== null) {
            $booleanRule->label($label);
        }

        if ($message !== null) {
            $booleanRule->message($message);
        }

        return $booleanRule;
    }

    public static function accepted(?string $label = null, ?string $message = null): AcceptedRule
    {
        $acceptedRule = new AcceptedRule();
        if ($label !== null) {
            $acceptedRule->label($label);
        }

        if ($message !== null) {
            $acceptedRule->message($message);
        }

        return $acceptedRule;
    }

    public static function declined(?string $label = null, ?string $message = null): DeclinedRule
    {
        $declinedRule = new DeclinedRule();
        if ($label !== null) {
            $declinedRule->label($label);
        }

        if ($message !== null) {
            $declinedRule->message($message);
        }

        return $declinedRule;
    }

    /** @param  Arrayable<array-key, string|\BackedEnum>|list<string|\BackedEnum>|null  $keys */
    public static function array(Arrayable|array|null $keys = null, ?string $label = null, ?string $message = null): ArrayRule
    {
        $arrayRule = new ArrayRule($keys);
        if ($label !== null) {
            $arrayRule->label($label);
        }

        if ($message !== null) {
            $arrayRule->message($message);
        }

        return $arrayRule;
    }

    public static function file(?string $label = null, ?string $message = null): FileRule
    {
        $fileRule = new FileRule();
        if ($label !== null) {
            $fileRule->label($label);
        }

        if ($message !== null) {
            $fileRule->message($message);
        }

        return $fileRule;
    }

    public static function email(?string $label = null, bool $defaults = true, ?string $message = null): EmailRule
    {
        $emailRule = new EmailRule($defaults);
        if ($label !== null) {
            $emailRule->label($label);
        }

        if ($message !== null) {
            $emailRule->message($message);
        }

        return $emailRule;
    }

    public static function image(?string $label = null, ?string $message = null): ImageRule
    {
        $imageRule = new ImageRule();
        if ($label !== null) {
            $imageRule->label($label);
        }

        if ($message !== null) {
            $imageRule->message($message);
        }

        return $imageRule;
    }

    /**
     * `message:` is not accepted — PasswordRule emits failures under sub-keys
     * (`password.mixed`, `password.letters`, `password.numbers`, etc.) rather
     * than a bare `password` key. Target a specific Password strength rule
     * via `->messageFor('password.letters', '...')`, or use a separate
     * Laravel `messages(): array` entry.
     */
    public static function password(?int $min = null, ?string $label = null, bool $defaults = true): PasswordRule
    {
        $passwordRule = new PasswordRule($min, $defaults);

        return $label !== null ? $passwordRule->label($label) : $passwordRule;
    }

    public static function url(?string $label = null, ?string $message = null): StringRule
    {
        return self::string($label)->url($message);
    }

    public static function uuid(?string $label = null, ?string $message = null): StringRule
    {
        return self::string($label)->uuid($message);
    }

    public static function ulid(?string $label = null, ?string $message = null): StringRule
    {
        return self::string($label)->ulid($message);
    }

    public static function ip(?string $label = null, ?string $message = null): StringRule
    {
        return self::string($label)->ip($message);
    }

    public static function ipv4(?string $label = null, ?string $message = null): StringRule
    {
        return self::string($label)->ipv4($message);
    }

    public static function ipv6(?string $label = null, ?string $message = null): StringRule
    {
        return self::string($label)->ipv6($message);
    }

    public static function macAddress(?string $label = null, ?string $message = null): StringRule
    {
        return self::string($label)->macAddress($message);
    }

    public static function json(?string $label = null, ?string $message = null): StringRule
    {
        return self::string($label)->json($message);
    }

    public static function timezone(?string $label = null, ?string $message = null): StringRule
    {
        return self::string($label)->timezone($message);
    }

    public static function hexColor(?string $label = null, ?string $message = null): StringRule
    {
        return self::string($label)->hexColor($message);
    }

    public static function activeUrl(?string $label = null, ?string $message = null): StringRule
    {
        return self::string($label)->activeUrl($message);
    }

    public static function regex(string $pattern, ?string $label = null, ?string $message = null): StringRule
    {
        return self::string($label)->regex($pattern, $message);
    }

    public static function list(?string $label = null, ?string $message = null): ArrayRule
    {
        return self::array(label: $label)->list($message);
    }

    /** @param  class-string  $type */
    public static function enum(string $type, ?\Closure $callback = null, ?string $label = null, ?string $message = null): FieldRule
    {
        return self::field($label)->enum($type, $callback, $message);
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
