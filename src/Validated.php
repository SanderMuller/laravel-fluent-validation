<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

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
 */
final readonly class Validated
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public function __construct(
        private bool $passes,
        private array $validated,
        private MessageBag $errors,
        private ValidatorContract $validator,
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
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validated(): array
    {
        if ($this->fails()) {
            throw new ValidationException($this->validator);
        }

        return $this->validated;
    }

    /**
     * Escape hatch: get the underlying Laravel Validator for deep integration
     * (->after(), ->sometimes(), extensions). Prefer the wrapper methods above.
     */
    public function validator(): ValidatorContract
    {
        return $this->validator;
    }
}
