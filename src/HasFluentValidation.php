<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

/**
 * Add this trait to Livewire components to enable FluentRule support.
 * Compiles FluentRule objects to native Laravel format, extracts
 * labels and messages before Livewire's validator sees them.
 *
 *     class EditUser extends Component
 *     {
 *         use HasFluentValidation;
 *
 *         public function rules(): array
 *         {
 *             return [
 *                 'name'  => FluentRule::string('Name')->required()->max(255),
 *                 'email' => FluentRule::email('Email')->required(),
 *             ];
 *         }
 *     }
 *
 * Note: Livewire reads wildcard keys from rules() before compilation.
 * Use flat wildcard keys instead of each() for array fields:
 *
 *     'items'   => FluentRule::array()->required(),
 *     'items.*' => FluentRule::string()->max(255),
 *
 * Not: FluentRule::array()->each(FluentRule::string()->max(255))
 */
trait HasFluentValidation
{
    public function validate($rules = null, $messages = [], $attributes = [])
    {
        [$rules, $messages, $attributes] = $this->compileFluentRules($rules, $messages, $attributes);

        return parent::validate($rules, $messages, $attributes);
    }

    public function validateOnly($field, $rules = null, $messages = [], $attributes = [], $dataOverrides = [])
    {
        [$rules, $messages, $attributes] = $this->compileFluentRules($rules, $messages, $attributes);

        return parent::validateOnly($field, $rules, $messages, $attributes, $dataOverrides);
    }

    /**
     * Compile FluentRule objects to native format, expand wildcards,
     * and extract labels/messages.
     *
     * @param  array<string, mixed>|null  $rules
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     * @return array{0: array<string, mixed>|null, 1: array<string, string>, 2: array<string, string>}
     */
    private function compileFluentRules(?array $rules, array $messages, array $attributes): array
    {
        // If no rules passed, resolve from rules() method (same as Livewire does)
        $resolvedRules = $rules ?? (method_exists($this, 'rules') ? $this->rules() : null);

        if ($resolvedRules === null || $resolvedRules === []) {
            return [$rules, $messages, $attributes];
        }

        // Check if any rules are FluentRule objects (skip compilation for plain string rules)
        $hasFluentRules = false;
        foreach ($resolvedRules as $rule) {
            if (is_object($rule)) {
                $hasFluentRules = true;
                break;
            }
        }

        if (! $hasFluentRules) {
            return [$rules, $messages, $attributes];
        }

        // Use Livewire's data resolution when available — it correctly
        // handles model-bound properties and nested data for wildcard expansion.
        $data = method_exists($this, 'getDataForValidation')
            ? $this->getDataForValidation($resolvedRules)
            : (method_exists($this, 'all') ? $this->all() : []);

        // Unwrap Eloquent models to arrays so WildcardExpander can traverse them.
        if (method_exists($this, 'unwrapDataForValidation')) {
            $data = $this->unwrapDataForValidation($data);
        }

        $prepared = RuleSet::from($resolvedRules)->prepare($data);

        return [
            $prepared->rules,
            array_merge($prepared->messages, $messages),
            array_merge($prepared->attributes, $attributes),
        ];
    }
}
