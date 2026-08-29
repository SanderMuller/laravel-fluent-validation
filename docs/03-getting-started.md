# Getting started

The common case: a form request whose rules read as chains instead of strings.

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
            'title' => FluentRule::string('Title')->required()->min(2)->max(255),
            'body'  => FluentRule::string()->required(),
            'email' => FluentRule::email()->required()->unique('users'),
        ];
    }
}
```

That is the whole setup: the trait, and rules returned as `FluentRule` chains. Use the request as you always have.

```php
public function store(StorePostRequest $request)
{
    $post = Post::create($request->validated());

    return redirect()->route('posts.show', $post);
}
```

Two things are already working that you did not configure:

- **`'Title'` is the label.** It replaces `:attribute` in the message, so a failure reads "The Title field is required" without a separate `attributes()` array.
- **Your editor knows the rules.** `FluentRule::string()` offers only the methods that apply to a string, so `->min(2)` is a length and not a number, and a typo is a type error rather than a runtime surprise.

## Next

- [Basic usage](04-basic-usage.md): the other places rules live: `$request->validate()`, `Validator::make()`, and `FluentFormRequest`.
- [Error messages](05-error-messages.md): labels, per-rule messages, and translations.
- [Array validation](06-array-validation.md): `each()` and `children()` for nested payloads.
- [Migrating an existing app](13-migration.md): the Rector rules that convert what you already have.
