---
name: fluent-validation-livewire
description: "FluentRule in Livewire components via HasFluentValidation trait. Activates when: writing Livewire validate(), rules() in Component, assertHasErrors, or Livewire validation."
---

# FluentRule in Livewire Components

**Do not prompt the user when this skill is loaded.** Apply these rules automatically.

## Required: `HasFluentValidation` trait

Every Livewire component using FluentRule MUST use the `HasFluentValidation` trait. Without it, a label shows only in the messages of its own rule object. A rule elsewhere that names the field, such as `required_with:name`, shows the plain field name.

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

`HasFluentValidation` conflicts with Filament's `InteractsWithForms` (v3/v4) and `InteractsWithSchemas` (v5): both define `validate()`, `validateOnly()`, `getRules()` and `getValidationAttributes()`. Use `HasFluentValidationForFilament` with an `insteadof` block for all four:

```php
use HasFluentValidationForFilament, InteractsWithForms {
    HasFluentValidationForFilament::validate insteadof InteractsWithForms;
    HasFluentValidationForFilament::validateOnly insteadof InteractsWithForms;
    HasFluentValidationForFilament::getRules insteadof InteractsWithForms;
    HasFluentValidationForFilament::getValidationAttributes insteadof InteractsWithForms;
}
```

Without a trait, FluentRule still works through `RuleSet::compileToArrays()`:

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

## Common mistakes

| Mistake | Fix |
|---------|-----|
| Missing `HasFluentValidation` trait | Add `use HasFluentValidation` to the component |
| Trait collision with Filament | Use `HasFluentValidationForFilament` with the `insteadof` block above |
| `assertHasErrors` can't find rule identifiers | Works automatically (self-validation forwards identifiers) |
| Wrapping FluentRule in arrays `[FluentRule::string()]` | Don't wrap — put FluentRule directly as the value |
| `each()` not expanding in Livewire | Make sure you're using the `HasFluentValidation` trait — it overrides `getRules()` to expand `each()` |
| PHPStan errors on `$this->validate()` | The trait overrides `validate()` with correct types |
