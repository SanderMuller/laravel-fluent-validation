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
    public function validate(mixed $rules = null, mixed $messages = [], mixed $attributes = []): mixed
    {
        [$compiledRules, $compiledMessages, $compiledAttributes] = $this->compileFluentRules(
            $this->toNullableArray($rules),
            $this->toStringMap($messages),
            $this->toStringMap($attributes),
        );

        return parent::validate($compiledRules, $compiledMessages, $compiledAttributes);
    }

    public function validateOnly(mixed $field, mixed $rules = null, mixed $messages = [], mixed $attributes = [], mixed $dataOverrides = []): mixed
    {
        [$compiledRules, $compiledMessages, $compiledAttributes] = $this->compileFluentRules(
            $this->toNullableArray($rules),
            $this->toStringMap($messages),
            $this->toStringMap($attributes),
        );

        return parent::validateOnly($field, $compiledRules, $compiledMessages, $compiledAttributes, $dataOverrides);
    }

    /** @return array<string, mixed>|null */
    private function toNullableArray(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $result = [];

        foreach ($value as $k => $v) {
            if (is_string($k)) {
                $result[$k] = $v;
            }
        }

        return $result;
    }

    /** @return array<string, string> */
    private function toStringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $result[$k] = $v;
            }
        }

        return $result;
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
    protected function compileFluentRules(?array $rules, array $messages, array $attributes): array
    {
        // If no rules passed, resolve from rules() method (same as Livewire does)
        $resolvedRules = $rules ?? (method_exists($this, 'rules') ? $this->rules() : null); // @phpstan-ignore function.alreadyNarrowedType

        $typedRules = $this->toNullableArray($resolvedRules);

        if ($typedRules === null || $typedRules === []) {
            return [$rules, $messages, $attributes];
        }

        // Check if any rules are FluentRule objects (skip compilation for plain string rules)
        $hasFluentRules = false;
        foreach ($typedRules as $rule) {
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
        $rawData = method_exists($this, 'getDataForValidation') // @phpstan-ignore function.alreadyNarrowedType
            ? $this->getDataForValidation($typedRules)
            : (method_exists($this, 'all') ? $this->all() : []); // @phpstan-ignore function.alreadyNarrowedType

        // Unwrap Eloquent models to arrays so WildcardExpander can traverse them.
        if (method_exists($this, 'unwrapDataForValidation')) { // @phpstan-ignore function.alreadyNarrowedType
            $rawData = $this->unwrapDataForValidation($rawData);
        }

        $data = $this->toNullableArray($rawData) ?? [];

        $prepared = RuleSet::from($typedRules)->prepare($data);

        return [
            $prepared->rules,
            array_merge($prepared->messages, $messages),
            array_merge($prepared->attributes, $attributes),
        ];
    }
}
