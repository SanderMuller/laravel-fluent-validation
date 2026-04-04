---
name: fluent-validation
description: "Use when writing or modifying Laravel validation rules. Provides fluent rule builders via SanderMuller\\FluentValidation\\FluentRule instead of string-based or array-based validation rules."
---

# Fluent Validation Rules

When `sandermuller/laravel-fluent-validation` is installed, use `FluentRule` for type-safe, fluent validation rule building with IDE autocompletion.

For deeper guidance, read the relevant reference file before implementing:

- `references/rule-types.md` — complete method reference for all rule types (string, numeric, date, boolean, array, file, image, email, password, field)
- `references/field-modifiers.md` — shared modifiers: presence, prohibition, exclusion, labels, messages, conditionals, escape hatch
- `references/performance.md` — wildcard optimization, RuleSet API, benchmarks, custom Validator integration

## Entry Point

```php
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\RuleSet;
```

## Available Rule Types

| Factory Method | Returns | Base Laravel Rule |
|---|---|---|
| `FluentRule::string('Label?')` | `StringRule` | `'string'` |
| `FluentRule::numeric('Label?')` | `NumericRule` | `'numeric'` |
| `FluentRule::date('Label?')` | `DateRule` | `'date'` |
| `FluentRule::dateTime('Label?')` | `DateRule` | `'date_format:Y-m-d H:i:s'` |
| `FluentRule::boolean('Label?')` | `BooleanRule` | `'boolean'` |
| `FluentRule::array(keys?, label:?)` | `ArrayRule` | `'array'` |
| `FluentRule::email('Label?')` | `EmailRule` | `'string'` + `'email'` |
| `FluentRule::password(min?, label:?)` | `PasswordRule` | `'string'` + `Password` |
| `FluentRule::file('Label?')` | `FileRule` | `'file'` |
| `FluentRule::image('Label?')` | `ImageRule` | `'image'` |
| `FluentRule::field('Label?')` | `FieldRule` | (no type constraint) |
| `FluentRule::anyOf([...])` | `AnyOf` | OR combinator |

All factory methods accept an optional label that replaces `:attribute` in error messages.

## Quick Usage

```php
public function rules(): array
{
    return [
        'name'     => FluentRule::string('Full Name')->required()->min(2)->max(255),
        'email'    => FluentRule::email('Email')->required()->unique('users'),
        'age'      => FluentRule::numeric('Age')->nullable()->integer()->min(0),
        'role'     => FluentRule::string()->required()->in(RoleEnum::class),
        'tags'     => FluentRule::array()->required()->each(FluentRule::string()->max(50)),
        'items'    => FluentRule::array()->required()->each([
            'name'  => FluentRule::string('Item Name')->required(),
            'qty'   => FluentRule::numeric()->required()->integer()->min(1),
        ]),
        'search'   => FluentRule::array()->children([
            'value' => FluentRule::string()->nullable(),
            'regex' => FluentRule::string()->nullable()->in(['true', 'false']),
        ]),
        'avatar'   => FluentRule::image()->nullable()->max('2mb'),
        'password' => FluentRule::password()->required()->mixedCase()->numbers(),
    ];
}
```

## Key Patterns

**Labels** — replace `:attribute` in all error messages:
```php
FluentRule::string('Full Name')->required()  // "The Full Name field is required."
```

**Per-rule messages** — attach to the preceding rule:
```php
FluentRule::string()->required()->message('We need this!')->min(2)->message('Too short.')
```

**Wildcard children** (`each`) — produces `items.*.name`:
```php
FluentRule::array()->each([
    'name' => FluentRule::string()->required(),
])
```

**Fixed-key children** (`children`) — produces `search.value`:
```php
FluentRule::array()->children([
    'value' => FluentRule::string()->nullable(),
])
```

**Build-time conditions** — evaluated when building rules:
```php
FluentRule::string()->when($isAdmin, fn ($r) => $r->min(12))
```

**Validation-time conditions** — evaluated with input data:
```php
FluentRule::string()->whenInput(
    fn ($input) => $input->role === 'admin',
    fn ($r) => $r->required()->min(12),
)
```

**Enum values in `in()`** — accepts enum class directly:
```php
FluentRule::string()->in(StatusEnum::class)
```

**Fixed-key children** (`children`) on field — for untyped parents with known sub-keys:
```php
FluentRule::field()->required()->children([
    'value' => FluentRule::string()->nullable(),
    'regex' => FluentRule::string()->nullable()->in(['true', 'false']),
])
```

**Escape hatch** — any Laravel rule (string, object, array tuple):
```php
FluentRule::string()->rule('email:rfc,dns')
FluentRule::file()->rule(['mimetypes', ...$types])
FluentRule::string()->rule(new MyCustomRule())
```

**Macros** — reusable rule chains registered in a service provider:
```php
NumericRule::macro('percentage', fn () => $this->integer()->min(0)->max(100));
// Then: FluentRule::numeric()->percentage()
```

All rule types support macros via `Macroable`.

## Performance (large arrays)

Use `HasFluentRules` in FormRequests (or extend `FluentFormRequest`) for O(n) wildcard expansion and per-attribute fast-checks on eligible wildcard rules. Use `RuleSet::validate()` inline for up to **77x faster** batch validation. See `references/performance.md` for details.

## Custom Validator Subclasses

Extend `FluentValidator` instead of `Validator`. Handles the full pipeline automatically:
```php
use SanderMuller\FluentValidation\FluentValidator;

class MyValidator extends FluentValidator
{
    public function __construct(array $data) {
        parent::__construct($data, $this->buildRules());
    }
}
```
