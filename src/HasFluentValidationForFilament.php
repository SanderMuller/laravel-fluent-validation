<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Illuminate\Validation\ValidationException;

/**
 * Use this trait on Livewire components that also use Filament's InteractsWithForms/InteractsWithSchemas.
 * Provides the same FluentRule support as HasFluentValidation but wraps validation errors
 * with Filament's error event dispatch (form-validation-error).
 *
 * Requires insteadof disambiguation since both traits define validate/validateOnly/getRules/getValidationAttributes:
 *
 *     class EditUser extends Component implements HasForms
 *     {
 *         use HasFluentValidationForFilament, InteractsWithForms {
 *             HasFluentValidationForFilament::validate insteadof InteractsWithForms;
 *             HasFluentValidationForFilament::validateOnly insteadof InteractsWithForms;
 *             HasFluentValidationForFilament::getRules insteadof InteractsWithForms;
 *             HasFluentValidationForFilament::getValidationAttributes insteadof InteractsWithForms;
 *         }
 *     }
 *
 * Standard validate()/validateOnly() work as expected — FluentRule compilation,
 * label extraction, each()/children() expansion, and Filament error dispatching
 * are all handled automatically.
 */
trait HasFluentValidationForFilament
{
    use HasFluentValidation {
        HasFluentValidation::validate as private fluentValidate;
        HasFluentValidation::validateOnly as private fluentValidateOnly;
    }

    /**
     * Validate with FluentRule compilation + Filament's error event dispatch.
     */
    public function validate(mixed $rules = null, mixed $messages = [], mixed $attributes = []): mixed
    {
        try {
            return $this->fluentValidate($rules, $messages, $attributes);
        } catch (ValidationException $validationException) {
            $this->dispatchFilamentValidationError($validationException);

            throw $validationException;
        }
    }

    /**
     * Validate a single field with FluentRule compilation + Filament's error event dispatch.
     */
    public function validateOnly(mixed $field, mixed $rules = null, mixed $messages = [], mixed $attributes = [], mixed $dataOverrides = []): mixed
    {
        try {
            return $this->fluentValidateOnly($field, $rules, $messages, $attributes, $dataOverrides);
        } catch (ValidationException $validationException) {
            $this->dispatchFilamentValidationError($validationException);

            throw $validationException;
        }
    }

    /**
     * Dispatch Filament's form-validation-error event, matching InteractsWithForms behavior.
     */
    private function dispatchFilamentValidationError(ValidationException $exception): void
    {
        if (method_exists($this, 'onValidationError')) { // @phpstan-ignore function.alreadyNarrowedType
            $this->onValidationError($exception);
        }

        if (method_exists($this, 'dispatch')) { // @phpstan-ignore function.alreadyNarrowedType
            $this->dispatch('form-validation-error', livewireId: $this->getId());
        }
    }
}
