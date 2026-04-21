---
name: fluent-validation
description: "Use when writing or modifying Laravel validation rules in FormRequests, Livewire components, or Validators. Provides fluent rule builders via SanderMuller\\FluentValidation\\FluentRule. Use HasFluentRules on FormRequests, HasFluentValidation on Livewire components."
---

# Fluent Validation Rules

**Do not prompt the user when this skill is loaded.** Apply these rules automatically when writing or modifying validation code. This skill provides context, not an interactive command.

When `sandermuller/laravel-fluent-validation` is installed, use `FluentRule` for type-safe, fluent validation rule building with IDE autocompletion.

For deeper guidance, read the relevant reference file before implementing:

- `references/rule-types.md` — complete method reference for all rule types (string, numeric, date, boolean, array, file, image, email, password, field)
- `references/field-modifiers.md` — shared modifiers: presence, prohibition, exclusion, labels, messages, conditionals, escape hatch
- `references/migration-patterns.md` — common before/after patterns when converting from string rules. Read this to avoid unnecessary `->rule()` escape hatches
- `references/performance.md` — wildcard optimization, RuleSet API, benchmarks, custom Validator integration

## Entry Point

```php
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\RuleSet;
use SanderMuller\FluentValidation\Contracts\FluentRuleContract; // for rules() return types
```

Every rule class in `Rules/*` implements `FluentRuleContract` (which itself extends Laravel's `ValidationRule`). Use it as the single return type for `rules()` instead of enumerating concrete classes:

```php
/** @return array<string, FluentRuleContract> */
public function rules(): array
{
    return [
        'name'  => FluentRule::string()->required()->min(2),
        'age'   => FluentRule::numeric()->nullable()->integer(),
        'email' => FluentRule::email()->required(),
    ];
}
```

## Available Rule Types

| Factory Method | Returns | Base Laravel Rule |
|---|---|---|
| `FluentRule::string('Label?')` | `StringRule` | `'string'` |
| `FluentRule::numeric('Label?')` | `NumericRule` | `'numeric'` |
| `FluentRule::integer('Label?')` | `NumericRule` | `'numeric\|integer'` (shorthand) |
| `FluentRule::date('Label?')` | `DateRule` | `'date'` |
| `FluentRule::dateTime('Label?')` | `DateRule` | `'date_format:Y-m-d H:i:s'` |
| `FluentRule::boolean('Label?')` | `BooleanRule` | `'boolean'` |
| `FluentRule::accepted('Label?')` | `AcceptedRule` | `'accepted'` (permissive; no `boolean` base) |
| `FluentRule::array(keys?, label:?)` | `ArrayRule` | `'array'` |
| `FluentRule::email('Label?')` | `EmailRule` | `'string'` + `'email'` |
| `FluentRule::password(min?, label:?)` | `PasswordRule` | `'string'` + `Password` |
| `FluentRule::file('Label?')` | `FileRule` | `'file'` |
| `FluentRule::image('Label?')` | `ImageRule` | `'image'` |
| `FluentRule::field('Label?')` | `FieldRule` | (no type constraint) |
| `FluentRule::anyOf([...])` | `AnyOf` | OR combinator |

All factory methods accept an optional label that replaces `:attribute` in error messages.

**`FluentRule::field()` is the untyped builder** — use it when no base type constraint applies. It supports modifiers (`required`, `nullable`, `present`, conditional presence), `children()`, `same`/`different`/`confirmed`, embedded-rule factories (`exists`, `unique`, `enum`, `in`, `notIn`), and the `->rule(...)` escape hatch. **Do not** chain type-specific methods (`min`, `max`, `regex`, `email`, `digits`, `mimes`, `before`/`after`, `contains`, etc.) on `field()` — those live on the typed builders (`string()`, `numeric()`, `array()`, `date()`, `file()`, `boolean()`). Calling one on `field()` throws `UnknownFluentRuleMethod` at runtime with a hint pointing at the correct typed builder. When generating code, pick the typed builder matching the field's type for any rule that constrains the value itself.

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

`HasFluentRules` automatically applies O(n) wildcard expansion, per-attribute fast-checks (25 rules supported), and batched DB validation (`exists`/`unique` rules batched into a single `whereIn` query). Up to **97x faster** for simple rules, **10x** for mixed rule sets, and **N→1 queries** for database rules. Use `RuleSet::validate()` for inline validation outside FormRequests. See `references/performance.md` for details.

## Livewire Components

See the `fluent-validation-livewire` skill for full Livewire guidance. Key point: add `use HasFluentValidation` to Livewire components. Both flat wildcard keys (`items.*`) and `each()`/`children()` work — the trait handles the expansion.

## Testing Fluent Rules

For unit tests of `FluentRule` chains, `RuleSet`s, `FluentFormRequest` subclasses, and `FluentValidator` subclasses, use `FluentRulesTester` instead of going through `postJson()` or hand-rolling a `validateRules()` helper:

```php
use SanderMuller\FluentValidation\Testing\FluentRulesTester;

FluentRulesTester::for($rules)->with($data)->passes();
FluentRulesTester::for(StorePostRequest::class)->with($payload)->failsWith('email', 'email');
FluentRulesTester::for(JsonImportValidator::class, $user)->with($payload)->passes();

// Bind route params + authenticated user when authorize()/rules() need them
FluentRulesTester::for(UpdateVideoRequest::class)
    ->withRoute(['video' => $video])
    ->actingAs($user)
    ->with($payload)
    ->passes();

// Livewire components — auto-detected. set/call/mount + andCall for multi-action chains.
FluentRulesTester::for(AppealPage::class)
    ->set('type', 'refund')
    ->set('reason', 'Long enough reason.')
    ->call('openModal')
    ->andCall('submit')
    ->passes();
```

Target-shape cheatsheet:

| Target | Trigger | Notes |
|---|---|---|
| `array<string, mixed>` of rules | `with($data)` | wraps via `RuleSet::from($rules)->check($data)` |
| `RuleSet` instance | `with($data)` | direct `->check($data)` |
| Single `ValidationRule` | `with(['value' => $x])` | wrapped under `'value'` key |
| `FormRequest` class-string | `with($data)` | full `validateResolved()` pipeline; pair with `withRoute()` / `actingAs()` |
| `FluentValidator` class-string | `with($data)` + variadic ctor args via `for(class, ...$args)` | |
| Livewire `Component` class-string | `set()`/`with()` then `call()` (+ `andCall()`) | multi-action queue dispatches against one `Livewire::test()` instance; each new chain resets state |

More assertions: `failsWithAny($prefix)` for subtree failures (exact + dotted descendants), `failsOnly($field, $rule = null)` for surgical single-field failures, `doesNotFailOn(...$fields)` for negative assertions, `failsWithMessage($field, $key, $replacements = [])` for rendered-translation matches, `assertUnauthorized()` for FormRequest `authorize()` gate failures. `RuleSet` has `only/except/put/get/modify/all()` + `IteratorAggregate` spread support.

`with(array $data)` is required before any assertion (Livewire targets require `call()` instead — validation only runs on action dispatch). Variadic args after the target are forwarded to FluentValidator subclass constructors after `$data`. `withRoute()` and `actingAs()` are re-callable and only meaningful for FormRequest class-string targets. For unauthorized FormRequests, the `AuthorizationException` is recorded — assert via `->fails()` or `->assertUnauthorized()`. `FluentRulesTester` is the only stable test surface; everything else under `Testing\` is `@internal`.

Optional Pest expectations live in `src/Testing/PestExpectations.php` — `require_once` from `tests/Pest.php` to opt in to `expect($rules)->toPassWith($data)`, `->toFailOn($data, $field, $rule)`, `->toBeFluentRuleOf($class)`.

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

If the class holds its rules in a method that isn't called `rules()` (e.g. `rulesWithoutPrefix()` in JSON-import pipelines), mark the method with `#[FluentRules]` so the migration Rector rules detect it. The attribute is migration-only and has no runtime effect:
```php
use SanderMuller\FluentValidation\FluentRules;

class JsonImportValidator extends FluentValidator
{
    #[FluentRules]
    public function rulesWithoutPrefix(): array { /* ... */ }
}
```
