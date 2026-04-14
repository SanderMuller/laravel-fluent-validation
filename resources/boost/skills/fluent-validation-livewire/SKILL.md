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

The trait also works on Livewire Form objects:

```php
class UserForm extends Form
{
    use HasFluentValidation;

    public string $name = '';

    public function rules(): array
    {
        return ['name' => FluentRule::string('Name')->required()->max(255)];
    }
}
```

## What the trait does

- Overrides `validate()` AND `validateOnly()` (works with `wire:model.blur` real-time validation)
- Compiles FluentRule objects to native Laravel format before Livewire's validator sees them
- Extracts labels (`->label()`) and custom messages (`->message()`)
- Expands `children()` into flat dot-notation keys
- Uses `getDataForValidation()` and `unwrapDataForValidation()` for correct Livewire data handling

## Wildcard arrays: both `each()` and flat keys work

The trait overrides `getRules()` to flatten `each()` and `children()` into wildcard keys that Livewire can see. Both styles work:

```php
// flat wildcard keys
'items'        => FluentRule::array()->required(),
'items.*.name' => FluentRule::string()->required(),

// each() — the trait expands this for Livewire automatically
'items' => FluentRule::array()->required()->each([
    'name' => FluentRule::string()->required(),
]),

// children() — produces fixed paths (credentials.base_uri)
'credentials' => FluentRule::array()->children([
    'base_uri'  => FluentRule::string()->nullable()->url(),
    'client_id' => FluentRule::string()->required()->uuid(),
]),
```

## Testing with `assertHasErrors`

FluentRule correctly exposes individual rule identifiers (`Required`, `Min`, `Max`, etc.) for Livewire's test assertions:

```php
Livewire::test(EditUser::class)
    ->set('name', '')
    ->call('save')
    ->assertHasErrors(['name' => 'required']);
```

## Filament components (trait collision)

`HasFluentValidation` conflicts with Filament's `InteractsWithSchemas` because both define `validate()`. For Filament components, FluentRule works without the trait — use `RuleSet::compileToArrays()`:

```php
// Filament component — no trait needed
use SanderMuller\FluentValidation\RuleSet;

$this->validate(RuleSet::compileToArrays($this->rules()));
```

`compileToArrays()` guarantees `array<string, array<mixed>>` return type, matching Livewire's expected parameter type — no PHPStan baseline entries needed.

For labels, extract metadata first:
```php
$rules = $this->rules();
[$messages, $attributes] = RuleSet::extractMetadata($rules);
$this->validate(RuleSet::compileToArrays($rules), $messages, $attributes);
```

Self-validation mode works correctly for Filament: rule identifiers are forwarded, error messages work, `assertHasErrors` works.

Alternatively, if you need the full trait benefits, use PHP's `insteadof` resolution:

```php
use HasFluentValidation, InteractsWithForms {
    HasFluentValidation::validate insteadof InteractsWithForms;
    HasFluentValidation::validateOnly insteadof InteractsWithForms;
}
```

## Common mistakes

| Mistake | Fix |
|---------|-----|
| Missing `HasFluentValidation` trait | Add `use HasFluentValidation` to the component |
| Trait collision with Filament | Don't use the trait — use `RuleSet::compileToArrays()` instead |
| `assertHasErrors` can't find rule identifiers | Works automatically since 0.4.2 (self-validation forwards identifiers) |
| Wrapping FluentRule in arrays `[FluentRule::string()]` | Don't wrap — put FluentRule directly as the value |
| `each()` not expanding in Livewire | Make sure you're using the `HasFluentValidation` trait — it overrides `getRules()` to expand `each()` |
| PHPStan errors on `$this->validate()` | The trait overrides `validate()` with correct types |
