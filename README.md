# Fluent validation rule builders for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sandermuller/laravel-fluent-validation.svg?style=flat-square)](https://packagist.org/packages/sandermuller/laravel-fluent-validation)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/sandermuller/laravel-fluent-validation/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/sandermuller/laravel-fluent-validation/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/sandermuller/laravel-fluent-validation/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/sandermuller/laravel-fluent-validation/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)

Write Laravel validation rules with IDE autocompletion instead of memorizing string syntax. Type-safe builders prevent impossible rule combinations, and nested `each()`/`children()` rules keep your structure readable.

```php
// Before
['name' => 'required|string|min:2|max:255']

// After
['name' => FluentRule::string('Full Name')->required()->min(2)->max(255)]
```

## Contents

- [Why this package?](#why-this-package) — DX, type safety, structure, performance
- [Installation](#installation)
- [Quick start](#quick-start) — Validator::make, Form Requests, Livewire, migrating existing rules
- [Error messages](#error-messages) — labels, per-rule messages
- [Array validation](#array-validation-with-each-and-children) — each, children, nesting
- [Performance](#performance) — O(n) wildcards, benchmarks, RuleSet::validate
- [RuleSet](#ruleset) — builder, conditional fields, custom Validators
- [Rule reference](#rule-reference) — string, email, password, numeric, date, boolean, array, file, image, field, anyOf, modifiers, conditionals, macros
- [Troubleshooting](#troubleshooting) — common issues and solutions

## Why this package?

**Autocompletion instead of memorization.** Is it `required_with` or `required_with_all`? `digits_between` or `digitsBetween`? With fluent rules, your IDE tells you.

**Type-safe combinations.** `FluentRule::string()` doesn't have `digits()`. `FluentRule::numeric()` doesn't have `alpha()`. Impossible combinations won't compile.

**Rules that match your data shape.** `each()` and `children()` keep parent and child rules together instead of spreading 20 flat dot-notation keys across the file.

**Labels and messages next to the rules.** No more maintaining a separate `messages()` array that drifts out of sync.

**Faster array validation.** For large arrays, the `HasFluentRules` trait replaces Laravel's O(n²) wildcard expansion with O(n) and applies per-attribute fast-checks that skip Laravel entirely for valid items. [Up to 97x faster](#benchmarks) for simple rules.

### Compared to Laravel's `Rule` class

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

## Installation

```bash
composer require sandermuller/laravel-fluent-validation
```

Requires PHP 8.2+ and Laravel 11+.

### AI-assisted development

This package ships with [Laravel Boost](https://github.com/laravel/boost) skills. If you use Boost:

```bash
php artisan boost:install    # adds the skills
php artisan boost:update     # publishes updates after package upgrades
```

AI assistants will automatically get the full FluentRule API reference when writing validation rules.

## Quick start

The simplest way to use FluentRule is anywhere you'd normally write validation rules. Let's start with `Validator::make()`:

```php
use SanderMuller\FluentValidation\FluentRule;

$validated = Validator::make($request->all(), [
    'name'  => FluentRule::string('Full Name')->required()->min(2)->max(255),
    'email' => FluentRule::email('Email')->required(),
    'age'   => FluentRule::numeric('Age')->nullable()->integer()->min(0),
])->validate();
```

The label `'Full Name'` replaces `:attribute` in error messages. You get "The Full Name field is required" instead of "The name field is required", without a separate `attributes()` array.

### In a Form Request

Add the `HasFluentRules` trait to your Form Requests. It compiles rules to native Laravel format, optimizes wildcard expansion, extracts labels and messages, and applies per-attribute fast-checks for eligible wildcard rules:

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

The `HasFluentRules` trait is required for correct behavior with `each()`, `children()`, labels, messages, and cross-field wildcard references.

Alternatively, extend `FluentFormRequest` instead of `FormRequest` — it applies the trait automatically:

```php
use SanderMuller\FluentValidation\FluentFormRequest;

class StorePostRequest extends FluentFormRequest
{
    public function rules(): array { /* same as above */ }
}
```

FluentRule objects implement Laravel's `ValidationRule` interface. They also work in `Validator::make()`, `Rule::forEach()`, and `Rule::when()`.

### In a Livewire component

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

> Livewire reads wildcard keys from `rules()` before compilation. Use flat wildcard keys instead of `each()` for array fields: `'items.*' => FluentRule::string()`, not `FluentRule::array()->each(...)`.

> If your component uses a `public array $rules` property, switch to a `rules()` method. FluentRule objects can't be declared in property defaults.

### Migrating existing rules

You don't need to convert all your rules at once. Fluent rules mix freely with string rules and native rule objects in the same array:

```php
$rules = [
    'name'   => FluentRule::string()->required()->min(2)->max(255),  // fluent
    'email'  => 'required|string|email|max:255',               // string, still works
    'role'   => ['required', LaravelRule::in(['admin', 'user'])],  // array, still works
];
```

**Step 1:** Add `use HasFluentRules` to your Form Request. This works even before you convert any rules.

**Step 2:** Convert fields one at a time. Start with the ones that benefit most from autocompletion (complex conditionals, date comparisons, nested arrays).

**Step 3:** For rules without a direct fluent method, use the `rule()` escape hatch:

```php
FluentRule::string()->rule('email:rfc,dns')           // string rule
FluentRule::string()->rule(new MyCustomRule())         // object rule
FluentRule::file()->rule(['mimetypes', ...$types])     // array tuple
```

**Tips for common patterns:**

| Before                                            | After                                                                     |
|---------------------------------------------------|---------------------------------------------------------------------------|
| `'items.*.name' => 'required&#124;string'`        | `FluentRule::array()->each(['name' => FluentRule::string()->required()])` |
| `'search' => 'array'` + `'search.value' => '...'` | `FluentRule::array()->children(['value' => ...])`                         |
| `Rule::in([...])`                                 | `->in([...])` or `->in(MyEnum::class)`                                    |
| `Rule::unique('users')`                           | `->unique('users')`                                                       |
| `Rule::forEach(fn () => ...)`                     | `FluentRule::array()->each(...)`                                          |

**Tips:**

- All conditional methods (`requiredIf`, `excludeUnless`, etc.) accept `string|int|bool` values.
- `each()` and `children()` nest naturally. Flat dot-notation keys like `columns.*.data.sort` become nested `each([...children([...])])` trees that mirror the data shape.
- FluentRule objects in rules arrays are objects, not arrays. Don't use `array_search`, `mergeRecursive`, or other structural array functions on them.

**Migrating an existing codebase?** If you have [Laravel Boost](https://github.com/laravel/boost) installed, ask your AI assistant to run the `optimize-validation` skill — it scans your codebase for convertible rules, prioritizes by impact, and applies changes file by file with test verification.

### Extending parent rules in child FormRequests

When a child class needs to add rules to fields defined by the parent, use clone + `rule()`:

```php
// Parent
public function rules(): array
{
    return [
        'type' => FluentRule::field()->required()->rule(new EnumValue(QuestionType::class)),
        'name' => FluentRule::string()->required()->max(255),
    ];
}

// Child — augment 'type' with extra validation
public function rules(): array
{
    $rules = parent::rules();
    $rules['type'] = (clone $rules['type'])->rule(function (string $attribute, mixed $value, Closure $fail): void {
        // extra validation for updates
    });

    return $rules;
}
```

To fully replace a field, use spread + override: `return [...parent::rules(), 'type' => FluentRule::string()->required()]`.

## Error messages

### Labels

Pass a label to the factory method. It replaces `:attribute` in all error messages for that field:

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

Labels work in Form Requests, `Validator::make()`, and `RuleSet::validate()`. They also work inside `each()`, so child fields get clean names too:

```php
'items' => FluentRule::array()->required()->each([
    'name'  => FluentRule::string('Item Name')->required(),
    'email' => FluentRule::email('Email')->required(),
]),
// "The Item Name field is required." (instead of "The items.0.name field is required.")
```

You can also set a label after construction with `->label('Name')`.

### Per-rule messages

Attach a custom error message to the most recently added rule with `->message()`:

```php
FluentRule::string('Full Name')
    ->required()->message('We need your name!')
    ->min(2)->message('At least :min characters.')
    ->max(255)
```

Labels affect all error messages for the field. `->message()` overrides a specific rule. For a field-level fallback that applies to any failure, use `->fieldMessage()`:

```php
FluentRule::string()->required()->min(2)->fieldMessage('Something is wrong with this field.')
```

> **Note:** `->message()` must be called after a rule method. Calling it before any rule (e.g. `FluentRule::string()->message(...)`) throws a `LogicException`.

> Standard Laravel `messages()` arrays and `Validator::make()` message arguments still work and take priority over `->message()` and `->fieldMessage()`.

## Array validation with `each()` and `children()`

Define rules for each item in an array inline with `each()`:

```php
// Scalar items: each tag must be a string under 255 characters
FluentRule::array()->each(FluentRule::string()->max(255))

// Object items: each item has named fields
FluentRule::array()->required()->each([
    'name'  => FluentRule::string('Item Name')->required(),
    'email' => FluentRule::string()->required()->rule('email'),
    'qty'   => FluentRule::numeric()->required()->integer()->min(1),
])

// Nested arrays
FluentRule::array()->each([
    'items' => FluentRule::array()->each([
        'qty' => FluentRule::numeric()->required()->min(1),
    ]),
])
```

`each()` works standalone and through Form Requests with `HasFluentRules`. The trait and `RuleSet` both optimize wildcard expansion.

### Fixed-key children with `children()`

For objects with known keys (not wildcard arrays), use `children()` to keep child rules with the parent:

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

## Performance

FluentRule objects compile to native Laravel format before validation runs. There is no runtime overhead compared to string rules.

For large arrays with wildcard rules (`items.*.name`), the `HasFluentRules` trait optimizes validation automatically. It replaces Laravel's [O(n²) wildcard expansion](https://github.com/laravel/framework/issues/49375) with O(n), and applies per-attribute fast-checks using pure PHP closures that skip Laravel entirely for valid items. 25 common rules are fast-checked including string, numeric, email, url, in, regex, and more. Fields with non-eligible rules (date comparisons, custom closures) fall through to Laravel transparently.

### Benchmarks

| Scenario                                          | Native Laravel | With HasFluentRules |
|---------------------------------------------------|----------------|---------------------|
| 500 items, simple rules (string, numeric, in)     | ~200ms         | **~2ms**            |
| 500 items, mixed rules (string + date comparison) | ~200ms         | **~20ms**           |
| 100 items, 47 conditional fields (exclude_unless) | ~3,200ms       | **~83ms**           |

Simple type+size rules get the largest speedup (50-97x) because the PHP closures are trivially cheap. Mixed rule sets benefit from partial fast-checking. Conditional rules (`exclude_unless`, `required_if` with closures) can't be fast-checked but still benefit from per-item validation via `RuleSet::validate()`.

### `RuleSet::validate()`

For inline validation outside FormRequests, `RuleSet::validate()` applies the same optimizations:

```php
$validated = RuleSet::from([
    'items' => FluentRule::array()->required()->each([
        'name' => FluentRule::string('Item Name')->required()->min(2),
        'qty'  => FluentRule::numeric()->required()->integer()->min(1),
    ]),
])->validate($request->all());
```

Benchmarks run automatically on PRs via GitHub Actions. All optimizations are Octane-safe (factory resolver restored via try/finally, no static state leakage).

## RuleSet

`RuleSet` wraps a set of rules with methods for building, merging, and validating:

```php
use SanderMuller\FluentValidation\RuleSet;

// From an array
$validated = RuleSet::from([
    'name'  => FluentRule::string('Full Name')->required()->min(2)->max(255),
    'email' => FluentRule::email('Email')->required(),
    'items' => FluentRule::array()->required()->each([
        'name'  => FluentRule::string()->required()->min(2),
        'price' => FluentRule::numeric()->required()->min(0),
    ]),
])->validate($request->all());

// Or fluently, with conditional fields and merging
$validated = RuleSet::make()
    ->field('name', FluentRule::string('Full Name')->required())
    ->field('email', FluentRule::email('Email')->required())
    ->when($isAdmin, fn (RuleSet $set) => $set
        ->field('role', FluentRule::string()->required()->in(['admin', 'editor']))
        ->field('permissions', FluentRule::array()->required())
    )
    ->merge($sharedAddressRules)
    ->validate($request->all());
```

`when()` and `unless()` are available via Laravel's `Conditionable` trait. `merge()` accepts another `RuleSet` or a plain array.

| Method                              | Returns         | Description                                                       |
|-------------------------------------|-----------------|-------------------------------------------------------------------|
| `RuleSet::from([...])`              | `RuleSet`       | Create from a rules array                                         |
| `RuleSet::make()->field(...)`       | `RuleSet`       | Fluent builder                                                    |
| `->merge($ruleSet)`                 | `RuleSet`       | Merge another RuleSet or array into this one                      |
| `->when($cond, $callback)`          | `RuleSet`       | Conditionally add fields (also: `unless`)                         |
| `->toArray()`                       | `array`         | Flat rules with `each()` expanded to wildcards                    |
| `->validate($data)`                 | `array`         | Validate with full optimization (see [Performance](#performance)) |
| `->prepare($data)`                  | `PreparedRules` | Expand, extract metadata, compile. For custom Validators          |
| `->expandWildcards($data)`          | `array`         | Pre-expand wildcards without validating                           |
| `RuleSet::compile($rules)`          | `array`         | Compile fluent rules to native Laravel format                     |
| `RuleSet::compileToArrays($rules)`  | `array`         | Compile to array format — for Livewire's `$this->validate()`     |

### Using with `validateWithBag` or custom Validator instances

When you need a Validator instance (for `validateWithBag`, custom error bags, or manual inspection), use `prepare()`:

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

If you extend `Illuminate\Validation\Validator` directly (e.g., for import jobs), extend `FluentValidator` instead:

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

### String

Length, pattern, format, and comparison constraints:

```php
FluentRule::string()->min(2)->max(255)->between(2, 255)->exactly(10)
FluentRule::string()->alpha()->alphaDash()->alphaNumeric()  // also: alpha(ascii: true)
FluentRule::string()->regex('/^[A-Z]+$/')->notRegex('/\d/')
FluentRule::string()->startsWith('prefix_')->endsWith('.txt')  // also: doesntStartWith(), doesntEndWith()
FluentRule::string()->lowercase()->uppercase()
FluentRule::string()->url()->uuid()->ulid()->json()->ip()->macAddress()->timezone()->hexColor()
FluentRule::string()->confirmed()->currentPassword()->same('field')->different('field')
FluentRule::string()->inArray('values.*')->inArrayKeys('values.*')->distinct()
```

### Email

`FluentRule::email()` uses your app's `Email::default()` configuration when set. Pass `defaults: false` for basic validation:

```php
FluentRule::email()->required()                     // uses Email::default() if configured
FluentRule::email(defaults: false)->required()       // basic 'email' validation
FluentRule::email()->rfcCompliant()->strict()         // explicit modes override defaults
FluentRule::email()->validateMxRecord()->preventSpoofing()
FluentRule::email()->required()->unique('users', 'email')
```

> **Tip:** `FluentRule::string()->email()` is also available if you prefer keeping email as a string modifier.

### Password

Chainable strength requirements:

```php
FluentRule::password(min: 12)->letters()->mixedCase()->numbers()->symbols()->uncompromised()
```

> `FluentRule::password()` uses your app's `Password::default()` configuration (set via `Password::defaults()` in AppServiceProvider). Pass `defaults: false` for a plain `Password::min(8)`: `FluentRule::password(defaults: false)`.

### Numeric

Type, size, digit, and comparison constraints:

```php
FluentRule::numeric()->integer(strict: true)->decimal(2)->min(0)->max(100)->between(1, 99)
FluentRule::numeric()->digits(4)->digitsBetween(4, 6)->minDigits(3)->maxDigits(5)->multipleOf(5)
FluentRule::numeric()->greaterThan('field')->lessThan('field')  // also: greaterThanOrEqualTo(), lessThanOrEqualTo()
```

### Date

Boundaries, shortcuts, and format control. All comparison methods accept `DateTimeInterface|string`:

```php
FluentRule::date()->after('today')->before('2025-12-31')->between('2025-01-01', '2025-12-31')
FluentRule::date()->afterToday()->future()->nowOrPast()  // also: beforeToday(), todayOrAfter(), past(), nowOrFuture()
FluentRule::date()->format('Y-m-d')->dateEquals('2025-06-15')
FluentRule::dateTime()->afterToday()                     // shortcut for format('Y-m-d H:i:s')
```

### Boolean

Acceptance and decline:

```php
FluentRule::boolean()->accepted()->declined()
FluentRule::boolean()->acceptedIf('role', 'admin')->declinedIf('type', 'free')
```

### Array

Size constraints, structure, and allowed keys:

```php
FluentRule::array()->min(1)->max(10)->between(1, 5)->exactly(3)->list()
FluentRule::array()->requiredArrayKeys('name', 'email')
FluentRule::array(['name', 'email'])  // restrict allowed keys
FluentRule::array(MyEnum::cases())    // BackedEnum keys
```

### File

Size and type constraints. Size methods accept integers (kilobytes) or human-readable strings:

```php
FluentRule::file()->max('5mb')->between('1mb', '10mb')
FluentRule::file()->extensions('pdf', 'docx')->mimes('jpg', 'png')->mimetypes('application/pdf')
```

### Image

Dimension constraints. Inherits all file methods:

```php
FluentRule::image()->max('5mb')->allowSvg()
FluentRule::image()->minWidth(100)->maxWidth(1920)->minHeight(100)->maxHeight(1080)
FluentRule::image()->width(800)->height(600)->ratio(16 / 9)
```

### Field (untyped)

For fields that need modifiers but no type constraint:

```php
FluentRule::field()->present()
FluentRule::field()->requiredIf('type', 'special')
FluentRule::field('Answer')->nullable()->in(['yes', 'no'])
```

### AnyOf

A value passes if it matches any of the given rule sets. Requires Laravel 13+.

```php
FluentRule::anyOf([
    FluentRule::string()->required()->min(2),
    FluentRule::numeric()->required()->integer(),
])
```

### Embedded rules

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

### Field modifiers

Shared by all rule types:

```php
// Presence
->required()  ->nullable()  ->sometimes()  ->filled()  ->present()  ->missing()

// Conditional presence: accepts field references or Closure|bool
->requiredIf('role', 'admin')  ->requiredUnless('type', 'guest')  ->requiredIf(fn () => $cond)
->requiredWith('field')  ->requiredWithAll('a', 'b')  ->requiredWithout('field')  ->requiredWithoutAll('a', 'b')

// Prohibition & exclusion
->prohibited()  ->prohibitedIf('field', 'val')  ->prohibitedUnless('field', 'val')  ->prohibits('other')
->exclude()  ->excludeIf('field', 'val')  ->excludeUnless('field', 'val')  ->excludeWith('f')  ->excludeWithout('f')

// Messages
->label('Name')  ->message('Rule-specific error')  ->fieldMessage('Field-level fallback')

// Other
->bail()  ->rule($stringOrObjectOrArray)  ->whenInput($condition, $then, $else?)
```

> To exclude a field from `validated()` output, place `exclude` alongside the fluent rule: `'field' => ['exclude', FluentRule::string()]`

### Conditional rules

All rule types use Laravel's `Conditionable` trait:

```php
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

### Escape hatch

Add any Laravel validation rule with `rule()`. Accepts strings, objects, and array tuples:

```php
FluentRule::string()->rule('email:rfc,dns')
FluentRule::string()->rule(new MyCustomRule())
FluentRule::file()->rule(['mimetypes', ...$acceptedTypes])
```

### Macros

Define reusable rule chains in a service provider:

```php
// In a service provider
NumericRule::macro('percentage', fn () => $this->integer()->min(0)->max(100));
StringRule::macro('slug', fn () => $this->alpha(true)->lowercase());

// Then use anywhere
FluentRule::numeric()->percentage()
FluentRule::string()->slug()
```

## Troubleshooting

**`validated()` is missing nested keys (children, each)**
Add `use HasFluentRules` to your FormRequest. Without the trait, FluentRule objects self-validate in isolation and nested keys don't appear in `validated()` output.

**Labels not working ("The name field" instead of "The Full Name field")**
Add `use HasFluentRules`. The trait extracts labels from rule objects and passes them to the validator. Without it, labels are only used inside the rule's self-validation.

**Cross-field wildcard references don't work (`requiredUnless('items.*.type', ...)`)**
These require `HasFluentRules` or `FluentValidator` to resolve wildcard paths. Standalone FluentRule objects self-validate in isolation.

**`mergeRecursive` breaks rules in child FormRequests**
PHP's `mergeRecursive` deconstructs objects into arrays. Use `(clone $parentRule)->rule(...)` to augment or `[...parent::rules(), 'field' => ...]` to override. See [Extending parent rules](#extending-parent-rules-in-child-formrequests).

**Method not found on a rule type**
Use `->rule('method_name')` as an escape hatch for any Laravel rule not yet available as a fluent method. Accepts strings, objects, and `['rule', ...$params]` tuples.

**`HasFluentValidation` conflicts with Filament's `InteractsWithSchemas`**
Both traits define `validate()`. For Filament components, use `RuleSet::compileToArrays()` instead of the trait: `$this->validate(RuleSet::compileToArrays($this->rules()))`. This returns `array<string, array<mixed>>` matching Livewire's expected type, so PHPStan is happy. FluentRule works correctly without the trait for simple rules.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Sander Muller](https://github.com/sandermuller)
- [All Contributors](../../contributors)

## License

MIT License. Please see [License File](LICENSE.md) for more information.
