<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Closure;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\ValidationException;

/**
 * Result of RuleSet::check(). Immutable view over validation outcome.
 * Use for errors-as-data flows (import rows, batch jobs) where throwing
 * on failure is unwanted.
 *
 *     $result = RuleSet::from($rules)->check($data);
 *     if ($result->fails()) {
 *         Log::warning('...', $result->errors()->all());
 *         return null;
 *     }
 *     $validated = $result->validated();
 *
 * @template-covariant TValidated of array<string, mixed> = array<string, mixed>
 */
final class Validated
{
    /**
     * @param  TValidated  $validated
     * @param  ValidatorContract|(Closure(): ValidatorContract)  $validator  a closure builds the validator on first use
     */
    public function __construct(
        private readonly bool $passes,
        private readonly array $validated,
        private readonly MessageBag $errors,
        private ValidatorContract|Closure $validator,
    ) {}

    public function passes(): bool
    {
        return $this->passes;
    }

    public function fails(): bool
    {
        return ! $this->passes;
    }

    public function errors(): MessageBag
    {
        return $this->errors;
    }

    public function firstError(string $field): ?string
    {
        $message = $this->errors->first($field);

        return $message === '' ? null : $message;
    }

    /**
     * Return validated data. Throws ValidationException if validation failed.
     *
     * @return TValidated
     *
     * @throws ValidationException
     */
    public function validated(): array
    {
        if ($this->fails()) {
            throw new ValidationException($this->validator());
        }

        return $this->validated;
    }

    /**
     * Return validated data as a ValidatedInput instance for fluent access
     * (->only(), ->except(), ->collect(), ->file(), ->files(), etc.).
     *
     * @throws ValidationException if validation failed
     */
    public function safe(): ValidatedInput
    {
        if ($this->fails()) {
            throw new ValidationException($this->validator());
        }

        return new ValidatedInput($this->validated);
    }

    /**
     * Escape hatch: get the underlying Laravel Validator for deep integration
     * (->after(), ->sometimes(), extensions). Prefer the wrapper methods above.
     */
    public function validator(): ValidatorContract
    {
        if ($this->validator instanceof Closure) {
            $this->validator = ($this->validator)();
        }

        return $this->validator;
    }
}
