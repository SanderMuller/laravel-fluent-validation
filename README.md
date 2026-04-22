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

> **Migrating an existing codebase?** Jump straight to [Migrating existing validation with Rector](#migrating-existing-validation-with-rector), a companion Rector package automates the bulk of the rewrite. Real-world testing: 448 files across 3469 tests with zero regressions.

## Contents

**Getting started**
- [Installation](#installation)
- [Quick start](#quick-start) — Validator::make, Form Requests, migrating existing rules
- [Error messages](#error-messages) — labels, per-rule messages
- [Array validation](#array-validation-with-each-and-children) — each, children, nesting

**Migration**
- [Migrating existing validation with Rector](#migrating-existing-validation-with-rector) — automated rewrite via [companion Rector package](https://github.com/sandermuller/laravel-fluent-validation-rector)
- [Common migration patterns](resources/boost/skills/fluent-validation/references/migration-patterns.md) — detailed reference for manual conversions (external doc)

**Deep dive**
- [Livewire](#livewire) — HasFluentValidation trait, Filament workaround
- [Why this package?](#why-this-package) — DX, type safety, structure, performance
- [Performance](#performance) — O(n) wildcards, pre-evaluation, fast-check closures, batched DB, benchmarks
- [RuleSet](#ruleset) — builder, conditional fields, custom Validators
- [Testing fluent rules](#testing-fluent-rules) — `FluentRulesTester`, Pest expectations
- [Rule reference](#rule-reference) — all types, modifiers, conditionals, macros
- [Troubleshooting](#troubleshooting) — common issues and solutions

## Installation

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

## Quick start

You may use FluentRule anywhere you'd normally write validation rules:

```php
use SanderMuller\FluentValidation\FluentRule;

$validated = $request->validate([
    'name'  => FluentRule::string()->required()->min(2)->max(255),
    'email' => FluentRule::email()->required(),
    'age'   => FluentRule::numeric()->nullable()->integer()->min(0),
]);
```

> [!NOTE]
> `FluentRule` is a static factory, not a base class. `FluentRule::string()` returns a `StringRule`, `FluentRule::email()` returns an `EmailRule`, etc. For PHPDoc type hints, reference `FluentRuleContract` (see below) or Laravel's `ValidationRule`, not `FluentRule` itself.

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

FluentRule objects implement Laravel's `ValidationRule` interface, so they also work in `Validator::make()`, `Rule::forEach()`, and `Rule::when()`. For inline validation outside form requests, prefer [`RuleSet::validate()`](#ruleset) over `Validator::make()`; it gives you the same optimizations as `HasFluentRules`. Use [`->when()`](#conditional-rules) to handle create and update in a single form request.


> [!IMPORTANT]
> FluentRule works anywhere Laravel accepts rules — including `each()` and `children()`, which self-validate through an inner validator. For **labels surfaced as outer attributes, wildcard/fast-check optimization, batched exists/unique queries, and precognitive requests**, use one of:
>
> | Context                | What to use                                                   |
> |------------------------|---------------------------------------------------------------|
> | FormRequest            | `use HasFluentRules` trait ([details](#in-a-form-request))    |
> | Livewire component     | `use HasFluentValidation` trait ([details](#livewire))        |
> | Inline / anywhere else | `RuleSet::from([...])->validate($data)` ([details](#ruleset)) |

#### Typing your `rules()` return

Every shipped rule class implements `SanderMuller\FluentValidation\Contracts\FluentRuleContract` — a single stable type alias covering the full shared modifier and conditional surface. Use it instead of enumerating concrete types:

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

`FluentRuleContract extends Illuminate\Contracts\Validation\ValidationRule`, so downstream code already typed against Laravel's native contract keeps working. Type-specific methods (e.g. `StringRule::email()`, `NumericRule::integer()`, `ImageRule::dimensions()`) stay on their concrete classes — narrow to the concrete type when you need to call them.

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

**Step 2:** Convert fields one at a time. Start with the ones that benefit most from autocompletion (complex conditionals, date comparisons, nested arrays). Common conversions:

| Before                                              | After                                                                     |
|-----------------------------------------------------|---------------------------------------------------------------------------|
| `'items.*.name' => 'required\|string'`              | `FluentRule::array()->each(['name' => FluentRule::string()->required()])` |
| `'search' => 'array'` and `'search.value' => '...'` | `FluentRule::array()->children(['value' => ...])`                         |
| `Rule::in([...])`                                   | `->in([...])` or `->in(MyEnum::class)`                                    |
| `Rule::unique('users')`                             | `->unique('users')`                                                       |
| `Rule::forEach(fn () => ...)`                       | `FluentRule::array()->each(...)`                                          |

All conditional methods (`requiredIf`, `excludeUnless`, etc.) accept `Closure|bool` in addition to field references. `each()` and `children()` nest naturally. Flat dot-notation keys like `columns.*.data.sort` become nested `each([...children([...])])` trees that mirror the data shape.

> [!TIP]
> **Using Boost?** If you have [Laravel Boost](https://github.com/laravel/boost) installed, ask your AI assistant to run the `optimize-validation` skill. It scans your codebase for convertible rules, prioritizes by impact, and applies changes file by file.

**Step 3:** For rules without a direct fluent method, use the `rule()` escape hatch:

```php
FluentRule::string()->rule('email:rfc,dns')           // string rule
FluentRule::string()->rule(new MyCustomRule())         // object rule
FluentRule::file()->rule(['mimetypes', ...$types])     // array tuple
```

### Extending parent rules in child form requests

To add fields in a child, use the spread operator: `return [...parent::rules(), 'extra' => FluentRule::string()->required()]`. If you need to modify a parent's rule, clone it first since `->rule()` mutates the object: `$rules['type'] = (clone $rules['type'])->rule(new ExtraRule())`.

`rules()` may also return a `RuleSet` directly — `HasFluentRules` (and `HasFluentValidation` for Livewire) auto-unwrap it via `->toArray()` before passing to the validator. This lets you chain `->only/->except/->merge/->put/->get` and return without a terminal `->toArray()` call:

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

## Error messages

### Labels

You may pass a label as the first argument to any factory method. It replaces `:attribute` in error messages for that field:

```php
return [
    'name'  => FluentRule::string('Full Name')->required()->min(2)->max(255),
    'email' => FluentRule::email('Email Address')->required(),
    'age'   => FluentRule::integer('Your Age')->nullable()->min(0),
    'items' => FluentRule::array(label: 'Import Items')->required()->min(1),
];
// "The Full Name field is required."
// "The Email Address field must be a valid email address."
// "The Import Items field must have at least 1 items."
```

Labels are extracted by `HasFluentRules`, `HasFluentValidation`, and `RuleSet::validate()`. They also work inside `each()`, so child fields get clean names:

```php
'items' => FluentRule::array()->required()->each([
    'name'  => FluentRule::string('Item Name')->required(),
    'email' => FluentRule::email('Email')->required(),
]),
// "The Item Name field is required." (instead of "The items.0.name field is required.")
```

You can also set a label after construction with `->label('Name')`.

> [!NOTE]
> Labels require trait-based or RuleSet-based validation to be extracted. When using `$request->validate()` or bare `Validator::make()`, FluentRule objects self-validate in isolation and labels are not passed to the outer validator. Use [`HasFluentRules`](#in-a-form-request) in form requests, [`RuleSet::validate()`](#rulesetvalidate) for inline validation, [`HasFluentValidation`](#livewire) for Livewire components, or [`FluentValidator`](#using-with-custom-validators) for custom validator classes.

### Per-rule messages

The recommended form is the inline `message:` named argument, which attaches the message directly to the rule it applies to:

```php
FluentRule::string('Full Name')
    ->required(message: 'We need your name!')
    ->min(2, message: 'At least :min characters.')
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
// Variadic method — message: cannot follow a variadic param. Use ->message() (shorter) or messageFor().
FluentRule::string()->requiredWith('email', 'phone')->message('Required when email or phone is set.')

// Composite method (->digits adds `integer` then `digits:N`). message: binds to the LAST sub-rule.
FluentRule::numeric()->digits(5, message: 'Must be 5 digits.')
    ->messageFor('integer', 'Must be a whole number.')

// Custom rule object — message: on ->rule() binds to the object's class-basename key.
FluentRule::string()->rule(new MyRule(), message: 'Custom failure.')
```

For a field-level fallback that applies to any failure, use `->fieldMessage()`:

```php
FluentRule::string()->required()->min(2)->fieldMessage('Something is wrong with this field.')
```

> [!NOTE]
> `FluentRule::date()` / `FluentRule::dateTime()` factories do **not** accept `message:` — the error-lookup key is resolved at build time (`'date'` vs `'date_format:Y-m-d'`) and cannot be seeded deterministically. Attach messages via a specific method: `FluentRule::date()->before('2026-12-31', message: 'Too late.')`.

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
> The `optimize-validation` skill automatically detects flat dot-notation keys that can be grouped with `each()` and `children()`, and converts them for you.

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

// each() — the trait expands this for Livewire automatically
'items' => FluentRule::array()->required()->each([
    'name' => FluentRule::string()->required(),
]),
```

**Filament components:** `HasFluentValidation` conflicts with Filament's `InteractsWithForms` (v3/v4) / `InteractsWithSchemas` (v5) because both define `validate()`, `validateOnly()`, `getRules()`, and `getValidationAttributes()`. Use `HasFluentValidationForFilament` instead. It provides the same FluentRule compilation with Filament's error event dispatching preserved:

> [!TIP]
> Running the [Rector companion](https://github.com/sandermuller/laravel-fluent-validation-rector) in CI handles the Filament trait selection for you (every run, not just the first). It picks `HasFluentValidationForFilament` plus the required 4-method `insteadof` block whenever `InteractsWithForms` / `InteractsWithSchemas` is used directly on a class. New components written after the initial migration get the same treatment. No manual setup of the conflict resolution below.

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

    public function rules(): array
    {
        return [
            'name' => FluentRule::string('Name')->required()->max(255),
        ];
    }

    public function save(): void
    {
        $validated = $this->validate(); // standard method — compiles FluentRules automatically
        // ...
    }
}
```

Standard `validate()` and `validateOnly()` work as expected. FluentRule compilation, label/message extraction, `each()`/`children()` expansion, Filament's `form-validation-error` event dispatch, and Filament form-schema rule aggregation are all handled automatically.

### Livewire + Laravel Boost

If you use [Laravel Boost](https://github.com/laravel/boost), the `fluent-validation-livewire` skill covers trait usage, Filament workarounds, and common mistakes automatically.

## Why this package?

If you've ever had to look up whether it's `required_with` or `required_with_all`, or whether the method is `digits_between` or `digitsBetween`, you know the frustration. The IDE answers that for you now. Each type has its own class, so `FluentRule::string()` won't even offer `digits()`.

`each()` and `children()` group parent and child rules instead of scattering 20 flat dot-notation keys. Labels and messages attach to the rule itself, so there's no separate `messages()` array to drift out of sync.

Performance is the other half. Laravel's wildcard validation is O(n²) on large arrays; `HasFluentRules` makes it [up to 160x faster](#benchmarks) for nested wildcards, 62x faster for conditional-heavy payloads.

<details>
<summary><a name="compared-to-rule"></a>Compared to Laravel's <code>Rule</code> class</summary>

`FluentRule` is intentionally named differently from `Illuminate\Validation\Rule` so both can be used without aliasing. You generally don't need Laravel's `Rule` at all:

| Laravel's `Rule`                      | FluentRule equivalent                                       |
|---------------------------------------|-------------------------------------------------------------|
| `Rule::forEach(fn () => ...)`         | `FluentRule::array()->each(...)`                            |
| `Rule::when($cond, $rules, $default)` | `->when($cond, fn ($r) => ..., fn ($r) => ...)`             |
| `Rule::unique('users')->where(...)`   | `->unique('users', 'col', fn($r) => $r->where(...))`        |
| `Rule::exists('roles')->where(...)`   | `->exists('roles', 'col', fn($r) => $r->where(...))`        |
| `Rule::in([...])`                     | `FluentRule::string()->in([...])`                           |
| `Rule::enum(Status::class)`           | `FluentRule::enum(Status::class)` / `::string()->enum(...)` |
| `Rule::anyOf([...])`                  | `FluentRule::anyOf([...])`                                  |
| No equivalent                         | `->each([...])` co-locate wildcard child rules              |
| No equivalent                         | `->children([...])` co-locate fixed-key child rules         |
| No equivalent                         | `->label('Name')` / `->message('...')` inline messages      |
| No equivalent                         | `->whenInput(fn ($input) => ...)` data-dependent conditions |

</details>

## Performance

When you use one of the optimized entry points (`HasFluentRules` on a FormRequest, `HasFluentValidation` on a Livewire component, `FluentValidator`, or `RuleSet::validate()`), FluentRule objects compile down to native Laravel format before validation runs and pick up four extra optimizations:

- [**O(n) wildcard expansion**](#on-wildcard-expansion) — replaces Laravel's O(n²) `Arr::dot()` + regex expansion with a single tree walk
- [**Pre-evaluation of conditional rules**](#pre-evaluation-of-conditional-rules) — resolves `exclude_unless`/`exclude_if` before validation and removes excluded attributes from the rule set
- [**Fast-check closures**](#fast-check-closures) — compiles 30+ common rules into PHP closures that skip Laravel's validator entirely for passing values
- [**Batched database validation**](#batched-database-validation) — turns N `exists`/`unique` queries into a single `whereIn`

> [!NOTE]
> When used directly with `$request->validate()` or bare `Validator::make()`, FluentRule objects self-validate: each rule builds an inner `Validator::make(...)` for itself, bypassing the compile-to-native path and the four optimizations above. Correct output, but slower than the optimized entry points.

### Benchmarks

| Scenario                                                                     | Optimizations                          | Native Laravel | Optimized   | Speedup |
|------------------------------------------------------------------------------|----------------------------------------|----------------|-------------|---------|
| [Product import](#product-import) — 500 items, simple rules                  | Wildcard, fast-check                   | ~163ms         | **~3ms**    | ~62x    |
| [Nested order lines](#nested-order-lines) — 1000 orders × 5 line items       | Wildcard, fast-check (nested)          | ~2,491ms       | **~15ms**   | ~163x   |
| [Conditional import](#conditional-import) — 100 items, 47 conditional fields | Wildcard, pre-evaluation               | ~2,928ms       | **~47ms**   | ~62x    |
| [Event scheduling](#event-scheduling) — 100 items, field-ref dates           | Wildcard, fast-check (field-ref dates) | ~19ms          | **~0.7ms**  | ~28x    |
| [Article submission](#article-submission) — 50 items, custom Rule objects    | Wildcard only                          | ~8ms           | **~2ms**    | ~3x     |
| [Login form](#login-form) — 3 fields, no wildcards                           | Fast-check (flat)                      | ~0.1ms         | **~0.02ms** | ~7x     |

All numbers are from `php benchmark.php` (macOS, PHP 8.4, OPcache); CI runs produce the same scenarios on Ubuntu.

### O(n) wildcard expansion

Laravel's `explodeWildcardRules()` flattens data with `Arr::dot()` and matches regex patterns against every key. For each wildcard rule, it scans every key in the flattened array, making the expansion O(n²). The package replaces this with a tree traversal that walks the data once and emits concrete paths as it descends.

### Pre-evaluation of conditional rules

Rules like `exclude_unless` and `exclude_if` are evaluated before the validator starts. Excluded attributes are removed from the rule set entirely, so the validator only sees the rules that actually apply. For a payload with 100 items and 47 conditional fields, this reduces the rule set from ~4,700 to ~200.

### Fast-check closures

The package compiles 30+ common rules into PHP closures that bypass Laravel's validator when values pass. Covered rules include the usual type checks (`string`, `numeric`, `email`, `date`, `array`, `boolean`, `in`, `regex`), presence gates (`required`, `prohibited`), date comparisons with literal dates, date/size/equality comparisons against wildcard siblings (`after:start_date`, `gte:min_price`, `same:password`, `confirmed`), and the presence-conditional family (`required_with`, `required_without`, `required_with_all`, `required_without_all`) — including dotted dependent paths like `required_without:profile.birthdate`, which the per-item closure can't handle directly but which `RuleSet::reduceRulesForItem` pre-evaluates against the item before dispatch.

What the closure does is simpler than what Laravel does. A `string|max:255` rule becomes `is_string($v) && strlen($v) <= 255`. No rule parsing, no method dispatch, no `BigNumber` size comparison. Values that pass never touch the validator. Values that fail fall through to Laravel so the error message stays identical, with no custom-formatting layer to maintain.

Rules that can't be fast-checked (custom Rule objects, closures, `distinct`, `exists`/`unique` with closure callbacks) go through Laravel as normal.

Fast-checks apply to both wildcard rules (`items.*.name`) and flat top-level rules. A simple `RuleSet::from(['name' => 'string|max:255'])->validate($data)` skips Laravel's validator entirely when the value passes.

### Batched database validation

When wildcard arrays use `exists` or `unique` rules, Laravel fires one database query per item. 500 items means 500 queries. `HasFluentRules` and `RuleSet::validate()` batch these into a single `whereIn` query automatically. Rules with scalar `where()` clauses are batched too. Rules with closure callbacks fall through to per-item validation as before. The batching is transparent: error messages, custom messages, and `validated()` output are unchanged. DB batching impact depends on the driver and network latency; it is measured in the test suite (`--group=benchmark`) rather than in `benchmark.php`.

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

#### When this won't help

The performance optimizations target wildcard array validation. These cases see little or no speedup:

- **`gt`/`gte`/`lt`/`lte` without a type flag** — Laravel derives comparison type from an accompanying rule (`string`/`array`/`numeric`/`integer`). Without one, these fall through to Laravel. With a type flag, sibling-field comparisons like `numeric|gt:min_price` are fast-checked.
- **`date_format` + date field-ref** — Laravel parses both sides with the declared format and has lenient missing-ref handling our strtotime-based closure can't match. Falls through to Laravel.
- **Multi-param `different:a,b,c`** — single-field `different:a` is fast-checked; comma-list forms fall through.
- **Custom `ValidationRule` objects and closures** — opaque to the fast-check compiler. Performance depends on what the rule does.
- **`distinct` rules** — require comparing values across all items in the array, not per-item.
- **Database rules with closure callbacks** (`exists`/`unique` with `->where(fn ...)`) — can't be batched; each item fires its own query.

If you're not sure whether validation is your bottleneck, profile first. Laravel Telescope shows total request time breakdowns.

> [!TIP]
> **Using Boost?**  
> The `optimize-validation` skill finds form requests with wildcard rules that are missing `HasFluentRules`, prioritizes them by impact, and adds the trait automatically.

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
| `[...$ruleSet]`                    | `array`         | Spread support via `IteratorAggregate` — yields `$this->toArray()` shape        |
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

    $safe = $result->safe();        // Illuminate\Support\ValidatedInput — gives you ->only(), ->except(), ->collect()
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

Mirrors Laravel's `Validator::validateWithBag()` without forcing you back to the `Validator::make(...)` incantation. Only affects the thrown exception's bag — `check()` never throws and is unaffected.

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

**Migrating rules in a non-standard method?** If your custom Validator holds its rules in a method that isn't named `rules()` (for example `rulesWithoutPrefix()` for a JSON-import pipeline), mark the method with `#[FluentRules]` so the migration Rector rules detect it:

```php
use SanderMuller\FluentValidation\FluentRules;

class JsonImportValidator extends FluentValidator
{
    #[FluentRules]
    public function rulesWithoutPrefix(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
```

The attribute has no runtime effect. It's a marker for the Rector rules only. Safe to leave in place after migrating.

---

## Testing fluent rules

`SanderMuller\FluentValidation\Testing\FluentRulesTester` lets you write direct unit tests against fluent rules, `RuleSet`s, `FluentFormRequest` subclasses, and `FluentValidator` subclasses without standing up the HTTP kernel or Livewire harness. It's the package's stable test surface — everything else under `Testing\` is `@internal`.

```php
use SanderMuller\FluentValidation\Testing\FluentRulesTester;

// 1. Array of rules
FluentRulesTester::for([
    'email' => FluentRule::email()->required(),
])->with(['email' => 'a@b.test'])->passes();

// 2. RuleSet instance
FluentRulesTester::for(
    RuleSet::make()->field('name', FluentRule::string()->required()->min(2))
)->with(['name' => 'Ada'])->passes();

// 3. A single FluentRule (wrapped under the "value" key)
FluentRulesTester::for(FluentRule::string()->required()->min(3))
    ->with(['value' => 'hi'])
    ->fails();

// 4. FormRequest class-string — runs the full FormRequest pipeline,
//    including authorize(). Bind the authenticated user via actingAs()
//    and any route parameters via withRoute() before invoking.
FluentRulesTester::for(UpdateVideoRequest::class)
    ->withRoute(['video' => $video])
    ->actingAs($user)
    ->with(['title' => 'Updated'])
    ->passes();

// 5. FluentValidator class-string — variadic args after `for(...)`
//    are forwarded to the FluentValidator subclass constructor after
//    `$data`, mirroring `new MyValidator($data, $user, $prefix)`.
FluentRulesTester::for(JsonImportValidator::class, $user, 'sku-')
    ->with($payload)
    ->passes();
```

`with(array $data)` is required before any assertion or escape hatch — calling them sooner raises `LogicException`. `with()` is re-callable, so a single tester can validate multiple data sets:

```php
$tester = FluentRulesTester::for($rules);
$tester->with(['qty' => 5])->passes();
$tester->with(['qty' => 0])->fails();
```

### Asserting specific failures

`failsWith()` checks `MessageBag::has()` for the field, and (when given) the underlying validator's `failed()` lookup for the rule key. The rule key is normalized via `Str::studly`, so `required` and `Required` both work:

```php
FluentRulesTester::for($rules)
    ->with(['name' => 'Jo'])
    ->failsWith('name', 'min');     // matches Laravel's 'Min' failed-rule key
```

For codebases that historically asserted on the *rendered* translation message (e.g. `assertJsonValidationErrors([... => [__('validation.min.string', [...])]])`), use `failsWithMessage()`:

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

Replacements are forwarded to the translator verbatim. Pass `:attribute` explicitly when your rules use labels — the validator pre-substitutes the label into the message before the bag stores it, so the comparison value must already match the labeled output.

For "this field is the *only* thing that should have failed" — surgical regression detection — use `failsOnly()`:

```php
FluentRulesTester::for($rules)
    ->with($payload)
    ->failsOnly('email', 'required');     // raises if any other field also failed
```

Wildcard error keys are fully expanded (`items.0.name`, `items.1.name`), so `failsOnly` requires exactly one matching key. For "any item under the wildcard failed" use `failsWithAny('items')` instead.

For the negative case — assert specific fields *did not* fail without enumerating the expected failures — use `doesNotFailOn()`:

```php
FluentRulesTester::for($rules)
    ->with($payload)
    ->fails()
    ->doesNotFailOn('email', 'name');     // these passed even though others failed
```

`doesNotFailOn` does not assert overall pass/fail — chain it after `fails()` or `passes()` if that matters to the test.

For "did this subtree fail at all?" assertions where you don't care about the specific child key, use `failsWithAny()`:

```php
FluentRulesTester::for(OfflineSyncRequest::class)
    ->with($payload)
    ->failsWithAny('actions.0.payload');     // matches actions.0.payload OR actions.0.payload.stars OR …
```

`failsWithAny($prefix)` matches the prefix exactly OR any dotted descendant (`$prefix.*`). It is **not** a substring match — `failsWithAny('payload')` will not match `someOther.payload.x`. For substring or regex matching, use `errors()` directly.

### Livewire components

Two distinct test shapes. Pick the one that matches what you're asserting:

| Shape              | When to use                                                                                                                      | Target                                                                        |
|--------------------|----------------------------------------------------------------------------------------------------------------------------------|-------------------------------------------------------------------------------|
| **Rules-only**     | Assert the `rules()` array has the right shape against a specific data payload. Component lifecycle irrelevant.                  | `for($component->rules())` or `for($ruleSet)`, then `->with($data)->passes()` |
| **Component-flow** | Drive `wire:model` state, dispatch an action, assert validation fires (or `addError` branches, guard clauses, multi-step flows). | `for(ComponentClass::class)->set(...)->call(...)`                             |

Don't reach for the class-string target just because the rules live in a Livewire component — use it only when the component's `submit()` flow (guards, `addError`, computed state) matters to the test. Shape-only tests stay on the array/RuleSet targets and `->with()`.

`FluentRulesTester::for(SomeLivewireComponent::class)` auto-detects Livewire `Component` subclasses and routes through `Livewire::test()` so the full `submit()` flow runs (guard clauses, `addError()` branches, computed state, rate-limit gates) — not just the rule set in isolation.

```php
use SanderMuller\FluentValidation\Testing\FluentRulesTester;

FluentRulesTester::for(AppealPage::class)
    ->set('type', 'refund')
    ->set('reason', 'Order arrived damaged in transit.')
    ->call('submit')
    ->passes();
```

`set($key, $value)` and `set([$key => $value])` both work (Livewire-parity). `call('submit')` is required before any assertion — Livewire validation only runs on action dispatch (calling `passes()`/`failsWith()` before `call()` raises `LogicException`).

`with([...])` also works on Livewire targets — it expands to `set($key, $value)` per pair, useful when porting tests that already use the data-shape style:

```php
FluentRulesTester::for(AppealPage::class)
    ->with(['type' => 'refund', 'reason' => 'Order arrived damaged in transit.'])
    ->call('submit')
    ->passes();
```

For components with `mount()` parameters:

```php
FluentRulesTester::for(EditAppealPage::class)
    ->mount(['appeal' => $appeal])
    ->call('submit')
    ->passes();
```

**Multi-action chains** — when action 1 mutates state that action 2 validates against (modal-open-then-submit, select-then-save), queue both with `call()` + `andCall()`. All queued actions dispatch in order against ONE `Livewire::test()` instance, so state persists:

```php
FluentRulesTester::for(ImportInteractionsModal::class)
    ->set('video', $targetVideo)
    ->call('selectVideo', $sourceVideo->uuid)
    ->andCall('import')
    ->failsWith('selectedInteractionIds', 'required');
```

`andCall()` is a readability alias for `call()` — both append to the action queue. Use whichever reads clearest at the call site.

**Error-bag capture** — both `$this->validate()`-driven failures AND manual `$this->addError(...)` calls surface via `failsWith()`. Pre-validate guards (rate-limit branch → `addError` → return before `validate()`) and post-validate `addError` (quota check after a successful validate()) both work:

```php
// Pre-validate guard — returns before validate() ever runs.
FluentRulesTester::for(AppealPage::class)
    ->set('rateLimited', true)
    ->call('submit')
    ->failsWith('reason');

// Post-validate addError — validate() passes, then addError.
FluentRulesTester::for(AppealPage::class)
    ->set('type', 'refund')
    ->set('reason', 'Long enough reason.')
    ->set('quotaExceeded', true)
    ->call('submit')
    ->failsWith('type');
```

**State is consumed per dispatch.** After one chain resolves, the accumulated `with()` / `set()` / `call()` state clears so reused testers don't leak prior cycles into new ones. Each `->call('submit')` at the tail of a new chain starts from a fresh `Livewire::test()` instance.

`livewire/livewire` is a soft dev dep — the Livewire branch `class_exists`-guards on `\Livewire\Component`. PHPUnit-only suites without Livewire installed see the standard "unsupported target" `LogicException` instead of a hard fatal at autoload time.

### Route parameters and authenticated user

FormRequests routinely read `$this->route('video')` inside `authorize()` or `rules()` for ownership checks and conditional validation, and gate via `$this->user()`. Bind both before invoking:

```php
FluentRulesTester::for(UpdateVideoRequest::class)
    ->withRoute(['video' => $video])
    ->actingAs($user)
    ->with(['title' => 'New title'])
    ->passes();
```

`withRoute()` accepts a `string => mixed` map. Inside the FormRequest:

- `$this->route('video')` returns the bound `$video`
- `$this->route('video', $default)` returns `$video` (default ignored when key present)
- `$this->route('missing', $default)` returns `$default`

`actingAs($user, $guard = null)` mirrors Laravel's test helper — sets the user on the auth guard before `validateResolved()` runs (FormRequest) or before `Livewire::test()` (Livewire component). Both methods are re-callable; later calls fully replace earlier ones (matching `with()`). For Livewire targets, `auth()->user()` inside `mount()`, actions, and policy gates returns the bound user.

### Unauthorized FormRequests

When a FormRequest's `authorize()` returns false, the tester records the `AuthorizationException` instead of rethrowing. Surface it via `assertUnauthorized()` (or just `fails()`):

```php
FluentRulesTester::for(AdminOnlyRequest::class)
    ->with($payload)
    ->fails()
    ->assertUnauthorized();
```

### Escape hatches

```php
$bag = FluentRulesTester::for($rules)->with($data)->errors();         // MessageBag
$ok  = FluentRulesTester::for($rules)->with($data)->validated();      // array, throws ValidationException on failure
```

### Pest expectations (optional)

If you use Pest, opt in to fluent expectations by `require_once`-ing the expectations file from your `tests/Pest.php`:

```php
// tests/Pest.php
require_once __DIR__ . '/../vendor/sandermuller/laravel-fluent-validation/src/Testing/PestExpectations.php';
```

```php
expect($rules)->toPassWith(['email' => 'a@b.test']);
expect($rules)->toFailOn(['email' => ''], 'email', 'required');
expect(FluentRule::string()->required())->toBeFluentRuleOf(StringRule::class);
```

The file `class_exists`-guards on `Pest\Expectation`, so requiring it under PHPUnit-only suites is safe — the extensions just don't register.

---

## Rule reference

Available types: `FluentRule::string()`, `integer()`, `numeric()`, `email()`, `password()`, `date()`, `dateTime()`, `boolean()`, `array()`, `file()`, `image()`, `field()`, `anyOf()`. Shortcuts: `url()`, `uuid()`, `ulid()`, `ip()`.

<details>
<summary><a name="rule-string"></a><strong>String</strong> — length, pattern, format, comparison</summary>

```php
FluentRule::string()->min(2)->max(255)->between(2, 255)->exactly(10)
FluentRule::string()->alpha()->alphaDash()->alphaNumeric()  // also: alpha(ascii: true)
FluentRule::string()->regex('/^[A-Z]+$/')->notRegex('/\d/')
FluentRule::string()->startsWith('prefix_')->endsWith('.txt')  // also: doesntStartWith(), doesntEndWith()
FluentRule::string()->lowercase()->uppercase()
FluentRule::string()->url()->uuid()->ulid()->json()->ip()->ipv4()->ipv6()->macAddress()->timezone()->hexColor()->activeUrl()->encoding('UTF-8')
FluentRule::string()->confirmed()->currentPassword()->same('field')->different('field')
FluentRule::string()->inArray('values.*')->inArrayKeys('values.*')->distinct()
```

> [!TIP]
> Top-level shortcuts for the most common single-rule strings: `FluentRule::url()`, `uuid()`, `ulid()`, `ip()`, `ipv4()`, `ipv6()`, `macAddress()`, `json()`, `timezone()`, `hexColor()`, `activeUrl()`, `regex($pattern)`. All accept an optional `$label`. Each is `FluentRule::string()->X()` — use the shortcut when the string type is the only constraint beyond the format.

</details>

<details>
<summary><a name="rule-email"></a><strong>Email</strong> — app defaults, modes, uniqueness</summary>

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
<summary><a name="rule-password"></a><strong>Password</strong> — strength, defaults</summary>

```php
FluentRule::password(min: 12)->letters()->mixedCase()->numbers()->symbols()->uncompromised()
```

`FluentRule::password()` uses your app's `Password::default()` configuration (set via `Password::defaults()` in AppServiceProvider). Pass `defaults: false` for a plain `Password::min(8)`: `FluentRule::password(defaults: false)`.

</details>

<details>
<summary><a name="rule-numeric"></a><strong>Numeric / Integer</strong> — type, size, digits, comparison</summary>

```php
FluentRule::integer()->required()->min(0)              // shorthand for numeric()->integer()
FluentRule::numeric()->integer(strict: true)->decimal(2)->min(0)->max(100)->between(1, 99)
FluentRule::numeric()->digits(4)->digitsBetween(4, 6)->minDigits(3)->maxDigits(5)->multipleOf(5)
FluentRule::numeric()->greaterThan('field')->lessThan('field')  // also: greaterThanOrEqualTo(), lessThanOrEqualTo()
FluentRule::numeric()->positive()->negative()          // gt:0 / lt:0 — sign-only helpers
FluentRule::numeric()->nonNegative()->nonPositive()    // gte:0 / lte:0 — allow zero
```

</details>

<details>
<summary><a name="rule-date"></a><strong>Date</strong> — boundaries, shortcuts, format</summary>

All comparison methods accept `DateTimeInterface|string`:

```php
FluentRule::date()->after('today')->before('2025-12-31')->between('2025-01-01', '2025-12-31')
FluentRule::date()->afterToday()->future()->nowOrPast()  // also: beforeToday(), todayOrAfter(), past(), nowOrFuture()
FluentRule::date()->format('Y-m-d')->dateEquals('2025-06-15')
FluentRule::dateTime()->afterToday()                     // shortcut for format('Y-m-d H:i:s')
```

</details>

<details>
<summary><a name="rule-other-types"></a><strong>Boolean, Array, File, Image, Field, AnyOf</strong></summary>

**Boolean** — `boolean()` accepts `true`, `false`, `1`, `0`, `'1'`, `'0'`. Use `accepted()` for `'yes'`, `'on'`, `'1'`, `true` and `declined()` for `'no'`, `'off'`, `'0'`, `false`:

```php
FluentRule::boolean()->accepted()->declined()
FluentRule::boolean()->acceptedIf('role', 'admin')->declinedIf('type', 'free')
```

**Accepted / Declined** — standalone factories for the permissive `accepted`/`declined` families without a strict `boolean` base. Useful for terms-of-service / opt-in checkboxes where form posts deliver `'yes'` or `'on'` values that Laravel's `boolean` rule rejects:

```php
FluentRule::accepted()                          // true | 1 | '1' | 'yes' | 'on' | 'true'
FluentRule::accepted()->acceptedIf('role', 'admin')
FluentRule::declined()                          // false | 0 | '0' | 'no' | 'off' | 'false'
FluentRule::declined()->declinedIf('under_18', 'yes')
```

> **Footgun:** `FluentRule::boolean()->accepted()` compiles to `boolean|accepted` — `boolean` rejects `'yes'` / `'on'` which `accepted` would otherwise permit. Use `FluentRule::accepted()` (or `::declined()`) when the input shape is HTML-form-ish.

**Array** — size, structure, allowed keys:

```php
FluentRule::array()->min(1)->max(10)->between(1, 5)->exactly(3)->list()
FluentRule::list()                    // shortcut for array()->list() — sequentially-indexed
FluentRule::array()->requiredArrayKeys('name', 'email')->contains('required_value')
FluentRule::array(['name', 'email'])  // restrict allowed keys
FluentRule::array(MyEnum::cases())    // BackedEnum keys
```

**File** — size methods accept integers (kilobytes) or human-readable strings:

```php
FluentRule::file()->max('5mb')->between('1mb', '10mb')
FluentRule::file()->extensions('pdf', 'docx')->mimes('jpg', 'png')->mimetypes('application/pdf')
```

**Image** — dimension constraints, inherits all file methods:

```php
FluentRule::image()->max('5mb')->allowSvg()
FluentRule::image()->minWidth(100)->maxWidth(1920)->minHeight(100)->maxHeight(1080)
FluentRule::image()->width(800)->height(600)->ratio(16 / 9)
```

**Field (untyped)** — modifiers without a type constraint. Use `field()` when the input has no inherent type (e.g. a value that could be a string OR integer depending on context), or when your only validation is modifiers (`required`, `nullable`, `in`, conditional presence). It's also the escape hatch Rector reaches for when it can't narrow the type from pipe/array rules. If you see `FluentRule::field()` in migrated code, consider whether a typed factory (`string()`, `integer()`) better expresses intent.

```php
FluentRule::field()->present()
FluentRule::field()->requiredIf('type', 'special')
FluentRule::field('Answer')->nullable()->in(['yes', 'no'])
```

**AnyOf** — value passes if it matches any rule set (Laravel 13+):

```php
FluentRule::anyOf([
    FluentRule::string()->required()->min(2),
    FluentRule::numeric()->required()->integer(),
])
```

</details>

<details>
<summary><a name="embedded-rules"></a><strong>Embedded rules</strong> — in, unique, exists, enum</summary>

String, numeric, and date rules support `in`, `unique`, `exists`, and `enum`. `in()` and `notIn()` accept arrays or a `BackedEnum` class:

```php
FluentRule::string()->in(['draft', 'published'])
FluentRule::string()->in(StatusEnum::class)          // all enum values
FluentRule::string()->notIn(DeprecatedStatus::class)
FluentRule::string()->enum(StatusEnum::class)
FluentRule::string()->enum(StatusEnum::class, fn ($r) => $r->only(StatusEnum::Active))
FluentRule::enum(StatusEnum::class)   // top-level shortcut — returns an untyped FieldRule
FluentRule::string()->unique('users', 'email')
FluentRule::string()->unique('users', 'email', fn ($r) => $r->ignore($this->user()->id))
FluentRule::string()->exists('roles', 'name')
FluentRule::string()->exists('subjects', 'id', fn ($r) => $r->where('active', true))
```

`unique()`, `exists()`, and `enum()` accept an optional callback as the last argument. The callback receives the underlying Laravel rule object, so you can chain `->where()`, `->ignore()`, `->only()`, etc.

</details>

<details>
<summary><a name="field-modifiers"></a><strong>Field modifiers</strong> — presence, prohibition, exclusion, messages</summary>

Shared by all rule types:

```php
// Presence
->required()  ->nullable()  ->sometimes()  ->filled()  ->present()  ->missing()

// Conditional presence: accepts field references or Closure|bool
->requiredIf('role', 'admin')  ->requiredUnless('type', 'guest')  ->requiredIf(fn () => $cond)
->requiredWith('field')  ->requiredWithAll('a', 'b')  ->requiredWithout('field')  ->requiredWithoutAll('a', 'b')
->requiredIfAccepted('terms')  ->requiredIfDeclined('terms')
->presentIf('type', 'admin')  ->presentUnless('type', 'guest')  ->presentWith('field')  ->presentWithAll('a', 'b')

// Prohibition & exclusion
->prohibited()  ->prohibitedIf('field', 'val')  ->prohibitedUnless('field', 'val')  ->prohibits('other')
->prohibitedIfAccepted('terms')  ->prohibitedIfDeclined('terms')
->exclude()  ->excludeIf('field', 'val')  ->excludeUnless('field', 'val')  ->excludeWith('f')  ->excludeWithout('f')

// Messages
->label('Name')  ->message('Rule-specific error')  ->messageFor('required', 'Custom msg')  ->fieldMessage('Field-level fallback')

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

**Conditional rules** — all rule types use Laravel's `Conditionable` trait. A single form request can handle both create and update:

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

**Escape hatch** — add any Laravel validation rule with `rule()`:

```php
FluentRule::string()->rule('email:rfc,dns')
FluentRule::string()->rule(new MyCustomRule())
FluentRule::file()->rule(['mimetypes', ...$acceptedTypes])
```

**Macros** — define reusable rule chains in a service provider:

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

The companion package [`sandermuller/laravel-fluent-validation-rector`](https://github.com/sandermuller/laravel-fluent-validation-rector) provides Rector rules that automate the bulk of a migration from native Laravel validation to FluentRule. In real-world testing against a production Laravel codebase, the rules converted **448 files across 3469 tests with zero regressions**.

```bash
composer require --dev sandermuller/laravel-fluent-validation-rector
```

```php
// rector.php
use Rector\Config\RectorConfig;
use SanderMuller\FluentValidationRector\Set\FluentValidationSetList;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/app'])
    ->withSets([FluentValidationSetList::ALL]);
```

```bash
vendor/bin/rector process --dry-run   # preview changes
vendor/bin/rector process             # apply them
vendor/bin/pint                       # fix code style after
```

The Rector package covers the full migration surface: pipe-delimited strings, array-based rules, `Rule::` objects, `Password::min()` chains, conditional tuples, closures, custom rule objects, Livewire `#[Rule]` / `#[Validate]` attributes, wildcard grouping, trait insertion, and post-migration chain cleanup. Organized into composable sets:

| Set        | Includes                                                                                                                                                                |
|------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `ALL`      | `CONVERT` + `GROUP` + `TRAITS` (full migration pipeline)                                                                                                                |
| `CONVERT`  | String, array, and `#[Rule]` / `#[Validate]` attribute converters                                                                                                       |
| `GROUP`    | Wildcard and dot-notation grouping into `each()` / `children()`                                                                                                         |
| `TRAITS`   | `HasFluentRules` for FormRequests, `HasFluentValidation` (with Filament variant) for Livewire                                                                           |
| `SIMPLIFY` | Post-migration chain cleanup — `string()->url()` → `url()`, `min()+max()` → `between()`, redundant-type removal. Run separately after verifying the initial conversion. |

See the [Rector package README](https://github.com/sandermuller/laravel-fluent-validation-rector) for the full rule-by-rule reference, configuration options (`PRESERVE_REALTIME_VALIDATION`, `BASE_CLASSES`), and diagnostics guidance.

See [Common migration patterns](resources/boost/skills/fluent-validation/references/migration-patterns.md) for a detailed reference covering rule-type selection, `Rule::` method conversion, BackedEnum handling, and advanced patterns.

The Rector rules aren't just for migration. Run `ALL` (or `SIMPLIFY` on its own) in CI as an ongoing code-quality gate. New validation code (new FormRequests, new Livewire components, new Filament pages) goes through the same converters, grouping, and trait insertion as the initial migration did, so patterns stay consistent as the codebase grows.

### Style: prefer explicit parent rules

When writing new rules, define the parent array alongside its wildcard children even when you don't have a type constraint:

```php
// Preferred
'items'        => FluentRule::array()->required(),
'items.*.name' => FluentRule::string()->required(),

// Works, but less explicit
'items.*.name' => FluentRule::string()->required(),
```

Rector's `GroupWildcardRulesToEachRector` synthesizes `FluentRule::array()->nullable()` when no parent rule exists (preserving Laravel's flat-rule null-parity). That's safe, but explicit parents are self-documenting and let you control nullability/required/size at the array level. Existing codebases without explicit parents migrate fine; this is a convention for new code, not a rule.

### Known limitations & verification workflow

The Rector rules are deliberately conservative. They'll skip abstract classes, custom Validator subclasses, dynamically-built rules, and enum cases in conditional tuples, among others. The full skip list, verbose-skip diagnostics (`FLUENT_VALIDATION_RECTOR_VERBOSE=1`), and a step-by-step post-migration verification checklist (baseline test run → dry-run → apply → diff-size sanity → spot-check → filter-runs → final green) live in the [Rector package README](https://github.com/sandermuller/laravel-fluent-validation-rector#known-limitations). Start there before migrating anything non-trivial.

## Troubleshooting

**`validated()` is missing nested keys (children, each)**
Add `use HasFluentRules` to your form request. Without the trait, FluentRule objects self-validate in isolation and nested keys don't appear in `validated()` output.

**Labels not working ("The name field" instead of "The Full Name field")**
Add `use HasFluentRules`. The trait extracts labels from rule objects and passes them to the validator. Without it, labels are only used inside the rule's self-validation.

**Cross-field wildcard references don't work (`requiredUnless('items.*.type', ...)`)**
These require `HasFluentRules` or `FluentValidator` to resolve wildcard paths. Standalone FluentRule objects self-validate in isolation.

**`mergeRecursive` breaks rules in child form requests**
PHP's `mergeRecursive` deconstructs objects into arrays. Use `(clone $parentRule)->rule(...)` to augment or `[...parent::rules(), 'field' => ...]` to override. See [Extending parent rules](#extending-parent-rules-in-child-form-requests).

**Method not found on a rule type**
Use `->rule('method_name')` as an escape hatch for any Laravel rule not yet available as a fluent method. Accepts strings, objects, and `['rule', ...$params]` tuples.
If you think it should be a native method, [open an issue](https://github.com/SanderMuller/laravel-fluent-validation/issues) and we'll add it.

**`UnknownFluentRuleMethod: FluentRule::field() has no method ...()`**
`FluentRule::field()` is the untyped builder — it carries no base type constraint. Modifiers (`required`, `nullable`, `present`, conditional presence), `children()`, `same`/`different`/`confirmed`, and the embedded-rule factories (`exists`, `unique`, `enum`, `in`, `notIn`) all work on it, plus the `->rule(...)` escape hatch. What it intentionally does *not* expose is **type-specific rules** — `min`, `max`, `regex`, `email`, `digits`, `mimes`, `before`, `after`, `contains`, etc. Those live on the typed builders so the base-type constraint is always implicit. The exception message names the builder(s) that expose the method you called; pick the one matching your field's type:

```php
// Before — UnknownFluentRuleMethod: FluentRule::field() has no method min()
FluentRule::field()->required()->min(5);

// After — chain on a typed builder
FluentRule::numeric()->required()->min(5);   // numeric value
FluentRule::string()->required()->min(5);    // string length
FluentRule::array()->required()->min(5);     // element count
FluentRule::file()->required()->min('2mb');  // file size
```

For belt-and-suspenders coverage in downstream apps, the package ships a Pest/PHPUnit arch helper that scans source for the footgun at test time — see `SanderMuller\FluentValidation\Testing\Arch\BansFieldRuleTypeMethods` (requires `nikic/php-parser` as a dev dep).

The related smell `FluentRule::field()->rule('min:1')` (or any `->rule('some_type_rule:...')` on `field()`) works at runtime — the string goes through Laravel's rule pipeline — but the field clearly *has* a base type, so the typed builder is more idiomatic: `FluentRule::numeric()->min(1)` is shorter, lets the IDE autocomplete the parameter, and lets the [Rector companion](https://github.com/sandermuller/laravel-fluent-validation-rector) simplify it automatically. If you see `->rule('min:1')` / `->rule('max:...')` / `->rule('regex:...')` etc. on `field()`, pick the typed builder that matches.

**`HasFluentValidation` conflicts with Filament's `InteractsWithSchemas`**
Both traits define `validate()`. For Filament components, use `RuleSet::compileToArrays()` instead of the trait: `$this->validate(RuleSet::compileToArrays($this->rules()))`. This returns `array<string, array<mixed>>` matching Livewire's expected type, so PHPStan is happy. FluentRule works correctly without the trait for simple rules.

**Migration issues (Rector companion)**
Rector-specific issues (`Attempt to read property 'value' on int`, `array_search(): Argument #2 must be of type array`, post-migration message drift, `SplObjectStorage` crashes, etc.) are tracked in the [laravel-fluent-validation-rector README](https://github.com/sandermuller/laravel-fluent-validation-rector#troubleshooting). Update the Rector companion to the latest version first; most are fixed upstream.

## Running the package's test suite

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Security vulnerabilities

Please review [our security policy](SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Sander Muller](https://github.com/sandermuller)
- [All Contributors](../../contributors)

## License

MIT License. Please see [License File](LICENSE.md) for more information.
