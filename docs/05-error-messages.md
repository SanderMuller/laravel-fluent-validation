# Error messages

## Labels

A label is the fluent equivalent of Laravel's [custom attribute name](https://laravel.com/docs/validation#specifying-custom-attribute-values): the value you would otherwise put in an `attributes()` array. Pass it as the first argument to any factory method and it replaces `:attribute` in error messages for that field:

```php
use Illuminate\Foundation\Http\FormRequest;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\HasFluentRules;

class StorePostRequest extends FormRequest
{
    use HasFluentRules;

    public function rules(): array
    {
        return [
            'name'  => FluentRule::string('Full Name')->required()->min(2)->max(255),
            'email' => FluentRule::email('Email Address')->required(),
            'age'   => FluentRule::integer('Your Age')->nullable()->min(0),
            'items' => FluentRule::array(label: 'Import Items')->required()->min(1),
        ];
        // "The Full Name field is required."
        // "The Email Address field must be a valid email address."
        // "The Import Items field must have at least 1 items."
    }
}
```

Labels also work inside `each()`, so child fields get clean names:

```php
'items' => FluentRule::array()->required()->each([
    'name'  => FluentRule::string('Item Name')->required(),
    'email' => FluentRule::email('Email')->required(),
]),
// "The Item Name field is required." (instead of "The items.0.name field is required.")
```

You can also set a label after construction with `->label('Name')`.

> [!CAUTION]
> Labels only reach the validator through one of the four pathways below. With bare `$request->validate()` or `Validator::make()`, FluentRule objects self-validate in isolation and the label is dropped, so you'll see Laravel's default `:attribute` (the snake-cased field name) in error messages instead.
>
> | Context           | Use                                                |
> |-------------------|----------------------------------------------------|
> | FormRequest       | [`HasFluentRules`](04-basic-usage.md#in-a-form-request)             |
> | Inline / anywhere | [`RuleSet::validate()`](11-ruleset.md#validating-data)          |
> | Livewire          | [`HasFluentValidation`](08-livewire.md)                 |
> | Custom validator  | [`FluentValidator`](11-ruleset.md#using-with-custom-validators) |

## Per-rule messages

The recommended form is the inline `message:` named argument, which attaches the message directly to the rule it applies to:

```php
FluentRule::string('Full Name') // Sets the label, used in the message as :attribute
    ->required(message: 'We need your :attribute!') // Translates to "We need your Full Name!"
    ->min(2, message: ':attribute has to be least :min characters.') // Translates to "Full Name has to be at least 2 characters."
    ->max(255)
```

Inline `message:` is available on the factory itself (e.g. `FluentRule::email(message: 'Invalid email.')`) and on every non-variadic rule method.

Three forms exist; each has a use case:

| Form                        | When to use                                                                                                                                                                                                |
|-----------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `->method(…, message: '…')` | **Recommended.** Colocated with the rule, rename-safe, works on factories and rule methods. Unavailable on variadic-trailing methods (see below).                                                          |
| `->method(…)->message('…')` | Shorthand when you want the message on the most recent rule. Binds to `$lastConstraint`. Works on variadic methods too (`->requiredWith('a', 'b')->message('…')`).                                         |
| `->messageFor('rule', '…')` | Targets a rule by name at any point in the chain. Use when you need to message a non-last sub-rule on composite methods (e.g. `integer` under `->digits(…)`), or a Macroable method PHPStan/IDE can't see. |

```php
// Variadic method: message: cannot follow a variadic param. Use ->message() (shorter) or messageFor().
FluentRule::string()->requiredWith('email', 'phone')->message('Required when email or phone is set.')

// Composite method (->digits adds `integer` then `digits:N`). message: binds to the LAST sub-rule.
FluentRule::numeric()->digits(5, message: ':attribute must be 5 digits.')
    ->messageFor('integer', ':attribute must be a whole number.')

// Custom rule object: message: on ->rule() binds to the object's class-basename key.
FluentRule::string()->rule(new MyRule(), message: 'Custom failure.')
```

For a field-level fallback that applies to any failure, use `->fieldMessage()`:

```php
FluentRule::string()->required()->min(2)->fieldMessage('Something is wrong with this field.')
```

> [!NOTE]
> Standard Laravel `messages()` arrays and `Validator::make()` message arguments still work and take priority over `message:`, `->message()`, `->messageFor()`, and `->fieldMessage()`. When Laravel emits multiple rule keys for a single fluent factory (e.g. `FluentRule::email()->when(..., fn ($r) => $r->required())` produces `required` + `string` + `email`), each distinct message still belongs in `messages()`; inline `message:` only carries one binding per call.
