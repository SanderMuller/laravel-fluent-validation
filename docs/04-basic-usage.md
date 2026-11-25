# Basic usage

## In a form request

Add the `HasFluentRules` trait to your form request:

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
            'title'    => FluentRule::string('Title')->required()->min(2)->max(255),
            'body'     => FluentRule::string()->required(),
            'email'    => FluentRule::email('Email')->required()->unique('users'),
            'date'     => FluentRule::date('Publish Date')->required()->afterToday(),
            'agree'    => FluentRule::accepted(),
            'avatar'   => FluentRule::image()->nullable()->max('2mb'),
            'tags'     => FluentRule::array(label: 'Tags')->required()->each(
                              FluentRule::string()->max(50)
                          ),
            'password' => FluentRule::password()->required()->mixedCase()->numbers(),
        ];
    }
}
```

The label `'Title'` replaces `:attribute` in error messages. You get "The Title field is required" instead of "The title field is required", without a separate `attributes()` array.

Or extend `FluentFormRequest` instead of adding the trait manually:

```php
use SanderMuller\FluentValidation\FluentFormRequest;

class StorePostRequest extends FluentFormRequest
{
    public function rules(): array { /* same as above */ }
}
```

> [!NOTE]
> `FluentRule` is a static factory, not a base class. `FluentRule::string()` returns a `StringRule`, `FluentRule::email()` returns an `EmailRule`, etc. For PHPDoc type hints, reference `FluentRuleContract` (see below) or Laravel's `ValidationRule`, not `FluentRule` itself.

### Typing your `rules()` return

Every shipped rule class implements `SanderMuller\FluentValidation\Contracts\FluentRuleContract`, a single stable type alias covering the full shared modifier and conditional surface. Use it instead of enumerating concrete types:

```php
use SanderMuller\FluentValidation\Contracts\FluentRuleContract;

/** @return array<string, FluentRuleContract> */
public function rules(): array
{
    return [
        'name'  => FluentRule::string()->required()->min(2),
        'email' => FluentRule::email()->required()->unique('users'),
        'age'   => FluentRule::numeric()->nullable()->integer()->min(0),
    ];
}
```

`FluentRuleContract extends Illuminate\Contracts\Validation\ValidationRule`, so downstream code already typed against Laravel's native contract keeps working. Type-specific methods (e.g. `StringRule::email()`, `NumericRule::integer()`, `ImageRule::dimensions()`) stay on their concrete classes. Narrow to the concrete type when you need to call them.

### The `schema()` builder

To drop the `FluentRule::` prefix, define `schema(FluentSchema $rules)` instead of `rules()`, or next to it:

```php
use Illuminate\Foundation\Http\FormRequest;
use SanderMuller\FluentValidation\FluentSchema;
use SanderMuller\FluentValidation\HasFluentRules;

class StorePostRequest extends FormRequest
{
    use HasFluentRules;

    public function schema(FluentSchema $rules): array
    {
        return [
            'title' => $rules->string('Title')->required()->min(2)->max(255),
            'email' => $rules->email('Email')->required()->unique('users'),
            'items' => $rules->array()->required()->each([
                'name' => $rules->string()->required(),
            ]),
        ];
    }
}
```

`$rules->string()` returns the same rule as `FluentRule::string()`, and macros on `FluentRule` work on `$rules` too.

When a request or its parents define both `schema()` and `rules()`, the package merges them. On a shared field the more specific declaration wins: the deeper class, or a method in the class body over one from a trait. On the same class, `schema()` wins. Only a `schema()` method with a `FluentSchema` parameter counts, and either method may return an array or a `RuleSet`.

A request that defines only `schema()` still answers `->rules()` with the builder's output.

## Other contexts

For Livewire components, use the [`HasFluentValidation`](08-livewire.md) trait. For inline validation outside form requests, use [`RuleSet::validate()`](12-validating.md). For custom Validator subclasses, extend [`FluentValidator`](11-ruleset.md#using-with-custom-validators).

FluentRule objects implement Laravel's `ValidationRule` interface, so they also work directly in `$request->validate()`, `Validator::make()`, `Rule::forEach()`, and `Rule::when()`:

```php
use SanderMuller\FluentValidation\FluentRule;

$validated = $request->validate([
    'name'  => FluentRule::string()->required()->min(2)->max(255),
    'email' => FluentRule::email()->required(),
    'age'   => FluentRule::numeric()->nullable()->integer()->min(0),
]);
```

In these direct-call contexts FluentRule objects self-validate: a label shows only in the messages of its own rule, and the [five optimizations](09-performance.md) don't engage. Reach for the trait or `RuleSet::validate()` for production code.
