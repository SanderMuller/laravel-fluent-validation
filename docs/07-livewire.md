# Livewire

Add the `HasFluentValidation` trait to Livewire components. It compiles FluentRule objects before Livewire's validator sees them:

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

The trait provides full Livewire support. Labels, messages, `each()`, `children()`, `wire:model.blur` real-time validation, Form objects, and `$rules` properties all work automatically.

```php
// both styles work in Livewire components:

// flat wildcard keys
'items'        => FluentRule::array()->required(),
'items.*.name' => FluentRule::string()->required(),

// each(): the trait expands this for Livewire automatically
'items' => FluentRule::array()->required()->each([
    'name' => FluentRule::string()->required(),
]),
```

**Filament components:** `HasFluentValidation` conflicts with Filament's `InteractsWithForms` (v3/v4) / `InteractsWithSchemas` (v5) because both define `validate()`, `validateOnly()`, `getRules()`, and `getValidationAttributes()`. Use `HasFluentValidationForFilament` instead. The [Rector companion](https://github.com/sandermuller/laravel-fluent-validation-rector) picks it plus the required `insteadof` block whenever `InteractsWithForms` / `InteractsWithSchemas` is used on a class, every run. FluentRule compilation, label/message extraction, `each()`/`children()` expansion, Filament's `form-validation-error` event dispatch, and form-schema rule aggregation all keep working.

<details>
<summary>Manual setup (if you're not running the Rector companion)</summary>

```php
use Filament\Forms\Concerns\InteractsWithForms;
use SanderMuller\FluentValidation\HasFluentValidationForFilament;

class EditUser extends Component implements HasForms
{
    use HasFluentValidationForFilament, InteractsWithForms {
        HasFluentValidationForFilament::validate insteadof InteractsWithForms;
        HasFluentValidationForFilament::validateOnly insteadof InteractsWithForms;
        HasFluentValidationForFilament::getRules insteadof InteractsWithForms;
        HasFluentValidationForFilament::getValidationAttributes insteadof InteractsWithForms;
    }
}
```

</details>

## Livewire + Laravel Boost

If you use [Laravel Boost](https://github.com/laravel/boost), the `fluent-validation-livewire` skill covers trait usage, Filament workarounds, and common mistakes automatically.
