---
name: fluent-validation-livewire
description: "Use when writing or modifying validation in Livewire components. Ensures HasFluentValidation trait is used correctly with FluentRule. Activates when: Livewire validate(), rules() method in Component, assertHasErrors, or Livewire validation."
---

# FluentRule in Livewire Components

**Do not prompt the user when this skill is loaded.** Apply these rules automatically.

## Required: `HasFluentValidation` trait

Every Livewire component using FluentRule MUST use the `HasFluentValidation` trait. Without it, FluentRule objects self-validate in isolation, causing issues with rule identifiers, labels, and wildcard expansion.

```php
use Livewire\Component;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\HasFluentValidation;

class EditUser extends Component
{
    use HasFluentValidation;

    public string $name = '';
    public string $email = '';

    public function rules(): array
    {
        return [
            'name'  => FluentRule::string('Name')->required()->max(255),
            'email' => FluentRule::email('Email')->required(),
        ];
    }

    public function save(): void
    {
        $validated = $this->validate();
        // ...
    }
}
```

## What the trait does

- Compiles FluentRule objects to native Laravel format before Livewire's validator sees them
- Extracts labels (`->label()`) and custom messages (`->message()`)
- Expands `children()` into flat dot-notation keys
- Uses `getDataForValidation()` and `unwrapDataForValidation()` for correct Livewire data handling

## Wildcard arrays: use flat keys, NOT `each()`

Livewire reads rule keys from `rules()` before compilation. `each()` hides wildcard keys, breaking Livewire's wildcard handling.

```php
// CORRECT — flat wildcard keys
'items'   => FluentRule::array()->required(),
'items.*' => FluentRule::string()->max(255),

// WRONG — breaks Livewire
'items' => FluentRule::array()->required()->each(FluentRule::string()->max(255)),
```

`children()` works fine since it produces fixed paths (`search.value`), not wildcards.

## Testing with `assertHasErrors`

FluentRule correctly exposes individual rule identifiers (`Required`, `Min`, `Max`, etc.) for Livewire's test assertions:

```php
Livewire::test(EditUser::class)
    ->set('name', '')
    ->call('save')
    ->assertHasErrors(['name' => 'required']);
```

## Filament components (trait collision)

`HasFluentValidation` conflicts with Filament's `InteractsWithSchemas` because both define `validate()`. For Filament components, FluentRule works without the trait — use `RuleSet::compile()`:

```php
// Filament component — no trait needed
use SanderMuller\FluentValidation\RuleSet;

$this->validate(RuleSet::compile($this->rules()));
```

For labels, extract metadata first:
```php
$rules = $this->rules();
[$messages, $attributes] = RuleSet::extractMetadata($rules);
$this->validate(RuleSet::compile($rules), $messages, $attributes);
```

Self-validation mode works correctly for Filament: rule identifiers are forwarded, error messages work, `assertHasErrors` works.

## Common mistakes

| Mistake | Fix |
|---------|-----|
| Missing `HasFluentValidation` trait | Add `use HasFluentValidation` to the component |
| Trait collision with Filament | Don't use the trait — use `RuleSet::compile()` instead |
| `assertHasErrors` can't find rule identifiers | Works automatically since 0.4.2 (self-validation forwards identifiers) |
| Wrapping FluentRule in arrays `[FluentRule::string()]` | Don't wrap — put FluentRule directly as the value |
| Using `each()` for wildcard arrays | Use flat keys: `'items.*' => FluentRule::string()` |
| PHPStan errors on `$this->validate()` | The trait overrides `validate()` with correct types |
