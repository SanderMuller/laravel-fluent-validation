<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Illuminate\Validation\ValidationException;

/**
 * Use this trait on Livewire components that also use Filament's InteractsWithForms/InteractsWithSchemas.
 * Provides FluentRule compilation + Filament form-schema rule aggregation + error event dispatch.
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
 * Both rules() FluentRules and Filament form-schema rules are merged automatically.
 */
trait HasFluentValidationForFilament
{
    use HasFluentValidation {
        HasFluentValidation::validate as private fluentValidate;
        HasFluentValidation::validateOnly as private fluentValidateOnly;
        HasFluentValidation::getRules as private fluentGetRules;
        HasFluentValidation::getValidationAttributes as private fluentGetValidationAttributes;
    }

    /**
     * Return compiled FluentRules merged with Filament form-schema rules.
     *
     * @return array<string, mixed>
     */
    public function getRules(): array
    {
        $rules = $this->fluentGetRules();

        // Merge Filament form-schema rules (same as InteractsWithForms::getRules)
        if (method_exists($this, 'getCachedForms')) { // @phpstan-ignore function.alreadyNarrowedType
            foreach ($this->getCachedForms() as $form) {
                if (method_exists($form, 'getValidationRules')) {
                    $rules = [...$rules, ...$form->getValidationRules()];
                }
            }
        }

        return $rules;
    }

    /**
     * Return FluentRule labels merged with Filament form-schema attributes.
     *
     * @return array<string, string>
     */
    public function getValidationAttributes(): array
    {
        $attributes = $this->fluentGetValidationAttributes();

        // Merge Filament form-schema attributes (same as InteractsWithForms::getValidationAttributes)
        if (method_exists($this, 'getCachedForms')) { // @phpstan-ignore function.alreadyNarrowedType
            foreach ($this->getCachedForms() as $form) {
                if (method_exists($form, 'getValidationAttributes')) {
                    $attributes = [...$attributes, ...$form->getValidationAttributes()];
                }
            }
        }

        return $attributes;
    }

    /**
     * Validate with FluentRule compilation + form-schema rules + Filament error dispatch.
     *
     * When no inline rules are passed, delegates to Livewire's base validate()
     * which calls getRules() — this ensures both FluentRules and form-schema
     * rules are included. When inline rules are passed, compiles those directly.
     */
    public function validate(mixed $rules = null, mixed $messages = [], mixed $attributes = []): mixed
    {
        try {
            if ($rules !== null) {
                // Inline rules passed — compile FluentRules directly
                return $this->fluentValidate($rules, $messages, $attributes);
            }

            // Global rules — let Livewire call getRules() which merges
            // FluentRules + form-schema rules, then extract messages/attributes
            $compiledMessages = $this->getMessages();
            $compiledAttributes = $this->getValidationAttributes();

            return parent::validate(
                null,
                array_merge($compiledMessages, is_array($messages) ? $messages : []),
                array_merge($compiledAttributes, is_array($attributes) ? $attributes : []),
            );
        } catch (ValidationException $validationException) {
            $this->dispatchFilamentValidationError($validationException);

            throw $validationException;
        }
    }

    /**
     * Validate a single field with FluentRule compilation + form-schema rules + Filament error dispatch.
     */
    public function validateOnly(mixed $field, mixed $rules = null, mixed $messages = [], mixed $attributes = [], mixed $dataOverrides = []): mixed
    {
        try {
            if ($rules !== null) {
                return $this->fluentValidateOnly($field, $rules, $messages, $attributes, $dataOverrides);
            }

            $compiledMessages = $this->getMessages();
            $compiledAttributes = $this->getValidationAttributes();

            return parent::validateOnly(
                $field,
                null,
                array_merge($compiledMessages, is_array($messages) ? $messages : []),
                array_merge($compiledAttributes, is_array($attributes) ? $attributes : []),
                $dataOverrides,
            );
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

        if (method_exists($this, 'dispatch') && method_exists($this, 'getId')) { // @phpstan-ignore function.alreadyNarrowedType
            $this->dispatch('form-validation-error', livewireId: $this->getId());
        }
    }
}
