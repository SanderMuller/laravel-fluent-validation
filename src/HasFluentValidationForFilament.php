<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

/**
 * Use this trait on Livewire components that also use Filament's InteractsWithForms.
 * Unlike HasFluentValidation, this trait does not override validate(), validateOnly(),
 * getRules(), or getValidationAttributes(), so it composes cleanly with Filament.
 *
 * Instead, it hooks into the validation flow by compiling FluentRule objects
 * before they reach the validator, while preserving Filament's form-schema rules.
 *
 *     class EditUser extends Component implements HasForms
 *     {
 *         use HasFluentValidationForFilament, InteractsWithForms;
 *
 *         public function rules(): array
 *         {
 *             return [
 *                 'name'  => FluentRule::string('Name')->required()->max(255),
 *                 'items' => FluentRule::array()->required()->each([...]),
 *             ];
 *         }
 *     }
 */
trait HasFluentValidationForFilament
{
    /**
     * Compile FluentRule objects in rules() and validate with labels/messages extracted.
     * Call this instead of $this->validate() in your Filament component's submit handler.
     *
     * @param  array<string, mixed>|null  $rules  Override rules (null = use rules())
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     * @return array<string, mixed>
     */
    public function validateFluent(?array $rules = null, array $messages = [], array $attributes = []): array
    {
        $source = $rules ?? (method_exists($this, 'rules') ? $this->rules() : []); // @phpstan-ignore function.alreadyNarrowedType

        if ($source === []) {
            return $this->validate($rules, $messages, $attributes);
        }

        // Check if any rules are FluentRule objects
        $hasFluentRules = false;

        foreach ($source as $rule) {
            if (is_object($rule)) {
                $hasFluentRules = true;

                break;
            }
        }

        if (! $hasFluentRules) {
            return $this->validate($rules, $messages, $attributes);
        }

        [$compiled, $extractedMessages, $extractedAttributes] = RuleSet::compileWithMetadata($source);

        return $this->validate(
            $compiled,
            array_merge($extractedMessages, $messages),
            array_merge($extractedAttributes, $attributes),
        );
    }
}
