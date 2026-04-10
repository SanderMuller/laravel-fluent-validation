# Fluent validation rule builders for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sandermuller/laravel-fluent-validation.svg?style=flat-square)](https://packagist.org/packages/sandermuller/laravel-fluent-validation)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/sandermuller/laravel-fluent-validation/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/sandermuller/laravel-fluent-validation/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/sandermuller/laravel-fluent-validation/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/sandermuller/laravel-fluent-validation/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)

Write Laravel validation rules with IDE autocompletion instead of memorizing string syntax. Each rule type only exposes the methods that make sense for it, and `each()`/`children()` let you co-locate parent and child rules. For large arrays, the `HasFluentRules` trait makes wildcard validation [up to 97x faster](#benchmarks).

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

## Contents

**Getting started**
- [Installation](#installation)
- [Quick start](#quick-start) — Validator::make, Form Requests, migrating existing rules
- [Error messages](#error-messages) — labels, per-rule messages
- [Array validation](#array-validation-with-each-and-children) — each, children, nesting

**Deep dive**
- [Livewire](#livewire) — HasFluentValidation trait, Filament workaround
- [Why this package?](#why-this-package) — DX, type safety, structure, performance
- [Performance](#performance) — O(n) wildcards, benchmarks, RuleSet::validate
- [RuleSet](#ruleset) — builder, conditional fields, custom Validators
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

### In a form request

To get the full feature set (wildcard optimization, label extraction, fast-checks), add the `HasFluentRules` trait to your form request:

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
            'agree'    => FluentRule::boolean()->accepted(),
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

Without the trait, FluentRule objects still work (they implement `ValidationRule`), but [`each()`/`children()`](#array-validation-with-each-and-children), [labels](#error-messages), [wildcard optimization](#performance), and [precognitive requests](https://laravel.com/docs/precognition) all require it for correct error messages and `validated()` output.

If you prefer, you may extend `FluentFormRequest` instead of adding the trait manually:

```php
use SanderMuller\FluentValidation\FluentFormRequest;

class StorePostRequest extends FluentFormRequest
{
    public function rules(): array { /* same as above */ }
}
```

FluentRule objects implement Laravel's `ValidationRule` interface, so they work in `Validator::make()`, `Rule::forEach()`, and `Rule::when()` too. For inline validation outside form requests, prefer [`RuleSet::validate()`](#ruleset) over `Validator::make()`. It gives you the same optimizations, label extraction, and `each()`/`children()` expansion as `HasFluentRules`. Use [`->when()`](#conditional-rules) to handle create and update in a single form request. For Livewire, see [Livewire](#livewire).

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

## Error messages

### Labels

You may pass a label as the first argument to any factory method. It replaces `:attribute` in error messages for that field:

```php
return [
    'name'  => FluentRule::string('Full Name')->required()->min(2)->max(255),
    'email' => FluentRule::email('Email Address')->required(),
    'age'   => FluentRule::numeric('Your Age')->nullable()->integer()->min(0),
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

To customize the error message for a specific rule, use `->messageFor('rule', 'message')`:

```php
FluentRule::string('Full Name')
    ->required()
    ->min(2)
    ->max(255)
    ->messageFor('required', 'We need your name!')
    ->messageFor('min', 'At least :min characters.')
```

Alternatively, chain `->message()` immediately after the rule it applies to:

```php
FluentRule::string('Full Name')
    ->required()->message('We need your name!')
    ->min(2)->message('At least :min characters.')
    ->max(255)
```

> [!WARNING]
> `->message()` must be called after a rule method. Calling it before any rule (e.g. `FluentRule::string()->message(...)`) throws a `LogicException`. Use `->messageFor()` if you prefer to group messages at the end of the chain.

Labels affect all error messages for the field. `->messageFor()` and `->message()` override a specific rule. For a field-level fallback that applies to any failure, use `->fieldMessage()`:

```php
FluentRule::string()->required()->min(2)->fieldMessage('Something is wrong with this field.')
```

Standard Laravel `messages()` arrays and `Validator::make()` message arguments still work and take priority over `->messageFor()`, `->message()`, and `->fieldMessage()`.

## Array validation with `each()` and `children()`

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

The trait overrides `validate()` and `validateOnly()`, so `wire:model.blur` real-time validation works automatically. It also works on Livewire Form objects. If your component uses a `public array $rules` property, switch to a `rules()` method. FluentRule objects can't be declared in property defaults.

> [!CAUTION]
> Wildcard keys with Livewire  
> Livewire reads wildcard keys from `rules()` before compilation. Use flat wildcard keys instead of `each()` for array fields: `'items.*' => FluentRule::string()`, not `FluentRule::array()->each(...)`. Using `each()` silently breaks Livewire's wildcard handling.

**Filament components:** `HasFluentValidation` conflicts with Filament's `InteractsWithSchemas` because both define `validate()`. Use [`RuleSet::compileToArrays()`](#ruleset) instead: `$this->validate(RuleSet::compileToArrays($this->rules()))`.

### Livewire + Laravel Boost

If you use [Laravel Boost](https://github.com/laravel/boost), the `fluent-validation-livewire` skill covers trait usage, Filament workarounds, and common mistakes automatically.

## Why this package?

If you've ever had to look up whether it's `required_with` or `required_with_all`, or whether the method is `digits_between` or `digitsBetween`, you know the frustration. Fluent rules let your IDE answer that for you. And because each type has its own class, `FluentRule::string()` won't even offer `digits()`.

Beyond autocompletion, `each()` and `children()` let you group parent and child rules together instead of scattering 20 flat dot-notation keys across the file. Labels and messages go right on the rule, so you don't end up maintaining a separate `messages()` array that slowly drifts out of date.

There's also a performance side. Laravel's wildcard validation is O(n²) for large arrays. The `HasFluentRules` trait fixes that, making it [up to 97x faster](#benchmarks) for simple rules.

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
| `Rule::enum(Status::class)`           | `FluentRule::string()->enum(Status::class)`                 |
| `Rule::anyOf([...])`                  | `FluentRule::anyOf([...])`                                  |
| No equivalent                         | `->each([...])` co-locate wildcard child rules              |
| No equivalent                         | `->children([...])` co-locate fixed-key child rules         |
| No equivalent                         | `->label('Name')` / `->message('...')` inline messages      |
| No equivalent                         | `->whenInput(fn ($input) => ...)` data-dependent conditions |
| No equivalent                         | `HasFluentRules` automatic compile + expand optimization    |
| No equivalent                         | `FluentValidator` base class for custom Validators          |

</details>

## Performance

FluentRule objects compile to native Laravel format before validation runs. There is no runtime overhead compared to string rules.

For large arrays with wildcard rules (`items.*.name`), the `HasFluentRules` trait replaces Laravel's [O(n²) wildcard expansion](https://github.com/laravel/framework/issues/49375) with O(n). It also generates PHP closures for 25 common rules (string, numeric, email, in, regex, etc.) that validate items without going through Laravel at all. Rules that can't be fast-checked (date comparisons, custom closures) fall through to Laravel as normal.

> [!NOTE]
> These optimizations require `HasFluentRules` (FormRequest), `HasFluentValidation` (Livewire), `FluentValidator`, or `RuleSet::validate()`. When using `$request->validate()` or bare `Validator::make()`, FluentRule objects self-validate without wildcard optimization.

### Benchmarks

| Scenario                                          | Native Laravel | With HasFluentRules |
|---------------------------------------------------|----------------|---------------------|
| 500 items, simple rules (string, numeric, in)     | ~200ms         | **~2ms**            |
| 500 items, mixed rules (string + date comparison) | ~200ms         | **~20ms**           |
| 100 items, 47 conditional fields (exclude_unless) | ~3,200ms       | **~83ms**           |

Simple type+size rules get the largest speedup (50-97x) because the PHP closures are trivially cheap. Mixed rule sets benefit from partial fast-checking. Conditional rules (`exclude_unless`, `required_if` with closures) can't be fast-checked but still benefit from conditional pre-evaluation that removes excluded attributes before validation runs.

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

| Method                             | Returns         | Description                                                       |
|------------------------------------|-----------------|-------------------------------------------------------------------|
| `RuleSet::from([...])`             | `RuleSet`       | Create from a rules array                                         |
| `RuleSet::make()->field(...)`      | `RuleSet`       | Fluent builder                                                    |
| `->merge($ruleSet)`                | `RuleSet`       | Merge another RuleSet or array into this one                      |
| `->when($cond, $callback)`         | `RuleSet`       | Conditionally add fields (also: `unless`)                         |
| `->toArray()`                      | `array`         | Flat rules with `each()` expanded to wildcards                    |
| `->validate($data)`                | `array`         | Validate with full optimization (see [Performance](#performance)) |
| `->prepare($data)`                 | `PreparedRules` | Expand, extract metadata, compile. For custom Validators          |
| `->expandWildcards($data)`         | `array`         | Pre-expand wildcards without validating                           |
| `RuleSet::compile($rules)`         | `array`         | Compile fluent rules to native Laravel format                     |
| `RuleSet::compileToArrays($rules)` | `array`         | Compile to array format for Livewire's `$this->validate()`        |
| `->dump()`                         | `array`         | Returns `{rules, messages, attributes}` for debugging             |
| `->dd()`                           | `never`         | Dumps and terminates                                              |

### Using with `validateWithBag` or custom Validator instances

If you need a `Validator` instance directly (for `validateWithBag`, custom error bags, or manual inspection), you may use the `prepare()` method:

```php
$prepared = RuleSet::from($rules)->prepare($request->all());

$validator = Validator::make(
    $request->all(),
    $prepared->rules,
    array_merge($prepared->messages, $customMessages),
    $prepared->attributes,
);

$validator->validateWithBag('myBag');
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
FluentRule::string()->url()->uuid()->ulid()->json()->ip()->macAddress()->timezone()->hexColor()->encoding('UTF-8')
FluentRule::string()->confirmed()->currentPassword()->same('field')->different('field')
FluentRule::string()->inArray('values.*')->inArrayKeys('values.*')->distinct()
```

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

**Boolean** — acceptance and decline:

```php
FluentRule::boolean()->accepted()->declined()
FluentRule::boolean()->acceptedIf('role', 'admin')->declinedIf('type', 'free')
```

**Array** — size, structure, allowed keys:

```php
FluentRule::array()->min(1)->max(10)->between(1, 5)->exactly(3)->list()
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

**Field (untyped)** — modifiers without a type constraint:

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
// In a service provider
NumericRule::macro('percentage', fn () => $this->integer()->min(0)->max(100));
StringRule::macro('slug', fn () => $this->alpha(true)->lowercase());

// Then use anywhere
FluentRule::numeric()->percentage()
FluentRule::string()->slug()
```

</details>

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

**`HasFluentValidation` conflicts with Filament's `InteractsWithSchemas`**
Both traits define `validate()`. For Filament components, use `RuleSet::compileToArrays()` instead of the trait: `$this->validate(RuleSet::compileToArrays($this->rules()))`. This returns `array<string, array<mixed>>` matching Livewire's expected type, so PHPStan is happy. FluentRule works correctly without the trait for simple rules.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Security vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Sander Muller](https://github.com/sandermuller)
- [All Contributors](../../contributors)

## License

MIT License. Please see [License File](LICENSE.md) for more information.
