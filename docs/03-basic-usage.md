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

Prefer one injected builder over repeating the `FluentRule::` prefix on every line? Define a `schema(FluentSchema $rules)` method in place of `rules()`, or alongside it, since the two merge (see below). You receive a `FluentSchema` instance and chain field starters off it — the same shape Laravel's AI SDK uses for structured output:

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

`$rules->string()` is exactly `FluentRule::string()` — same rule objects, same labels, same five optimizations. It's pure ergonomics: one builder instead of the static prefix, with the typed parameter autocompleting the full factory list. Macros registered on `FluentRule` are reachable on `$rules` too.

When a request (or any class in its hierarchy) defines both `schema()` and `rules()`, the two are merged rather than one shadowing the other. On a shared field the more specific declaration wins: the deeper class in the hierarchy, or a body definition over a trait import. So an abstract base or trait can supply shared fields, and a concrete request overrides or extends them, exactly like a plain method override. Non-colliding fields from both survive. When both are declared on the same class, the tie resolves to `schema()`. Detection keys off the `FluentSchema`-typed parameter, so an unrelated `schema()` method is never hijacked, and either method may return a plain array or a `RuleSet`.

A request that defines only `schema()` can still call `->rules()`; it returns the builder's output, so tooling or interop that expects a `rules()` method keeps working.

## Other contexts

For Livewire components, use the [`HasFluentValidation`](07-livewire.md) trait. For inline validation outside form requests, use [`RuleSet::validate()`](09-ruleset.md#validating-data). For custom Validator subclasses, extend [`FluentValidator`](09-ruleset.md#using-with-custom-validators).

FluentRule objects implement Laravel's `ValidationRule` interface, so they also work directly in `$request->validate()`, `Validator::make()`, `Rule::forEach()`, and `Rule::when()`:

```php
use SanderMuller\FluentValidation\FluentRule;

$validated = $request->validate([
    'name'  => FluentRule::string()->required()->min(2)->max(255),
    'email' => FluentRule::email()->required(),
    'age'   => FluentRule::numeric()->nullable()->integer()->min(0),
]);
```

In these direct-call contexts FluentRule objects self-validate in isolation: labels don't reach the outer validator and the [five optimizations](08-performance.md) don't engage. Reach for the trait or `RuleSet::validate()` for production code.
