<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Validation\Rules\AnyOf;
use RuntimeException;
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

/**
 * Instance-based mirror of the {@see FluentRule} static factory.
 *
 * Receive one builder and chain field starters off it, instead of repeating
 * the `FluentRule::` prefix on every line — the same shape Laravel's AI SDK
 * uses for `schema(JsonSchema $schema)`:
 *
 *     RuleSet::define(fn (FluentSchema $rules) => [
 *         'name'  => $rules->string('Full Name')->required()->max(255),
 *         'items' => $rules->array()->required()->each([
 *             'name' => $rules->string()->required(),
 *         ]),
 *     ])->validate($data);
 *
 * Every method delegates to {@see FluentRule}, so the two surfaces return
 * identical rule objects and stay in lockstep (a reflection parity test
 * guards against drift). Macros registered on {@see FluentRule} are reachable
 * here too, forwarded through `__call`.
 */
final class FluentSchema
{
    public function string(?string $label = null, ?string $message = null): StringRule
    {
        return FluentRule::string($label, $message);
    }

    public function numeric(?string $label = null, ?string $message = null): NumericRule
    {
        return FluentRule::numeric($label, $message);
    }

    public function integer(?string $label = null, ?string $message = null, bool $strict = false): NumericRule
    {
        return FluentRule::integer($label, $message, $strict);
    }

    public function date(?string $label = null, ?string $message = null): DateRule
    {
        return FluentRule::date($label, $message);
    }

    public function dateTime(?string $label = null, ?string $message = null): DateRule
    {
        return FluentRule::dateTime($label, $message);
    }

    public function boolean(?string $label = null, ?string $message = null): BooleanRule
    {
        return FluentRule::boolean($label, $message);
    }

    public function accepted(?string $label = null, ?string $message = null): AcceptedRule
    {
        return FluentRule::accepted($label, $message);
    }

    public function declined(?string $label = null, ?string $message = null): DeclinedRule
    {
        return FluentRule::declined($label, $message);
    }

    /** @param Arrayable<array-key, string|BackedEnum>|list<string|BackedEnum>|null $keys */
    public function array(Arrayable|array|null $keys = null, ?string $label = null, ?string $message = null): ArrayRule
    {
        return FluentRule::array($keys, $label, $message);
    }

    public function file(?string $label = null, ?string $message = null): FileRule
    {
        return FluentRule::file($label, $message);
    }

    public function email(?string $label = null, bool $defaults = true, ?string $message = null): EmailRule
    {
        return FluentRule::email($label, $defaults, $message);
    }

    public function image(?string $label = null, ?string $message = null): ImageRule
    {
        return FluentRule::image($label, $message);
    }

    public function password(?int $min = null, ?string $label = null, bool $defaults = true): PasswordRule
    {
        return FluentRule::password($min, $label, $defaults);
    }

    public function url(?string $label = null, ?string $message = null): StringRule
    {
        return FluentRule::url($label, $message);
    }

    public function uuid(?string $label = null, ?string $message = null): StringRule
    {
        return FluentRule::uuid($label, $message);
    }

    public function ulid(?string $label = null, ?string $message = null): StringRule
    {
        return FluentRule::ulid($label, $message);
    }

    public function ip(?string $label = null, ?string $message = null): StringRule
    {
        return FluentRule::ip($label, $message);
    }

    public function ipv4(?string $label = null, ?string $message = null): StringRule
    {
        return FluentRule::ipv4($label, $message);
    }

    public function ipv6(?string $label = null, ?string $message = null): StringRule
    {
        return FluentRule::ipv6($label, $message);
    }

    public function macAddress(?string $label = null, ?string $message = null): StringRule
    {
        return FluentRule::macAddress($label, $message);
    }

    public function json(?string $label = null, ?string $message = null): StringRule
    {
        return FluentRule::json($label, $message);
    }

    public function timezone(?string $label = null, ?string $message = null): StringRule
    {
        return FluentRule::timezone($label, $message);
    }

    public function hexColor(?string $label = null, ?string $message = null): StringRule
    {
        return FluentRule::hexColor($label, $message);
    }

    public function activeUrl(?string $label = null, ?string $message = null): StringRule
    {
        return FluentRule::activeUrl($label, $message);
    }

    public function regex(string $pattern, ?string $label = null, ?string $message = null): StringRule
    {
        return FluentRule::regex($pattern, $label, $message);
    }

    public function list(?string $label = null, ?string $message = null): ArrayRule
    {
        return FluentRule::list($label, $message);
    }

    /** @param  class-string  $type */
    public function enum(string $type, ?Closure $callback = null, ?string $label = null, ?string $message = null): FieldRule
    {
        return FluentRule::enum($type, $callback, $label, $message);
    }

    public function field(?string $label = null): FieldRule
    {
        return FluentRule::field($label);
    }

    /**
     * @param  array<int, mixed>  $rules
     *
     * @throws RuntimeException If AnyOf is not available (requires Laravel 13+)
     */
    public function anyOf(array $rules): AnyOf
    {
        return FluentRule::anyOf($rules);
    }

    /**
     * Forward anything not declared above to {@see FluentRule}, so macros
     * registered on the static factory are also reachable on the instance.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        // Only reached for names not declared above — i.e. macros, which
        // Macroable resolves through FluentRule's __callStatic.
        return FluentRule::__callStatic($name, $arguments);
    }
}
