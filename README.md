# Fluent validation rule builders for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sandermuller/laravel-fluent-validation.svg?style=flat-square)](https://packagist.org/packages/sandermuller/laravel-fluent-validation)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/sandermuller/laravel-fluent-validation/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/sandermuller/laravel-fluent-validation/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub PHPStan Action Status](https://img.shields.io/github/actions/workflow/status/sandermuller/laravel-fluent-validation/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/sandermuller/laravel-fluent-validation/actions?query=workflow%3Aphpstan+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/sandermuller/laravel-fluent-validation.svg?style=flat-square)](https://packagist.org/packages/sandermuller/laravel-fluent-validation)
[![License](https://img.shields.io/github/license/sandermuller/laravel-fluent-validation.svg?style=flat-square)](LICENSE)
[![Laravel Compatibility](https://badge.laravel.cloud/badge/sandermuller/laravel-fluent-validation?style=flat)](https://packagist.org/packages/sandermuller/laravel-fluent-validation)

Write Laravel validation rules with IDE autocompletion instead of memorizing string syntax. Each rule type exposes only the methods that apply to it: `FluentRule::string()` won't offer `digits()`, `FluentRule::date()` won't offer `mimes()`. `each()` and `children()` keep parent and child rules in one place instead of scattered across dot-notation keys. For large arrays, the `HasFluentRules` trait makes wildcard validation [up to 160x faster](#benchmarks).

```php
// Before
'name'         => 'required|string|min:2|max:255',
'email'        => ['required', 'email', Rule::unique('users')->ignore($id)],
'role'         => Rule::when($isAdmin, 'required|string|in:admin,editor'),
'items'        => 'array',
'items.*.id'   => 'required|integer|exists:items,id',
'items.*.name' => 'required|string|max:255',

// After
'name'  => FluentRule::string('Full Name')->required()->min(2)->max(255),
'email' => FluentRule::email('Email')->required()->unique('users', 'email', fn ($r) => $r->ignore($id)),
'role'  => FluentRule::string()->when($isAdmin, fn ($r) => $r->required()->in(['admin', 'editor'])),
'items' => FluentRule::array()->each([
    'id'   => FluentRule::integer()->required()->exists('items', 'id'),
    'name' => FluentRule::string()->required()->max(255),
]),
```

> **Migrating an existing codebase?** Jump straight to [Migrating existing validation with Rector](#migrating-existing-validation-with-rector); a companion package automates the bulk of the rewrite.

## Contents

**Getting started**
- [Why this package?](#why-this-package): DX, type safety, structure, performance, and how it compares to Laravel's `Rule` class
- [Installation](#installation)
- [Usage](#usage): Form Requests, migrating existing rules, extending parent rules, other contexts
- [Error messages](#error-messages): labels, per-rule messages
- [Array validation](#array-validation-with-each-and-children): each, children, nesting

**Migration**
- [Migrating existing validation with Rector](#migrating-existing-validation-with-rector): automated rewrite via [companion Rector package](https://github.com/sandermuller/laravel-fluent-validation-rector)
- [Common migration patterns](resources/boost/skills/fluent-validation/references/migration-patterns.md): detailed reference for manual conversions (external doc)

**Static analysis**
- [Static analysis with PHPStan](#static-analysis-with-phpstan): opt-in [PHPStan rules package](https://github.com/sandermuller/laravel-fluent-validation-phpstan) that flags unbounded `each()` chains

**Deep dive**
- [Livewire](#livewire): HasFluentValidation trait, Filament workaround
- [Performance](#performance): O(n) wildcards, pre-evaluation, fast-check closures, batched DB, benchmarks
- [RuleSet](#ruleset): builder, conditional fields, custom Validators
- [Testing](#testing): `FluentRulesTester`, Pest expectations
- [Rule reference](#rule-reference): all types, modifiers, conditionals, macros
- [Troubleshooting](#troubleshooting): common issues and solutions

## Why this package?

### Fluent

If you've ever wondered whether `min:5` means characters, an integer, array elements, or kilobytes (it depends on the type rule next to it), forgotten which slot in `unique:users,email,$ignoreId,id` holds the ignored ID, or had to look up whether to use `date_equals`, `same`, or `before_or_equal` to compare two dates, you know the frustration. The IDE answers that for you now. Each type has its own class, so `FluentRule::string()` won't even offer `digits()`, and `FluentRule::date()` won't offer `mimes()`.

### Array notation

`each()` and `children()` group parent and child rules in one place instead of scattering them across 20 flat dot-notation keys. Wildcard children land under their parent definition; fixed-key children stay scoped to the field they belong to. Nested arrays nest naturally. The flat `'items.*.name'` form still works when you want it.

### Messages & attributes

Labels and per-rule messages attach to the rule itself, so there's no separate `messages()` or `attributes()` array to drift out of sync with `rules()`. `FluentRule::email('Email Address')->required(message: 'We need your :attribute.')` carries both the human-readable name and the failure copy with the rule definition.

### Performance

Where you'll actually feel this is on endpoints that validate **a lot of fields** or **a lot of items at once**: CSV/JSON imports, bulk-edit forms, settings pages, anything with wildcard arrays like `items.*.id` or `orders.*.line_items.*.product_id`. On a 3-field login form FluentRule is still faster than the native pipeline, but you won't notice; the saving is in microseconds.

Laravel's wildcard validation is O(n²) on large arrays; `HasFluentRules` rewrites the expansion as a single tree walk and makes it [up to 160x faster](#benchmarks) for nested wildcards, 62x faster for conditional-heavy payloads. Database `exists`/`unique` checks against wildcard arrays batch into a single `whereIn` query instead of one per item. Common rules compile to PHP closures that bypass Laravel's validator entirely on the happy path.

<details>
<summary><a name="compared-to-rule"></a>Compared to Laravel's <code>Rule</code> class</summary>

`FluentRule` is intentionally named differently from `Illuminate\Validation\Rule` so both can be used without aliasing. You generally don't need Laravel's `Rule` at all.

**Type starters**

| Laravel's `Rule`             | FluentRule equivalent                                 |
|------------------------------|-------------------------------------------------------|
| `Rule::string()`             | `FluentRule::string()`                                |
| `Rule::numeric()`            | `FluentRule::numeric()` / `FluentRule::integer()`     |
| `Rule::date()`               | `FluentRule::date()`                                  |
| `Rule::dateTime()`           | `FluentRule::dateTime()`                              |
| `Rule::email()`              | `FluentRule::email()`                                 |
| `Rule::file()`               | `FluentRule::file()`                                  |
| `Rule::imageFile($allowSvg)` | `FluentRule::image()->allowSvg()` (or just `image()`) |
| `Rule::array($keys = null)`  | `FluentRule::array($keys)`                            |
| `Rule::dimensions([...])`    | `FluentRule::image()->minWidth(...)->ratio(...)`      |

**Set membership and value spaces**

| Laravel's `Rule`                  | FluentRule equivalent                                |
|-----------------------------------|------------------------------------------------------|
| `Rule::in([...])`                 | `->in([...])` (on any typed builder)                 |
| `Rule::notIn([...])`              | `->notIn([...])`                                     |
| `Rule::contains([...])`           | `FluentRule::array()->contains(...)`                 |
| `Rule::doesntContain([...])`      | `FluentRule::array()->doesntContain(...)`            |
| `Rule::enum(Status::class)`       | `FluentRule::enum(Status::class)` / `->enum(...)`    |
| `Rule::anyOf([...])`              | `FluentRule::anyOf([...])`                           |

**Database lookups**

| Laravel's `Rule`                       | FluentRule equivalent                                  |
|----------------------------------------|--------------------------------------------------------|
| `Rule::unique('users')->where(...)`    | `->unique('users', 'col', fn ($r) => $r->where(...))`  |
| `Rule::exists('roles')->where(...)`    | `->exists('roles', 'col', fn ($r) => $r->where(...))`  |

**Conditional callables**

| Laravel's `Rule`                            | FluentRule equivalent                       |
|---------------------------------------------|---------------------------------------------|
| `Rule::when($cond, $rules, $default)`       | `->when($cond, fn ($r) => …, fn ($r) => …)` |
| `Rule::unless($cond, $rules, $default)`     | `->when(! $cond, …)`                        |
| `Rule::requiredIf(fn () => …)`              | `->requiredIf(fn () => …)`                  |
| `Rule::requiredUnless(fn () => …)`          | `->requiredUnless(fn () => …)`              |
| `Rule::excludeIf(fn () => …)`               | `->excludeIf(fn () => …)`                   |
| `Rule::excludeUnless(fn () => …)`           | `->excludeUnless(fn () => …)`               |
| `Rule::prohibitedIf(fn () => …)`            | `->prohibitedIf(fn () => …)`                |
| `Rule::prohibitedUnless(fn () => …)`        | `->prohibitedUnless(fn () => …)`            |

**Iteration**

| Laravel's `Rule`                  | FluentRule equivalent                                |
|-----------------------------------|------------------------------------------------------|
| `Rule::forEach(fn ($v, $k) => …)` | `FluentRule::array()->each(FluentRule::string()->…)` |

**Authorization (escape hatch only)**

| Laravel's `Rule`                  | FluentRule equivalent                                |
|-----------------------------------|------------------------------------------------------|
| `Rule::can('ability', …$args)`    | `->rule(['can', 'ability', …$args])`                 |

**FluentRule additions with no Laravel equivalent**

| Method                                      | What it does                            |
|---------------------------------------------|-----------------------------------------|
| `->each([key => FluentRule, …])`            | Co-locate wildcard child rules          |
| `->children([key => FluentRule, …])`        | Co-locate fixed-key child rules         |
| `->label('Full Name')`                      | Replaces `:attribute` in messages       |
| `->message('…')` / `->messageFor('…', '…')` | Per-rule custom messages                |
| `->fieldMessage('…')`                       | Field-level fallback message            |
| `->whenInput(fn ($input) => …)`             | Branch on full input at validation time |

</details>

## Installation

You can install the package via composer:

```bash
composer require sandermuller/laravel-fluent-validation
```

Requires PHP 8.2+ and Laravel 11+.

### AI-assisted development

If you use [Laravel Boost](https://github.com/laravel/boost), this package ships with skills that give AI assistants the full FluentRule API reference:

```bash
php artisan boost:install    # adds the skills
php artisan boost:update     # publishes updates after package upgrades
```

## Usage

### In a form request

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

#### Typing your `rules()` return

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

### Migrating existing rules

You don't need to convert all your rules at once. Fluent rules mix freely with string rules and native rule objects in the same array:

```php
$rules = [
    'name'   => FluentRule::string()->required()->min(2)->max(255),  // fluent
    'email'  => 'required|string|email|max:255',               // string, still works
    'role'   => ['required', LaravelRule::in(['admin', 'user'])],  // array, still works
];
```

**Step 1:** Add `use HasFluentRules` to your form request. This works even before you convert any rules.

**Step 2:** Convert fields. Either by hand (start with the ones that benefit most from autocompletion: complex conditionals, date comparisons, nested arrays), or run the [Rector companion](#migrating-existing-validation-with-rector) to migrate the bulk of your rules in one pass and review the diff. Common conversions if you're going manually:

| Before                                              | After                                                                     |
|-----------------------------------------------------|---------------------------------------------------------------------------|
| `'items.*.name' => 'required\|string'`              | `FluentRule::array()->each(['name' => FluentRule::string()->required()])` |
| `'search' => 'array'` and `'search.value' => '...'` | `FluentRule::array()->children(['value' => ...])`                         |
| `Rule::in([...])`                                   | `->in([...])` or `->in(MyEnum::class)`                                    |
| `Rule::unique('users')`                             | `->unique('users')`                                                       |
| `Rule::forEach(fn () => ...)`                       | `FluentRule::array()->each(...)`                                          |

All conditional methods (`requiredIf`, `excludeUnless`, etc.) accept `Closure|bool` in addition to field references. `each()` and `children()` nest naturally. Flat dot-notation keys like `columns.*.data.sort` become nested `each([...children([...])])` trees that mirror the data shape.

> [!TIP]
> **Using Boost?** If you have [Laravel Boost](https://github.com/laravel/boost) installed, ask your AI assistant to run the `fluent-validation-optimize` skill. It scans your codebase for convertible rules, prioritizes by impact, and applies changes file by file.

**Step 3:** For rules without a direct fluent method, use the `rule()` escape hatch:

```php
FluentRule::string()->rule('email:rfc,dns')           // string rule
FluentRule::string()->rule(new MyCustomRule())         // object rule
FluentRule::file()->rule(['mimetypes', ...$types])     // array tuple
```

### Extending parent rules in child form requests

To add fields in a child, use the spread operator: `return [...parent::rules(), 'extra' => FluentRule::string()->required()]`. If you need to modify a parent's rule, clone it first since `->rule()` mutates the object: `$rules['type'] = (clone $rules['type'])->rule(new ExtraRule())`.

When the parent defines a keyed `each([...])` or `children([...])` map and the child needs to add or replace one sub-rule, use the extend helpers on `ArrayRule` / `FieldRule` via `RuleSet::modify`, or reach for the `modifyEach` / `modifyChildren` sugar:

```php
// Parent
return RuleSet::from([
    'answers' => FluentRule::array()->nullable()->max(20)->each([
        'text' => FluentRule::string()->required(),
    ]),
]);

// Child, sugar form (later-wins merge)
return parent::rules()->modifyEach('answers', [
    'id' => FluentRule::numeric()->nullable(),
]);

// Or the strict-add primitive; throws on existing-key collision
return parent::rules()->modify('answers', fn (ArrayRule $rule) =>
    $rule->addEachRule('id', FluentRule::numeric()->nullable())
);
```

`modifyEach` wraps `mergeEachRules` (later-wins on collision); `modifyChildren` wraps `mergeChildRules` on `FieldRule`. For strict add-only semantics, use the primitive `modify(..., fn ($r) => $r->addEachRule(...))`. `addEachRule` / `addChildRule` throw on existing-key collision. Base constraints (`nullable`, `max:20`, etc.) are preserved by design.

`rules()` may also return a `RuleSet` directly; `HasFluentRules` (and `HasFluentValidation` for Livewire) auto-unwrap it via `->toArray()` before passing to the validator. This lets you chain `->only/->except/->merge/->put/->get` and return without a terminal `->toArray()` call:

```php
// Assumes a class-level helper that returns a RuleSet, e.g.
//   class UserRules { public static function base(): RuleSet { return RuleSet::from([...]); } }
public function rules(): RuleSet
{
    return UserRules::base()
        ->only(['email', 'password'])
        ->put('email_confirmation', FluentRule::email()->required()->same('email'));
}
```

`RuleSet` also implements `IteratorAggregate`, so spread works on it too: `[...$ruleSet, 'extra' => FluentRule::string()->required()]`.

### Other contexts

For Livewire components, use the [`HasFluentValidation`](#livewire) trait. For inline validation outside form requests, use [`RuleSet::validate()`](#ruleset). For custom Validator subclasses, extend [`FluentValidator`](#using-with-custom-validators).

FluentRule objects implement Laravel's `ValidationRule` interface, so they also work directly in `$request->validate()`, `Validator::make()`, `Rule::forEach()`, and `Rule::when()`:

```php
use SanderMuller\FluentValidation\FluentRule;

$validated = $request->validate([
    'name'  => FluentRule::string()->required()->min(2)->max(255),
    'email' => FluentRule::email()->required(),
    'age'   => FluentRule::numeric()->nullable()->integer()->min(0),
]);
```

In these direct-call contexts FluentRule objects self-validate in isolation: labels don't reach the outer validator and the [four optimizations](#performance) don't engage. Reach for the trait or `RuleSet::validate()` for production code.

## Error messages

### Labels

Pass a label as the first argument to any factory method. It replaces `:attribute` in error messages for that field:

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
> | FormRequest       | [`HasFluentRules`](#in-a-form-request)             |
> | Inline / anywhere | [`RuleSet::validate()`](#rulesetvalidate)          |
> | Livewire          | [`HasFluentValidation`](#livewire)                 |
> | Custom validator  | [`FluentValidator`](#using-with-custom-validators) |

### Per-rule messages

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

## Array validation with `each()` and `children()`

|                | `each()`                                           | `children()`                                 |
|----------------|----------------------------------------------------|----------------------------------------------|
| **Data shape** | Array of items (`[{...}, {...}, ...]`)             | Single object with known keys (`{key: ...}`) |
| **Produces**   | Wildcard paths (`items.*.name`)                    | Fixed paths (`search.value`)                 |
| **Use when**   | You have a list of N items with the same structure | You have one object with specific sub-keys   |

To validate each item in an array, use the `each()` method:

```php
// Scalar items: each tag must be a string under 255 characters
FluentRule::array()->each(FluentRule::string()->max(255))

// Object items: each item has named fields
FluentRule::array()->required()->each([
    'name'  => FluentRule::string('Item Name')->required(),
    'email' => FluentRule::email()->required(),
    'qty'   => FluentRule::numeric()->required()->integer()->min(1),
])

// Nested arrays
FluentRule::array()->each([
    'items' => FluentRule::array()->each([
        'qty' => FluentRule::numeric()->required()->min(1),
    ]),
])
```

`each()` works standalone and through Form Requests with `HasFluentRules`. The trait and [`RuleSet`](#ruleset) both [optimize wildcard expansion](#performance).

> [!TIP]
> **Catch unbounded `each()` at analyse time.** The companion [PHPStan package](#static-analysis-with-phpstan) flags `each()` chains without a size cap (`->max()`, `->between()`, `->exactly()`, or a key whitelist). That's the shape that turns into an N+1 / DoS footgun with `->exists()` or closure rules on large payloads.

### Fixed-key children with `children()`

When validating an object with known keys (not a wildcard array), you may use `children()` to keep child rules with their parent:

```php
// Instead of:
'search'       => FluentRule::array()->required(),
'search.value' => FluentRule::string()->nullable(),
'search.regex' => FluentRule::string()->nullable()->in(['true', 'false']),

// Write:
'search' => FluentRule::array()->required()->children([
    'value' => FluentRule::string()->nullable(),
    'regex' => FluentRule::string()->nullable()->in(['true', 'false']),
]),
```

`children()` produces fixed paths (`search.value`), while `each()` produces wildcard paths (`items.*.name`). `children()` is also available on `FluentRule::field()` for untyped fields with known sub-keys.

### Combining `each()` and `children()`

Both may be used together on the same array. This example validates a datatable with columns that have nested search and render options:

```php
'columns' => FluentRule::array()->required()->each([
    'data' => FluentRule::field()->nullable()
        ->rule(FluentRule::anyOf([FluentRule::string(), FluentRule::array()]))
        ->children([
            'sort'   => FluentRule::string()->nullable(),
            'render' => FluentRule::array()->nullable()->children([
                'display' => FluentRule::string()->nullable(),
            ]),
        ]),
    'search' => FluentRule::array()->required()->children([
        'value' => FluentRule::string()->nullable(),
    ]),
]),
```

The rule tree mirrors the data shape. Compare this with the flat dot-notation alternative: `columns.*.data`, `columns.*.data.sort`, `columns.*.data.render.display`, `columns.*.search.value`, each defined separately.

> [!TIP]
> **Using Boost?**  
> The `fluent-validation-optimize` skill automatically detects flat dot-notation keys that can be grouped with `each()` and `children()`, and converts them for you.

## Livewire

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

### Livewire + Laravel Boost

If you use [Laravel Boost](https://github.com/laravel/boost), the `fluent-validation-livewire` skill covers trait usage, Filament workarounds, and common mistakes automatically.

## Performance

The win is real for endpoints that validate **a lot of fields** or **a lot of items at once**: CSV/JSON ingest, bulk-edit, settings pages, anywhere a single request hits wildcard arrays like `items.*.id` or `orders.*.line_items.*.product_id`. On a 3-field login form FluentRule is still faster than native, but you won't notice; the saving is in microseconds.

When you use one of the optimized entry points (`HasFluentRules` on a FormRequest, `HasFluentValidation` on a Livewire component, `FluentValidator`, or `RuleSet::validate()`), FluentRule objects compile down to native Laravel format before validation runs and pick up four extra optimizations:

- [**O(n) wildcard expansion**](#on-wildcard-expansion): replaces Laravel's O(n²) `Arr::dot()` + regex expansion with a single tree walk
- [**Pre-evaluation of conditional rules**](#pre-evaluation-of-conditional-rules): resolves `exclude_unless`/`exclude_if` before validation and removes excluded attributes from the rule set
- [**Fast-check closures**](#fast-check-closures): compiles 30+ common rules into PHP closures that skip Laravel's validator entirely for passing values
- [**Batched database validation**](#batched-database-validation): turns N `exists`/`unique` queries into a single `whereIn`

### Benchmarks

| Scenario                                                                    | Optimizations                          | Native Laravel | Optimized   | Speedup |
|-----------------------------------------------------------------------------|----------------------------------------|----------------|-------------|---------|
| [Product import](#product-import), 500 items, simple rules                  | Wildcard, fast-check                   | ~163ms         | **~3ms**    | ~62x    |
| [Nested order lines](#nested-order-lines), 1000 orders × 5 line items       | Wildcard, fast-check (nested)          | ~2,491ms       | **~15ms**   | ~163x   |
| [Conditional import](#conditional-import), 100 items, 47 conditional fields | Wildcard, pre-evaluation               | ~2,928ms       | **~47ms**   | ~62x    |
| [Event scheduling](#event-scheduling), 100 items, field-ref dates           | Wildcard, fast-check (field-ref dates) | ~19ms          | **~0.7ms**  | ~28x    |
| [Article submission](#article-submission), 50 items, custom Rule objects    | Wildcard only                          | ~8ms           | **~2ms**    | ~3x     |
| [Login form](#login-form), 3 fields, no wildcards                           | Fast-check (flat)                      | ~0.1ms         | **~0.02ms** | ~7x     |

All numbers are from `php benchmark.php` (macOS, PHP 8.4, OPcache); CI runs produce the same scenarios on Ubuntu.

### O(n) wildcard expansion

Laravel's `explodeWildcardRules()` flattens data with `Arr::dot()` and matches regex patterns against every key. For each wildcard rule, it scans every key in the flattened array, making the expansion O(n²). The package replaces this with a tree traversal that walks the data once and emits concrete paths as it descends.

### Pre-evaluation of conditional rules

Rules like `exclude_unless` and `exclude_if` are evaluated before the validator starts. Excluded attributes are removed from the rule set entirely, so the validator only sees the rules that actually apply. For a payload with 100 items and 47 conditional fields, this reduces the rule set from ~4,700 to ~200.

### Fast-check closures

The package compiles 30+ common rules into PHP closures that bypass Laravel's validator when values pass. Coverage:

- **Type checks:** `string`, `numeric`, `email`, `date`, `array`, `boolean`, `in`, `regex`
- **Presence gates:** `required`, `prohibited`
- **Date / size / equality comparisons:** literal dates plus wildcard-sibling references (`after:start_date`, `gte:min_price`, `same:password`, `confirmed`)
- **Presence-conditional:** `required_with`, `required_without`, `required_with_all`, `required_without_all`
- **Value-conditional:** `required_if`, `required_unless`, `prohibited_if`, `prohibited_unless`

The two conditional families are pre-evaluated per item against the current row's data: rewritten to bare `required`/`prohibited` when active, or dropped when inactive, so the remainder of the chain fast-checks normally. Dotted dependent paths (`required_without:profile.birthdate`, `required_if:profile.role,admin`) are resolved via `data_get` against the item during reduction.

What the closure does is simpler than what Laravel does. A `string|max:255` rule becomes `is_string($v) && strlen($v) <= 255`. No rule parsing, no method dispatch, no `BigNumber` size comparison. Values that pass never touch the validator. Values that fail fall through to Laravel so the error message stays identical, with no custom-formatting layer to maintain.

Rules that can't be fast-checked (custom Rule objects, closures, `distinct`, `exists`/`unique` with closure callbacks) go through Laravel as normal.

Fast-checks apply to both wildcard rules (`items.*.name`) and flat top-level rules. A simple `RuleSet::from(['name' => 'string|max:255'])->validate($data)` skips Laravel's validator entirely when the value passes.

### Batched database validation

When wildcard arrays use `exists` or `unique` rules, Laravel fires one database query per item. 500 items means 500 queries. `HasFluentRules` and `RuleSet::validate()` batch these into a single `whereIn` query automatically.

Rules with scalar `where()` clauses are batched too. Rules with closure callbacks fall through to per-item validation. Batching is transparent: error messages, custom messages, and `validated()` output are unchanged.

DB batching impact depends on driver and network latency; it is measured in the test suite (`--group=benchmark`) rather than in `benchmark.php`.

**Guards against hostile input.** Because values are batched from raw input before per-item rules run, batching is protected by three layered safeguards so a 100k-element POST body cannot trigger a hundred `whereIn` queries or crash a strict database:

- **Parent `max:N` is honoured.** If the parent array is declared `max:100` but the request sends 1_000 items, batching short-circuits before any query runs, and you see a normal `ValidationException` on the parent attribute. Only the *immediate* parent's `max:N` is inspected (not `size:N`, `between:a,b`, or outer ancestors in nested-wildcard chains). The check also assumes numerically-indexed wildcards (`items.0.id`, `orders.0.items.0.id`); if your API accepts string-keyed collections (`{"items": {"foo": {...}}}`), rely on the hard cap below for defence-in-depth.
- **Per-item type rules filter the batch.** `integer`, `numeric`, `uuid`, `ulid`, `string` rules on each item drop values that couldn't pass validation anyway, so malformed input like `{"id": "abc"}` never reaches a PostgreSQL `INTEGER` column (which would otherwise raise `invalid input syntax for type integer`). End-user error semantics are unchanged; the per-item rule still reports the error.
- **Hard cap.** `BatchDatabaseChecker::$maxValuesPerGroup` (default `10_000`) is a defence-in-depth ceiling per `(table, column, rule-type)` group. Exceeding it throws `SanderMuller\FluentValidation\Exceptions\BatchLimitExceededException`, which the trait and `RuleSet::validate()` / `check()` remap to the standard `ValidationException`. Override once during boot if your legitimate bulk-import endpoints need more headroom:

```php
// app/Providers/AppServiceProvider.php
use SanderMuller\FluentValidation\BatchDatabaseChecker;

public function boot(): void
{
    BatchDatabaseChecker::$maxValuesPerGroup = 50_000;
}
```

Power users who want to handle `parent-max` and `hard-cap` differently (e.g. map to HTTP 413) can catch `BatchLimitExceededException` before the remap; it carries `$reason`, `$ruleType`, `$valueCount`, `$limit`, and `$attribute` for routing decisions.

### `RuleSet::validate()`

For inline validation outside form requests, `RuleSet::validate()` applies the same optimizations:

```php
$validated = RuleSet::from([
    'items' => FluentRule::array()->required()->each([
        'name' => FluentRule::string('Item Name')->required()->min(2),
        'qty'  => FluentRule::numeric()->required()->integer()->min(1),
    ]),
])->validate($request->all());
```

Benchmarks run automatically on PRs via GitHub Actions. All optimizations are Octane-safe (factory resolver restored via try/finally, no static state leakage).

### Benchmark scenarios

#### Product import

500 products with simple, fully fast-checkable rules. All fields pass through PHP closures without touching Laravel's validator.

```php
'products'              => FluentRule::array()->required()->each([
    'sku'               => FluentRule::string()->required()->max(50)->regex('/^SKU-/'),
    'name'              => FluentRule::string()->required()->min(2)->max(255),
    'price'             => FluentRule::numeric()->required()->min(0),
    'quantity'           => FluentRule::numeric()->required()->integer()->min(0),
    'category'          => FluentRule::string()->required()->in(['electronics', 'clothing', 'food']),
    'active'            => FluentRule::boolean()->required(),
    'tags'              => FluentRule::string()->nullable()->max(50),
]),
```

**Optimizations**: O(n) wildcard expansion + fast-check closures for all fields.

#### Nested order lines

1000 orders, each with 5 line items. Nested wildcards (`orders.*.line_items.*.product_id`) are expanded within the per-item closure.

```php
'orders'                            => FluentRule::array()->required()->each([
    'order_number'                  => FluentRule::string()->required()->alphaDash()->min(5),
    'status'                        => FluentRule::string()->required()->in(['pending', 'processing', 'shipped']),
    'line_items'                    => FluentRule::array()->required()->each([
        'product_id'                => FluentRule::numeric()->required()->integer(),
        'quantity'                  => FluentRule::numeric()->required()->integer()->min(1),
        'price'                     => FluentRule::numeric()->required()->min(0.01),
    ]),
]),
```

**Optimizations**: O(n) wildcard expansion + fast-check closures, including the nested level.

#### Conditional import

100 interactive media items with 47 wildcard patterns. Most fields use `exclude_unless` to conditionally apply rules based on the item's `type` field. Only ~4 fields apply per item type out of 47 total.

```php
// String rules work through the same optimization path as FluentRule objects.
'interactions'                                          => 'required|array|min:1',
'interactions.*.type'                                   => ['required', 'string', Rule::in([...])],
'interactions.*.title'                                  => ['nullable', 'string'],
'interactions.*.start_time'                             => ['required', 'numeric', 'min:0'],
'interactions.*.end_time'                               => ['required', 'numeric', 'gte:interactions.*.start_time'],
// Only validated when type = 'chapter':
'interactions.*.should_start_collapsed'                 => [['exclude_unless', 'interactions.*.type', 'chapter'], 'boolean'],
'interactions.*.should_collapse_after_menu_item_click'  => [['exclude_unless', 'interactions.*.type', 'chapter'], 'boolean'],
// Only validated when type = 'chapter' or 'menu':
'interactions.*.position'                               => ['bail', ['exclude_unless', 'interactions.*.type', 'chapter', 'menu'], 'string'],
// ... 40+ more conditional fields across 9 interaction types
```

**Optimizations**: O(n) wildcard expansion + pre-evaluation removes ~95% of rules before validation starts.

#### Event scheduling

100 events with date fields. Both literal date comparisons and wildcard-sibling field references fast-check.

```php
'events'                        => FluentRule::array()->required()->each([
    'name'                      => FluentRule::string()->required()->min(3)->max(255),
    'start_date'                => FluentRule::date()->required()->after('2025-01-01'),        // literal → fast-checked
    'end_date'                  => FluentRule::date()->required()->after('start_date'),          // field ref → fast-checked
    'registration_deadline'     => FluentRule::date()->required()->before('start_date'),         // field ref → fast-checked
]),
```

**Optimizations**: O(n) wildcard expansion + fast-check for every field. Sibling field references (`after:start_date`, `before:start_date`) resolve against the current wildcard item at call time via a second closure variant, so date comparisons don't fall through to Laravel.

#### Article submission

50 articles where most rules are custom `ValidationRule` objects. Custom objects bypass fast-check compilation entirely, so only the wildcard expansion helps.

```php
'articles'                      => FluentRule::array()->required()->each([
    'title'                     => FluentRule::string()->required()->min(3)->max(255),
    'slug'                      => FluentRule::string()->required()->alphaDash()->max(255),
    'content'                   => ['required', 'string', new MinimumWordCount(100)],
    'category'                  => ['required', new ValidCategory()],
    'priority'                  => ['required', new ValidPriority()],
]),
```

**Optimizations**: O(n) wildcard expansion only. Custom Rule objects bypass fast-check compilation, so most fields go through Laravel's validator.

#### Login form

3 fields, no wildcards. All three rules are fully fast-checkable, so `RuleSet::validate()` skips Laravel's validator entirely when values pass.

```php
'email'    => FluentRule::email()->required()->max(255),
'password' => FluentRule::string()->required()->min(8),
'remember' => FluentRule::boolean()->nullable(),
```

**Optimizations**: Fast-check closures for all three fields. Absolute savings are small (~0.1ms → ~0.02ms), but the relative speedup is ~6x since a simple form doesn't give Laravel much wildcard work to amortize against.

### When this won't help

The performance optimizations target wildcard array validation. These cases see little or no speedup:

- **`gt`/`gte`/`lt`/`lte` without a type flag.** Laravel derives comparison type from an accompanying rule (`string`/`array`/`numeric`/`integer`). Without one, these fall through to Laravel. With a type flag, sibling-field comparisons like `numeric|gt:min_price` are fast-checked.
- **`date_format` + date field-ref.** Laravel parses both sides with the declared format and has lenient missing-ref handling our strtotime-based closure can't match. Falls through to Laravel.
- **Multi-param `different:a,b,c`.** Single-field `different:a` is fast-checked; comma-list forms fall through.
- **Custom `ValidationRule` objects and closures.** Opaque to the fast-check compiler. Performance depends on what the rule does.
- **`distinct` rules.** Require comparing values across all items in the array, not per-item.
- **Database rules with closure callbacks** (`exists`/`unique` with `->where(fn ...)`). Can't be batched; each item fires its own query.

If you're not sure whether validation is your bottleneck, profile first. Laravel Telescope shows total request time breakdowns.

> [!TIP]
> **Using Boost?**  
> The `fluent-validation-optimize` skill finds form requests with wildcard rules that are missing `HasFluentRules`, prioritizes them by impact, and adds the trait automatically.

## RuleSet

For validation outside of form requests, you may use `RuleSet` to build, merge, and validate rule sets:

```php
use SanderMuller\FluentValidation\RuleSet;

// From an array
$validated = RuleSet::from([
    'name'  => FluentRule::string('Full Name')->required()->min(2)->max(255),
    'items' => FluentRule::array()->required()->each([
        'name'  => FluentRule::string()->required()->min(2),
        'price' => FluentRule::numeric()->required()->min(0),
    ]),
    'role'        => FluentRule::string()->when($isAdmin, fn ($r) => $r->required()->in(['admin', 'editor'])),
    'permissions' => FluentRule::array()->when($isAdmin, fn ($r) => $r->required()),
])
    ->merge($sharedAddressRules)
    ->validate($request->all());

// Or fluently, with conditional fields and merging
$validated = RuleSet::make()
    ->field('name', FluentRule::string('Full Name')->required())
    ->field('items', FluentRule::array()->required()->each([
        'name'  => FluentRule::string()->required()->min(2),
        'price' => FluentRule::numeric()->required()->min(0),
    ]))
    ->when($isAdmin, fn (RuleSet $set) => $set
        ->field('role', FluentRule::string()->required()->in(['admin', 'editor']))
        ->field('permissions', FluentRule::array()->required())
    )
    ->merge($sharedAddressRules)
    ->validate($request->all());
```

`when()` and `unless()` are available via Laravel's `Conditionable` trait. `merge()` accepts another `RuleSet` or a plain array.

| Method                             | Returns         | Description                                                                     |
|------------------------------------|-----------------|---------------------------------------------------------------------------------|
| `RuleSet::from([...])`             | `RuleSet`       | Create from a rules array                                                       |
| `RuleSet::make()->field(...)`      | `RuleSet`       | Fluent builder                                                                  |
| `->merge($ruleSet)`                | `RuleSet`       | Merge another RuleSet or array into this one                                    |
| `->only(...$fields)`               | `RuleSet`       | Keep only the named fields (variadic strings or single array)                   |
| `->except(...$fields)`             | `RuleSet`       | Drop the named fields (variadic strings or single array)                        |
| `->put($field, $rule)`             | `RuleSet`       | Add or replace a single field's rule                                            |
| `->get($field, $default = null)`   | `mixed`         | Read a single field's rule (uncompiled), or `$default` if absent                |
| `->modify($field, fn ($rule))`     | `RuleSet`       | Read-modify-write a single field; clones before callback; throws on missing key |
| `->all()`                          | `array`         | Collection-style alias of `->toArray()`                                         |
| `[...$ruleSet]`                    | `array`         | Spread support via `IteratorAggregate`; yields `$this->toArray()` shape         |
| `->when($cond, $callback)`         | `RuleSet`       | Conditionally add fields (also: `unless`)                                       |
| `->toArray()`                      | `array`         | Flat rules with `each()` expanded to wildcards                                  |
| `->validate($data)`                | `array`         | Validate with full optimization (see [Performance](#performance))               |
| `->check($data)`                   | `Validated`     | Validate without throwing. See [errors-as-data](#errors-as-data-with-check)     |
| `->prepare($data)`                 | `PreparedRules` | Expand, extract metadata, compile. For custom Validators                        |
| `->expandWildcards($data)`         | `array`         | Pre-expand wildcards without validating                                         |
| `RuleSet::compile($rules)`         | `array`         | Compile fluent rules to native Laravel format                                   |
| `RuleSet::compileToArrays($rules)` | `array`         | Compile to array format for Livewire's `$this->validate()`                      |
| `->failOnUnknownFields()`          | `RuleSet`       | Reject input keys not present in the rule set                                   |
| `->stopOnFirstFailure()`           | `RuleSet`       | Stop validating after the first field fails                                     |
| `->dump()`                         | `array`         | Returns `{rules, messages, attributes}` for debugging                           |
| `->dd()`                           | `never`         | Dumps and terminates                                                            |

### Errors-as-data with `check()`

`validate()` throws `ValidationException` on failure. For import pipelines, batch jobs, and any flow where exceptions are the wrong control structure, use `check()` instead. It returns an immutable `Validated` object:

```php
use SanderMuller\FluentValidation\RuleSet;

foreach ($rows as $row) {
    $result = RuleSet::from($rules)->check($row);

    if ($result->fails()) {
        Log::warning('row rejected', $result->errors()->all());
        continue;
    }

    $safe = $result->safe();        // Illuminate\Support\ValidatedInput, gives you ->only(), ->except(), ->collect()
    $array = $result->validated();  // plain array (throws if the result failed)
    insert_row($safe->all());
}
```

| Method                 | Returns             | Description                                                                          |
|------------------------|---------------------|--------------------------------------------------------------------------------------|
| `->passes()`           | `bool`              | Did validation pass?                                                                 |
| `->fails()`            | `bool`              | Inverse of `passes()`                                                                |
| `->errors()`           | `MessageBag`        | All validation errors (empty bag on success)                                         |
| `->firstError($field)` | `?string`           | First error message for a field, or `null`                                           |
| `->validated()`        | `array`             | Validated data; throws `ValidationException` if it failed                            |
| `->safe()`             | `ValidatedInput`    | Same data as `validated()`, wrapped for `->only()`/`->except()`/`->collect()` access |
| `->validator()`        | `ValidatorContract` | Escape hatch for deep Laravel integration (`->after()`, `->sometimes()`, extensions) |

`check()` runs the same internal engine as `validate()` (fast-check closures, wildcard expansion, batched DB queries). There is no double-parse; the result object just wraps the outcome.

### Rejecting unknown fields

`failOnUnknownFields()` rejects input keys that don't match any rule in the set. If someone sends `role` when you only defined `name` and `email`, validation fails:

```php
$validated = RuleSet::from([
    'name'  => FluentRule::string()->required(),
    'email' => FluentRule::email()->required(),
])->failOnUnknownFields()->validate($request->all());
// Input: ['name' => 'John', 'email' => 'john@example.com', 'role' => 'admin']
// → ValidationException: "The role field is prohibited."
```

Wildcard arrays are checked too. `items.0.hack` fails if only `items.*.name` is defined. You can customize the error message per field:

```php
->validate($data, messages: ['role.prohibited' => 'This field is not allowed.']);
```

> [!TIP]
> For form requests, Laravel 13.4+ has a native `#[FailOnUnknownFields]` attribute that works automatically with `HasFluentRules`.

### Stopping on first failure

`stopOnFirstFailure()` bails after the first field error. If the file upload fails, the 500 `exists` queries for items never run:

```php
$validated = RuleSet::from([
    'file'   => FluentRule::file()->required()->max('10mb'),
    'items'  => FluentRule::array()->required()->each([
        'sku' => FluentRule::string()->required()->exists('products', 'sku'),
    ]),
])->stopOnFirstFailure()->validate($request->all());
```

The same applies inside wildcard arrays. If the first item fails, the rest are skipped.

### Named error bags (`withBag`)

Multiple forms on one page (Fortify's update-password + reset-password, a Livewire multi-card screen, etc.) need separate error bags so their validation messages don't collide. Chain `->withBag($name)` on the rule set; the thrown `ValidationException`'s `errorBag` is set to that name:

```php
RuleSet::from([
    'current_password' => FluentRule::string()->required()->currentPassword(),
    'password'         => FluentRule::string()->required()->min(12),
])
    ->withBag('updatePassword')
    ->validate($input);
```

Mirrors Laravel's `Validator::validateWithBag()` without forcing you back to the `Validator::make(...)` incantation. Only affects the thrown exception's bag; `check()` never throws and is unaffected.

### Using with a raw `Validator` instance

If you still need to touch the `Validator` directly (inspection, non-standard extensions), `prepare()` gives you the compiled pieces:

```php
$prepared = RuleSet::from($rules)->prepare($request->all());

$validator = Validator::make(
    $request->all(),
    $prepared->rules,
    array_merge($prepared->messages, $customMessages),
    $prepared->attributes,
);

$validator->validate();
```

### Using with custom Validators

If your application extends `Illuminate\Validation\Validator` directly (for example, in import jobs), you may extend `FluentValidator` instead:

```php
use SanderMuller\FluentValidation\FluentValidator;

class JsonImportValidator extends FluentValidator
{
    public function __construct(array $data, protected ?User $user = null)
    {
        parent::__construct($data, $this->buildRules());
    }

    private function buildRules(): array
    {
        return [
            '*.type' => FluentRule::string()->required()->in(InteractionType::cases()),
            '*.end_time' => FluentRule::numeric()
                ->requiredUnless('*.type', ...InteractionType::withoutDuration())
                ->greaterThanOrEqualTo('*.start_time'),
        ];
    }
}
```

`FluentValidator` resolves the translator and presence verifier from the container, calls `prepare()` on the rules, and sets implicit attributes. Cross-field wildcard references (`requiredUnless('*.type', ...)`) work automatically.

**Migrating rules in a non-standard method?** If your custom Validator holds its rules in a method that isn't named `rules()` (for example `rulesWithoutPrefix()` for a JSON-import pipeline), mark the method with `#[SanderMuller\FluentValidation\FluentRules]` so the migration Rector rules detect it. The attribute has no runtime effect; see the [`#[FluentRules]` opt-in docs](https://github.com/sandermuller/laravel-fluent-validation-rector#opting-in-fluentrules-attribute) for the full semantics and guard interactions.

---

## Testing

`SanderMuller\FluentValidation\Testing\FluentRulesTester` lets you write direct unit tests against fluent rules, RuleSets, `FluentFormRequest` subclasses, and `FluentValidator` subclasses without standing up the HTTP kernel or Livewire harness. It's the package's stable test surface; everything else under `Testing\` is `@internal`.

In this section: [Targets](#targets) · [Assertions](#assertions) · [FormRequest binding](#formrequest-binding) · [Livewire components](#livewire-components) · [Pest expectations](#pest-expectations-optional)

### Targets

`FluentRulesTester::for($target)` accepts any of these. Chain `->with($data)` before assertions (required; calling assertions sooner raises `LogicException`). `with()` is re-callable so one tester can validate multiple payloads.

```php
use SanderMuller\FluentValidation\Testing\FluentRulesTester;

// 1. Array of rules
FluentRulesTester::for(['email' => FluentRule::email()->required()])
    ->with(['email' => 'a@b.test'])->passes();

// 2. RuleSet instance
FluentRulesTester::for(RuleSet::make()->field('name', FluentRule::string()->required()))
    ->with(['name' => 'Ada'])->passes();

// 3. A single FluentRule (wrapped under the "value" key)
FluentRulesTester::for(FluentRule::string()->required()->min(3))
    ->with(['value' => 'hi'])->fails();

// 4. FormRequest class-string, full pipeline including authorize()
FluentRulesTester::for(UpdateVideoRequest::class)
    ->withRoute(['video' => $video])->actingAs($user)
    ->with(['title' => 'Updated'])->passes();

// 5. FluentValidator class-string; variadic args after for(...) forward to the constructor
FluentRulesTester::for(JsonImportValidator::class, $user, 'sku-')
    ->with($payload)->passes();

// 6. Livewire Component class-string; routes through Livewire::test() so the submit() flow runs
FluentRulesTester::for(AppealPage::class)
    ->set('type', 'refund')->call('submit')->passes();
```

### Assertions

| Method                                                       | Purpose                                                                                                                                                            |
|--------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `->passes()`                                                 | No errors on any field                                                                                                                                             |
| `->fails()`                                                  | At least one error                                                                                                                                                 |
| `->failsWith($field, $rule = null)`                          | Field failed. Optional rule key normalized via `Str::studly` (`required` and `Required` both match)                                                                |
| `->failsWithMessage($field, $translationKey, $replacements)` | Rendered translation matches. Use when porting tests that compare against `__()` output. Pass `:attribute` explicitly when rules use labels                        |
| `->failsOnly($field, $rule = null)`                          | Exactly one field failed; surgical regression detection. Wildcard error keys expand (`items.0.name`); requires exactly one matching key                            |
| `->failsWithAny($prefix)`                                    | Prefix matched exactly or any dotted descendant (`actions.0.payload` → also matches `actions.0.payload.stars`). Not a substring match                              |
| `->doesNotFailOn(...$fields)`                                | Named fields did not fail. Chain after `->fails()`/`->passes()` if overall pass/fail matters; `doesNotFailOn` alone does not assert either direction               |
| `->assertUnauthorized()`                                     | FormRequest `authorize()` returned false. The tester records the `AuthorizationException` rather than rethrowing; surface it via `->fails()->assertUnauthorized()` |

Escape hatches: `->errors()` returns `MessageBag`; `->validated()` returns the validated array (throws `ValidationException` on failure).

### FormRequest binding

```php
FluentRulesTester::for(UpdateVideoRequest::class)
    ->withRoute(['video' => $video])   // $this->route('video') returns $video
    ->actingAs($user)                    // $this->user() / auth()->user()
    ->with(['title' => 'New title'])
    ->passes();
```

Both `withRoute()` and `actingAs($user, $guard = null)` are re-callable (later calls fully replace earlier). `actingAs` mirrors Laravel's test helper and sets the user on the auth guard before `validateResolved()` runs.

### Livewire components

Two shapes. Pick the one matching what you're asserting:

| Shape              | When to use                                                                                                              | Target                                            |
|--------------------|--------------------------------------------------------------------------------------------------------------------------|---------------------------------------------------|
| **Rules-only**     | Assert `rules()` has the right shape against a payload. Component lifecycle irrelevant                                   | `for($component->rules())` or `for($ruleSet)`     |
| **Component-flow** | Drive `wire:model` state, dispatch an action, assert validation fires (or `addError` branches, guards, multi-step flows) | `for(ComponentClass::class)->set(...)->call(...)` |

The class-string target auto-detects Livewire `Component` subclasses and routes through `Livewire::test()`, so the full `submit()` flow runs: guard clauses, `addError()` branches, computed state, rate-limit gates. Both `$this->validate()` failures and manual `addError()` calls surface via `failsWith()`.

```php
FluentRulesTester::for(EditAppealPage::class)
    ->mount(['appeal' => $appeal])                 // for components with mount() params
    ->set('type', 'refund')                         // or set([...]) for multiple keys
    ->set('reason', 'Order arrived damaged.')
    ->call('submit')                                // required before any assertion
    ->passes();
```

`with([...])` expands to `set($key, $value)` per pair on Livewire targets. For multi-action chains where action 1 mutates state that action 2 validates against, queue both with `->call(...)->andCall(...)`; state persists across one `Livewire::test()` instance, then clears on the next chain:

```php
FluentRulesTester::for(ImportInteractionsModal::class)
    ->set('video', $targetVideo)
    ->call('selectVideo', $sourceVideo->uuid)
    ->andCall('import')
    ->failsWith('selectedInteractionIds', 'required');
```

`livewire/livewire` is a soft dev dep; the Livewire branch `class_exists`-guards on `\Livewire\Component`. PHPUnit-only suites see the standard "unsupported target" `LogicException` rather than a hard fatal.

<details>
<summary>Edge cases and advanced patterns</summary>

**Re-callable `with()` on one tester:**

```php
$tester = FluentRulesTester::for($rules);
$tester->with(['qty' => 5])->passes();
$tester->with(['qty' => 0])->fails();
```

**`failsWithMessage()` with labels.** When rules use labels, the validator pre-substitutes `:attribute` into the stored message, so pass it explicitly for the comparison to match:

```php
FluentRulesTester::for([
    'password' => FluentRule::password('Password')->required()->min(8),
])
    ->with(['password' => 'short'])
    ->failsWithMessage('password', 'validation.min.string', [
        'attribute' => 'Password',
        'min' => 8,
    ]);
```

**`withRoute()` default semantics.** Inside the FormRequest:

- `$this->route('video')` returns the bound `$video`
- `$this->route('video', $default)` returns `$video` (default ignored when key present)
- `$this->route('missing', $default)` returns `$default`

**Pre-validate vs post-validate `addError`.** Both surface via `failsWith()`. A rate-limit guard that returns before `validate()` runs, and a quota check that runs after a successful `validate()`, both land in the bag:

```php
// Pre-validate guard. Returns before validate() ever runs.
FluentRulesTester::for(AppealPage::class)
    ->set('rateLimited', true)
    ->call('submit')
    ->failsWith('reason');

// Post-validate addError. validate() passes, then addError.
FluentRulesTester::for(AppealPage::class)
    ->set('type', 'refund')
    ->set('reason', 'Long enough reason.')
    ->set('quotaExceeded', true)
    ->call('submit')
    ->failsWith('type');
```

**State lifecycle on Livewire testers.** After one `->call(...)` chain resolves, the accumulated `with()` / `set()` / `call()` state clears. Each new chain starts from a fresh `Livewire::test()` instance, so reused testers don't leak prior cycles into new ones.

**Choosing Livewire target shape.** Don't reach for the class-string target just because the rules live in a Livewire component. Use it only when the `submit()` flow (guards, `addError`, computed state) matters to the test; otherwise stay on the array or RuleSet target with `->with()`.

</details>

### Pest expectations (optional)

```php
// tests/Pest.php
require_once __DIR__ . '/../vendor/sandermuller/laravel-fluent-validation/src/Testing/PestExpectations.php';
```

```php
expect($rules)->toPassWith(['email' => 'a@b.test']);
expect($rules)->toFailOn(['email' => ''], 'email', 'required');
expect(FluentRule::string()->required())->toBeFluentRuleOf(StringRule::class);
```

The file `class_exists`-guards on `Pest\Expectation`, so requiring it under PHPUnit-only suites is safe.

---

## Rule reference

Available types: `FluentRule::string()`, `integer()`, `numeric()`, `email()`, `password()`, `date()`, `dateTime()`, `boolean()`, `array()`, `file()`, `image()`, `field()`, `anyOf()`. Shortcuts: `url()`, `uuid()`, `ulid()`, `ip()`.

<details>
<summary><a name="rule-string"></a><strong>String</strong>: length, pattern, format, comparison</summary>

```php
// Length
FluentRule::string()->min(2)->max(255)                       // also: between(2, 255), exactly(10)

// Character classes (pick one — each is a complete pattern)
FluentRule::string()->alpha()                                // letters only; also: alphaDash(), alphaNumeric(); each accepts ascii: true
FluentRule::string()->ascii()                                // 7-bit ASCII

// Pattern matching
FluentRule::string()->regex('/^SKU-\d+$/')                   // also: notRegex('/\s/')

// Affixes
FluentRule::string()->startsWith('prefix_')->endsWith('.txt') // also: doesntStartWith(), doesntEndWith()

// Case (mutually exclusive — pick one)
FluentRule::string()->lowercase()                            // or: uppercase()

// Formats (pick the one that matches your field)
FluentRule::string()->url()                                  // also: activeUrl(), uuid(), ulid(), json(), ip(),
                                                             //       ipv4(), ipv6(), macAddress(), timezone(), hexColor()
FluentRule::string()->encoding('UTF-8')

// Cross-field & confirmation
FluentRule::string()->confirmed()                            // pairs with `<field>_confirmation`
FluentRule::string()->currentPassword()                      // matches the authed user's password; accepts a guard
FluentRule::string()->same('confirm_field')                  // also: different('other_field')

// Wildcards & uniqueness in arrays
FluentRule::string()->inArray('values.*')                    // also: inArrayKeys('values.*')
FluentRule::string()->distinct()                             // for `'tags.*'` rules; also: distinct('strict'), distinct('ignore_case')
```

> [!TIP]
> Top-level shortcuts for the most common single-rule strings: `FluentRule::url()`, `uuid()`, `ulid()`, `ip()`, `ipv4()`, `ipv6()`, `macAddress()`, `json()`, `timezone()`, `hexColor()`, `activeUrl()`, `regex($pattern)`. All accept an optional `$label`. Each is `FluentRule::string()->X()`; use the shortcut when the string type is the only constraint beyond the format.

</details>

<details>
<summary><a name="rule-email"></a><strong>Email</strong>: app defaults, modes, uniqueness</summary>

`FluentRule::email()` uses your app's `Email::default()` configuration when set. Pass `defaults: false` for basic validation:

```php
FluentRule::email()->required()                     // uses Email::default() if configured
FluentRule::email(defaults: false)->required()       // basic 'email' validation
FluentRule::email()->rfcCompliant()->strict()         // explicit modes override defaults
FluentRule::email()->validateMxRecord()->preventSpoofing()
FluentRule::email()->required()->unique('users', 'email')
```

> [!TIP]
> `FluentRule::string()->email()` is also available if you prefer keeping email as a string modifier.

</details>

<details>
<summary><a name="rule-password"></a><strong>Password</strong>: strength, confirmation, defaults</summary>

```php
FluentRule::password(min: 12)->letters()->mixedCase()->numbers()->symbols()->uncompromised()
FluentRule::password()->min(10)->max(64)                     // length bounds
FluentRule::password()->uncompromised(threshold: 3)          // allow up to N HIBP hits
FluentRule::password()->confirmed()                          // requires `password_confirmation` field
FluentRule::password()->confirmed('Passwords do not match.') // custom mismatch message
```

`FluentRule::password()` uses your app's `Password::default()` configuration (set via `Password::defaults()` in AppServiceProvider). Pass `defaults: false` for a plain `Password::min(8)`: `FluentRule::password(defaults: false)`.

</details>

<details>
<summary><a name="rule-numeric"></a><strong>Numeric / Integer</strong>: type, size, digits, comparison</summary>

```php
// Type
FluentRule::integer()->required()->min(0)              // shorthand for numeric()->integer()
FluentRule::integer(strict: true)->required()          // reject numeric strings ("42"); requires Laravel 12.23+
FluentRule::numeric()->required()                      // any numeric value (int or float)

// Decimal precision
FluentRule::numeric()->decimal(2)                      // exactly 2 decimal places (e.g. money)
FluentRule::numeric()->decimal(0, 2)                   // up to 2 decimal places

// Size & multiples
FluentRule::numeric()->min(0)->max(100)                // also: between(0, 100), exactly(42)
FluentRule::numeric()->multipleOf(5)

// Digit-count constraints (pick one)
FluentRule::integer()->digits(4)                       // exactly 4 digits, e.g. PIN, ZIP
FluentRule::integer()->digitsBetween(4, 6)             // also: minDigits(3), maxDigits(8)

// Cross-field comparisons
FluentRule::numeric()->greaterThan('min_price')->lessThan('max_price')   // also: greaterThanOrEqualTo(), lessThanOrEqualTo()

// Sign helpers
FluentRule::numeric()->positive()                      // gt:0, same as greaterThan(0); also: negative() for lt:0
FluentRule::numeric()->nonNegative()                   // gte:0 (allows zero); also: nonPositive() for lte:0
```

</details>

<details>
<summary><a name="rule-date"></a><strong>Date</strong>: boundaries, shortcuts, format</summary>

All comparison methods accept `DateTimeInterface|string`:

```php
// Boundary comparisons
FluentRule::date()->after('today')->before('2025-12-31')         // also: afterOrEqual(), beforeOrEqual()
FluentRule::date()->between('2025-01-01', '2025-12-31')          // also: betweenOrEqual()

// Today-relative shortcuts (mutually exclusive — pick one)
FluentRule::date()->afterToday()                                  // also: beforeToday(), todayOrAfter(), todayOrBefore()
FluentRule::date()->future()                                      // also: past(), nowOrFuture(), nowOrPast()

// Format & equality
FluentRule::date()->format('Y-m-d')->dateEquals('2025-06-15')
FluentRule::dateTime()->afterToday()                              // shortcut for date()->format('Y-m-d H:i:s')

// Cross-field
FluentRule::date()->same('start_date')                            // also: different('other_field')
```

</details>

<details>
<summary><a name="rule-other-types"></a><strong>Boolean, Array, File, Image, Field, AnyOf</strong></summary>

**Boolean.** `boolean()` accepts `true`, `false`, `1`, `0`, `'1'`, `'0'`. Use `accepted()` for `'yes'`, `'on'`, `'1'`, `true` and `declined()` for `'no'`, `'off'`, `'0'`, `false`:

```php
FluentRule::boolean()->required()                       // strict boolean
FluentRule::boolean()->acceptedIf('role', 'admin')      // also: declinedIf('type', 'free')
```

**Accepted / Declined.** Standalone factories for the permissive `accepted`/`declined` families without a strict `boolean` base. Useful for terms-of-service / opt-in checkboxes where form posts deliver `'yes'` or `'on'` values that Laravel's `boolean` rule rejects:

```php
FluentRule::accepted()                          // true | 1 | '1' | 'yes' | 'on' | 'true'
FluentRule::accepted()->acceptedIf('role', 'admin')
FluentRule::declined()                          // false | 0 | '0' | 'no' | 'off' | 'false'
FluentRule::declined()->declinedIf('under_18', 'yes')
```

> **Footgun:** `FluentRule::boolean()->accepted()` compiles to `boolean|accepted`; `boolean` rejects `'yes'` / `'on'` which `accepted` would otherwise permit. Use `FluentRule::accepted()` (or `::declined()`) when the input shape is HTML-form-ish.

**Array.** Size, structure, allowed keys:

```php
// Size
FluentRule::array()->min(1)->max(10)                  // also: between(1, 5), exactly(3)

// Shape
FluentRule::list()                                    // shortcut for array()->list(), sequentially-indexed
FluentRule::array(['name', 'email'])                  // restrict allowed keys
FluentRule::array(MyEnum::cases())                    // BackedEnum keys
FluentRule::array()->requiredArrayKeys('name', 'email')

// Element membership
FluentRule::array()->contains('required_value')       // also: doesntContain('forbidden_value')
FluentRule::array()->distinct()                       // unique elements; also: distinct('strict'), distinct('ignore_case')
```

**File.** Size methods accept integers (kilobytes) or human-readable strings:

```php
// Size
FluentRule::file()->max('5mb')                        // also: min('100kb'), between('1mb', '10mb'), exactly('2mb')

// Type (pick the check that matches your trust model)
FluentRule::file()->extensions('pdf', 'docx')         // by filename extension only
FluentRule::file()->mimes('jpg', 'png')               // by mime guessed via extension
FluentRule::file()->mimetypes('application/pdf')      // by actual mime sniffed from contents
```

**Image.** Dimension constraints, inherits all file methods:

```php
// Size & format
FluentRule::image()->max('5mb')->allowSvg()

// Dimension bounds
FluentRule::image()->minWidth(100)->maxWidth(1920)->minHeight(100)->maxHeight(1080)

// Exact dimensions OR aspect ratio (mutually exclusive — pick one)
FluentRule::image()->width(800)->height(600)
FluentRule::image()->ratio(16 / 9)                    // also: ratio('16/9'), ratio(1) for square
```

**Field (untyped).** Modifiers without a type constraint. Use `field()` when the input has no inherent type (e.g. a value that could be a string OR integer depending on context), or when your only validation is modifiers (`required`, `nullable`, `in`, conditional presence). It's also the escape hatch Rector reaches for when it can't narrow the type from pipe/array rules. If you see `FluentRule::field()` in migrated code, consider whether a typed factory (`string()`, `integer()`) better expresses intent.

```php
FluentRule::field()->present()
FluentRule::field()->requiredIf('type', 'special')
FluentRule::field('Answer')->nullable()->in(['yes', 'no'])
```

**AnyOf.** Value passes if it matches any rule set (Laravel 13+):

```php
FluentRule::anyOf([
    FluentRule::string()->required()->min(2),
    FluentRule::numeric()->required()->integer(),
])
```

</details>

<details>
<summary><a name="embedded-rules"></a><strong>Embedded rules</strong>: in, unique, exists, enum</summary>

String, numeric, and date rules support `in`, `unique`, `exists`, and `enum`. `in()` and `notIn()` accept arrays or a `BackedEnum` class:

```php
FluentRule::string()->in(['draft', 'published'])
FluentRule::string()->in(StatusEnum::class)          // all enum values
FluentRule::string()->notIn(DeprecatedStatus::class)
FluentRule::string()->enum(StatusEnum::class)
FluentRule::string()->enum(StatusEnum::class, fn ($r) => $r->only(StatusEnum::Active))
FluentRule::enum(StatusEnum::class)   // top-level shortcut, returns an untyped FieldRule
FluentRule::string()->unique('users', 'email')
FluentRule::string()->unique('users', 'email', fn ($r) => $r->ignore($this->user()->id))
FluentRule::string()->exists('roles', 'name')
FluentRule::string()->exists('subjects', 'id', fn ($r) => $r->where('active', true))
```

`unique()`, `exists()`, and `enum()` accept an optional callback as the last argument. The callback receives the underlying Laravel rule object, so you can chain `->where()`, `->ignore()`, `->only()`, etc.

</details>

<details>
<summary><a name="field-modifiers"></a><strong>Field modifiers</strong>: presence, prohibition, exclusion, messages</summary>

Shared by all rule types:

```php
// Presence
->required()  ->nullable()  ->sometimes()  ->filled()  ->present()  ->missing()

// Conditional presence: accepts field references or Closure|bool.
// Value args on *If / *Unless accept BackedEnum, so ->requiredIf('role', Role::Admin) works; no ->value needed.
->requiredIf('role', 'admin')  ->requiredUnless('type', 'guest')  ->requiredIf(fn () => $cond)
->requiredWith('field')  ->requiredWithAll('a', 'b')  ->requiredWithout('field')  ->requiredWithoutAll('a', 'b')
->requiredIfAccepted('terms')  ->requiredIfDeclined('terms')
->presentIf('type', 'admin')  ->presentUnless('type', 'guest')  ->presentWith('field')  ->presentWithAll('a', 'b')

// Prohibition & exclusion
->prohibited()  ->prohibitedIf('field', 'val')  ->prohibitedUnless('field', 'val')  ->prohibits('other')
->prohibitedIfAccepted('terms')  ->prohibitedIfDeclined('terms')
->exclude()  ->excludeIf('field', 'val')  ->excludeUnless('field', 'val')  ->excludeWith('f')  ->excludeWithout('f')

// Messages
->label('Name') // sets :attribute for this field's messages
->required(message: 'Please enter your :attribute') // custom message for this rule
->requiredIf('type', 'admin', message: 'Admins must provide :attribute') // custom message for this conditional rule

// Debugging
->toArray()  ->dump()  ->dd()

// Other
->bail()  ->rule($stringOrObjectOrArray)  ->whenInput($condition, $then, $else?)
```

> [!IMPORTANT]
> To exclude a field from `validated()` output, place `exclude` alongside the fluent rule: `'field' => ['exclude', FluentRule::string()]`. Using `->exclude()` on the FluentRule itself only works within the rule's self-validation scope.

</details>

<details>
<summary><a name="conditional-rules"></a><strong>Conditional rules, escape hatch, macros</strong></summary>

**Conditional rules.** All rule types use Laravel's `Conditionable` trait. A single form request can handle both create and update:

```php
// Required on create, optional on update
FluentRule::string()->when($this->isMethod('POST'), fn ($r) => $r->required(), fn ($r) => $r->sometimes())

// Admin-only constraint
FluentRule::string()->required()->when($isAdmin, fn ($r) => $r->min(12))->max(255)
```

For conditions that depend on the input data at validation time, use `whenInput()`:

```php
FluentRule::string()->whenInput(
    fn ($input) => $input->role === 'admin',
    fn ($r) => $r->required()->min(12),
    fn ($r) => $r->sometimes()->max(100),
)
```

The closure receives the full input as a `Fluent` object and runs during validation, not at build time. You can also pass string rules: `->whenInput($condition, 'required|min:12')`.

**Escape hatch.** Add any Laravel validation rule with `rule()`:

```php
FluentRule::string()->rule('email:rfc,dns')
FluentRule::string()->rule(new MyCustomRule())
FluentRule::file()->rule(['mimetypes', ...$acceptedTypes])
```

**Macros.** Define reusable rule chains in a service provider:

```php
// Rule-level macros: add methods to existing rule types
NumericRule::macro('percentage', fn () => $this->integer()->min(0)->max(100));
StringRule::macro('slug', fn () => $this->alpha(true)->lowercase());

FluentRule::numeric()->percentage()
FluentRule::string()->slug()

// Factory-level macros: add new FluentRule::xyz() entry points
FluentRule::macro('phone', fn (?string $label = null) => FluentRule::string($label)->rule('phone'));

FluentRule::phone('Phone Number')
```

</details>

## Migrating existing validation with Rector

The companion package [`sandermuller/laravel-fluent-validation-rector`](https://github.com/sandermuller/laravel-fluent-validation-rector) automates the bulk of a migration from native Laravel validation to FluentRule. In real-world testing against a production Laravel codebase, the rules converted **448 files across 3469 tests with zero regressions**.

```bash
composer require --dev sandermuller/laravel-fluent-validation-rector
```

The Rector package covers the full migration surface: pipe-delimited strings, array-based rules, `Rule::` objects, `Password::min()` chains, conditional tuples, closures, custom rule objects, Livewire `#[Rule]` / `#[Validate]` attributes, wildcard grouping, trait insertion, and post-migration chain cleanup.

See the [Rector package README](https://github.com/sandermuller/laravel-fluent-validation-rector) for `rector.php` setup, the set catalog (`ALL`, `CONVERT`, `GROUP`, `TRAITS`, `SIMPLIFY`, `POLISH`), per-rector configuration constants, the `#[FluentRules]` per-method opt-in, the post-migration verification workflow, and skip-log diagnostics.

See [Common migration patterns](resources/boost/skills/fluent-validation/references/migration-patterns.md) for a detailed reference covering rule-type selection, `Rule::` method conversion, BackedEnum handling, and advanced patterns when Rector leaves a file alone.

The Rector rules aren't just for migration. Run `ALL` (or `SIMPLIFY` on its own) in CI as an ongoing code-quality gate. New validation code (new FormRequests, new Livewire components, new Filament pages) goes through the same converters, grouping, and trait insertion as the initial migration did, so patterns stay consistent as the codebase grows.

> [!TIP]
> **Prefer explicit parent rules for new code.** Pair `'items' => FluentRule::array()->required()` with `'items.*.name' => FluentRule::string()->required()` so nullability/required/size live on the parent. Rector's `GroupWildcardRulesToEachRector` synthesizes `FluentRule::array()->nullable()` when the parent is missing (preserving flat-rule null-parity), so existing codebases migrate fine either way.

## Static analysis with PHPStan

The companion package [`sandermuller/laravel-fluent-validation-phpstan`](https://github.com/sandermuller/laravel-fluent-validation-phpstan) ships PHPStan rules that flag misuse of this library in consumer projects. The flagship rule catches unbounded `FluentRule::array()->each(...)` / `FluentRule::list()->each(...)` chains, the classic N+1 / DoS footgun on per-item `exists()` or closure rules.

```bash
composer require --dev sandermuller/laravel-fluent-validation-phpstan
```

See the [phpstan-package README](https://github.com/sandermuller/laravel-fluent-validation-phpstan#rules) for the rule catalog, configuration (`namespaces`, `excludeNamespaces`) and escape hatches.

## Troubleshooting

**`validated()` is missing nested keys (children, each)**
Add `use HasFluentRules` to your form request. Without the trait, FluentRule objects self-validate in isolation and nested keys don't appear in `validated()` output.

**Labels not working ("The name field" instead of "The Full Name field")**
Add `use HasFluentRules`. The trait extracts labels from rule objects and passes them to the validator. Without it, labels are only used inside the rule's self-validation.

**Cross-field wildcard references don't work (`requiredUnless('items.*.type', ...)`)**
These require `HasFluentRules` or `FluentValidator` to resolve wildcard paths. Standalone FluentRule objects self-validate in isolation.

**Child form request loses or corrupts parent rules**
`array_merge_recursive` flattens FluentRule objects into arrays. See [Extending parent rules](#extending-parent-rules-in-child-form-requests) for the supported merge patterns (spread, clone, `modifyEach`, `modifyChildren`).

**Method not found on a rule type**
Use `->rule('method_name')` as an escape hatch for any Laravel rule not yet available as a fluent method. Accepts strings, objects, and `['rule', ...$params]` tuples.
If you think it should be a native method, [open an issue](https://github.com/SanderMuller/laravel-fluent-validation/issues) and we'll add it.

**`UnknownFluentRuleMethod: FluentRule::field() has no method ...()`**
`FluentRule::field()` is the untyped builder; type-specific rules (`min`, `max`, `regex`, `email`, `digits`, `mimes`, `before`, `after`, `contains`) live on the typed builders. The exception message names the builders that expose the method. Pick the one matching your field's type:

```php
FluentRule::numeric()->required()->min(5);   // numeric value
FluentRule::string()->required()->min(5);    // string length
FluentRule::array()->required()->min(5);     // element count
FluentRule::file()->required()->min('2mb');  // file size
```

The smell-form `FluentRule::field()->rule('min:1')` (or any `->rule('some_type_rule:...')` on `field()`) works at runtime but is non-idiomatic. Pick the typed builder. The [Rector companion](https://github.com/sandermuller/laravel-fluent-validation-rector) auto-simplifies it. For test-time coverage, see `SanderMuller\FluentValidation\Testing\Arch\BansFieldRuleTypeMethods` (requires `nikic/php-parser` dev dep).

**`HasFluentValidation` conflicts with Filament's `InteractsWithForms` / `InteractsWithSchemas`**
Use `HasFluentValidationForFilament` instead. See [Livewire → Filament components](#livewire). The Rector companion picks it plus the `insteadof` block automatically.

**Migration issues (Rector companion)**
Rector-specific issues are tracked in the [laravel-fluent-validation-rector README](https://github.com/sandermuller/laravel-fluent-validation-rector#troubleshooting). Update the Rector companion to the latest version first; most are fixed upstream.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Security vulnerabilities

Please review [our security policy](SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Sander Muller](https://github.com/sandermuller)
- [All Contributors](../../contributors)

## License

MIT License. Please see [License File](LICENSE.md) for more information.
