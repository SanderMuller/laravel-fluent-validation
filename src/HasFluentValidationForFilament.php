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
        [$compiled, $mergedMessages, $mergedAttributes] = $this->compileFluentSource($rules, $messages, $attributes);

        return $this->validate($compiled, $mergedMessages, $mergedAttributes);
    }

    /**
     * Compile FluentRule objects and validate a single field.
     * Call this instead of $this->validateOnly() for real-time validation
     * with FluentRule label/message extraction in Filament components.
     *
     * @param  array<string, mixed>|null  $rules
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     * @param  array<string, mixed>  $dataOverrides
     * @return array<string, mixed>
     */
    public function validateOnlyFluent(string $field, ?array $rules = null, array $messages = [], array $attributes = [], array $dataOverrides = []): array
    {
        [$compiled, $mergedMessages, $mergedAttributes] = $this->compileFluentSource($rules, $messages, $attributes);

        return $this->validateOnly($field, $compiled, $mergedMessages, $mergedAttributes, $dataOverrides);
    }

    /**
     * Resolve and compile FluentRule source with metadata extraction.
     *
     * @param  array<string, mixed>|null  $rules
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     * @return array{0: array<string, mixed>|null, 1: array<string, string>, 2: array<string, string>}
     */
    private function compileFluentSource(?array $rules, array $messages, array $attributes): array
    {
        $source = $rules ?? (method_exists($this, 'rules') ? $this->rules() : []); // @phpstan-ignore function.alreadyNarrowedType

        if ($source === []) {
            return [$rules, $messages, $attributes];
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
            return [$rules, $messages, $attributes];
        }

        [$compiled, $extractedMessages, $extractedAttributes] = RuleSet::compileWithMetadata($source);

        return [
            $compiled,
            array_merge($extractedMessages, $messages),
            array_merge($extractedAttributes, $attributes),
        ];
    }
}
