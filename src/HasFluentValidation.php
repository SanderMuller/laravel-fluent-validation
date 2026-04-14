<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

/**
 * Add this trait to Livewire components to enable FluentRule support.
 * Compiles FluentRule objects to native Laravel format, extracts
 * labels and messages before Livewire's validator sees them.
 *
 * Supports both flat wildcard keys and each()/children():
 *
 *     class EditUser extends Component
 *     {
 *         use HasFluentValidation;
 *
 *         public function rules(): array
 *         {
 *             return [
 *                 'name'  => FluentRule::string('Name')->required()->max(255),
 *                 'items' => FluentRule::array()->required()->each([
 *                     'name' => FluentRule::string()->required(),
 *                 ]),
 *             ];
 *         }
 *     }
 */
trait HasFluentValidation
{
    /**
     * Override Livewire's getRules() to return compiled rules with
     * wildcard keys preserved (e.g. items.*.name). This ensures
     * hasRuleFor(), validateOnly(), and rulesForModel() work correctly.
     *
     * @return array<string, mixed>
     */
    public function getRules(): array
    {
        $rules = $this->resolveFluentRuleSource();

        if ($rules === []) {
            return $this->mergeRulesFromOutside([]);
        }

        // Check if any rules are FluentRule objects
        $hasFluentRules = false;

        foreach ($rules as $rule) {
            if (is_object($rule)) {
                $hasFluentRules = true;

                break;
            }
        }

        if (! $hasFluentRules) {
            return $this->mergeRulesFromOutside($rules);
        }

        // Flatten and compile: expands each()/children() into wildcard keys
        // but does NOT expand wildcards against data. This preserves patterns
        // like items.*.name that Livewire needs for hasRuleFor() matching.
        $ruleSet = RuleSet::from($rules);
        $flattened = RuleSet::compile($ruleSet->flattenRules());

        return $this->mergeRulesFromOutside($flattened);
    }

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

    /**
     * Merge with Livewire's rulesFromOutside (same as parent::getRules()).
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function mergeRulesFromOutside(array $rules): array
    {
        /** @var list<mixed> $outside */
        $outside = property_exists($this, 'rulesFromOutside') ? $this->rulesFromOutside : []; // @phpstan-ignore function.alreadyNarrowedType

        if ($outside === []) {
            return $rules;
        }

        /** @var array<string, mixed> $rulesFromOutside */
        $rulesFromOutside = array_merge_recursive(
            ...array_map(
                /** @return array<string, mixed> */
                fn ($i): array => (array) value($i),
                $outside,
            ),
        );

        return array_merge($rules, $rulesFromOutside);
    }

    /**
     * Resolve rules from the rules() method or $rules property,
     * matching Livewire's own fallback order.
     *
     * @return array<string, mixed>
     */
    private function resolveFluentRuleSource(): array
    {
        if (method_exists($this, 'rules')) { // @phpstan-ignore function.alreadyNarrowedType
            $rules = $this->rules();
        } elseif (property_exists($this, 'rules')) { // @phpstan-ignore function.alreadyNarrowedType
            $rules = $this->rules;
        } else {
            return [];
        }

        return $this->toNullableArray($rules) ?? [];
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
     * Compile FluentRule objects to native format, expand wildcards
     * against actual data, and extract labels/messages.
     *
     * @param  array<string, mixed>|null  $rules
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     * @return array{0: array<string, mixed>|null, 1: array<string, string>, 2: array<string, string>}
     */
    protected function compileFluentRules(?array $rules, array $messages, array $attributes): array
    {
        // If no rules passed, resolve from rules() method or $rules property
        $resolvedRules = $rules ?? $this->resolveFluentRuleSource();

        if ($resolvedRules === []) {
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
        $rawData = method_exists($this, 'getDataForValidation') // @phpstan-ignore function.alreadyNarrowedType
            ? $this->getDataForValidation($resolvedRules)
            : (method_exists($this, 'all') ? $this->all() : []); // @phpstan-ignore function.alreadyNarrowedType

        // Unwrap Eloquent models to arrays so WildcardExpander can traverse them.
        if (method_exists($this, 'unwrapDataForValidation')) { // @phpstan-ignore function.alreadyNarrowedType
            $rawData = $this->unwrapDataForValidation($rawData);
        }

        $data = $this->toNullableArray($rawData) ?? [];

        $prepared = RuleSet::from($resolvedRules)->prepare($data);

        return [
            $prepared->rules,
            array_merge($prepared->messages, $messages),
            array_merge($prepared->attributes, $attributes),
        ];
    }
}
