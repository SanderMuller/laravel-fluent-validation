# Fluent validation rule builders for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sandermuller/laravel-fluent-validation.svg?style=flat-square)](https://packagist.org/packages/sandermuller/laravel-fluent-validation)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/sandermuller/laravel-fluent-validation/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/sandermuller/laravel-fluent-validation/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/sandermuller/laravel-fluent-validation/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/sandermuller/laravel-fluent-validation/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)

Stop memorizing Laravel's 100+ validation rule strings. Write them fluently with full IDE autocompletion, type safety, and structure that mirrors your data.

```php
// Before
['name' => 'required|string|min:2|max:255']

// After
['name' => FluentRule::string('Full Name')->required()->min(2)->max(255)]
```

## Why this package?

**Better DX.** IDE autocompletion for every rule. No more guessing `required_with` vs `required_with_all`, or whether it's `digits_between` or `digitsBetween`. The method names tell you.

**Type-safe rule combinations.** Each rule type only exposes methods that make sense for it. `FluentRule::string()` doesn't have `digits()`, `FluentRule::numeric()` doesn't have `alpha()`. Incompatible combinations become impossible. Your IDE catches them before you run a single test.

**Co-located structure.** `each()` and `children()` keep parent and child rules together. No more maintaining 20 flat dot-notation keys that mirror a data structure. The rules mirror it directly.

**Inline error messages.** Labels and per-rule messages live right next to the rules they belong to. No more maintaining a separate `messages()` array that drifts out of sync.

**Faster array validation.** For large arrays (imports, bulk operations), this package replaces Laravel's O(n²) wildcard expansion with an O(n) approach. With `RuleSet::validate()`, per-item validation and compiled fast-checks push that to [up to 77x faster](#benchmarks).

## Installation

```bash
composer require sandermuller/laravel-fluent-validation
```

Requires PHP 8.2+ and Laravel 11+.

### AI-assisted development

This package ships with [Laravel Boost](https://github.com/laravel/boost) skills. If you use Boost, run `php artisan boost:update` to register them. AI assistants will automatically get the full FluentRule API reference when writing validation rules.

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

When you pass a label like `'Full Name'`, it automatically replaces `:attribute` in all error messages for that field. You get "The Full Name field is required" instead of "The name field is required". No separate `attributes()` array needed.

### In a Form Request

Add the `HasFluentRules` trait to your Form Requests. It compiles rules to native Laravel format, optimizes wildcard expansion, and extracts labels and messages automatically:

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

> **Tip:** `HasFluentRules` is recommended for all Form Requests using FluentRule. It has no downsides and ensures labels, messages, and wildcard rules all work correctly.

FluentRule objects implement Laravel's `ValidationRule` interface. They also work in `Validator::make()`, `Rule::forEach()`, and `Rule::when()`.

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

| Before | After |
|---|---|
| `'items.*.name' => 'required&#124;string'` | `FluentRule::array()->each(['name' => FluentRule::string()->required()])` |
| `'search' => 'array'` + `'search.value' => '...'` | `FluentRule::array()->children(['value' => ...])` |
| `Rule::in([...])` | `->in([...])` or `->in(MyEnum::class)` |
| `Rule::unique('users')` | `->unique('users')` |
| `Rule::forEach(fn () => ...)` | `FluentRule::array()->each(...)` |

**Things to know:**

- All conditional methods (`requiredIf`, `excludeUnless`, etc.) accept `string|int|bool` values.
- Cross-field wildcard references (`requiredUnless('items.*.type', ...)`) require `HasFluentRules` or `FluentValidator`. They don't work in standalone self-validation mode.
- `each()` and `children()` nest naturally. Flat dot-notation keys like `columns.*.data.sort` become nested `each([...children([...])])` trees that mirror the data shape.

### Relationship with Laravel's `Rule` class

`FluentRule` is intentionally named differently from `Illuminate\Validation\Rule` so both can be used without aliasing. You generally don't need Laravel's `Rule` at all:

| Laravel's `Rule`                              | FluentRule equivalent                                      |
|-----------------------------------------------|------------------------------------------------------------|
| `Rule::forEach(fn () => ...)`                 | `FluentRule::array()->each(...)`                           |
| `Rule::when($cond, $rules, $default)`         | `->when($cond, fn ($r) => ..., fn ($r) => ...)`            |
| `Rule::unique('users')`                       | `FluentRule::string()->unique('users')`                    |
| `Rule::exists('roles')`                       | `FluentRule::string()->exists('roles')`                    |
| `Rule::in([...])`                             | `FluentRule::string()->in([...])`                          |
| `Rule::enum(Status::class)`                   | `FluentRule::string()->enum(Status::class)`                |
| `Rule::anyOf([...])`                          | `FluentRule::anyOf([...])`                                 |
| No equivalent                                 | `->each([...])` co-locate wildcard child rules             |
| No equivalent                                 | `->children([...])` co-locate fixed-key child rules        |
| No equivalent                                 | `->label('Name')` / `->message('...')` inline messages     |
| No equivalent                                 | `->whenInput(fn ($input) => ...)` data-dependent conditions|
| No equivalent                                 | `HasFluentRules` automatic compile + expand optimization   |
| No equivalent                                 | `FluentValidator` base class for custom Validators         |

## Error messages

### Labels

Pass a label to the factory method and every error message automatically uses it as the `:attribute` name:

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

Labels work in Form Requests, `Validator::make()`, and `RuleSet::validate()`. You may also set a label after construction using `->label('Name')`.

### Per-rule messages

You may attach a custom error message to the most recently added rule using `->message()`:

```php
FluentRule::string('Full Name')
    ->required()->message('We need your name!')
    ->min(2)->message('At least :min characters.')
    ->max(255)
```

Labels and messages compose naturally. Labels improve ALL error messages for the field, while `->message()` overrides specific rules. For a field-level fallback that applies to any failure, use `->fieldMessage()`:

```php
FluentRule::string()->required()->min(2)->fieldMessage('Something is wrong with this field.')
```

> **Note:** Standard Laravel `messages()` arrays and `Validator::make()` message arguments still work and take priority over `->message()` and `->fieldMessage()`.

## Array validation with `each()` and `children()`

When validating arrays of items, you may define the rules for each item inline using `each()`:

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

`each()` works both standalone (passed directly to a validator) and through Form Requests with `HasFluentRules`. Wildcard expansion is automatically optimized when using the trait or `RuleSet`.

### Fixed-key children with `children()`

For objects with known keys (not wildcard arrays), you may use `children()` to co-locate the child rules with the parent:

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

FluentRule objects compile to native Laravel format (pipe-delimited strings or arrays) before validation runs. There is zero runtime overhead compared to writing string rules by hand.

When validating large arrays with wildcard rules (`items.*.name`), the `HasFluentRules` trait replaces Laravel's [O(n²) wildcard expansion](https://github.com/laravel/framework/issues/49375) with an O(n) tree traversal. The bigger your arrays, the more this matters.

### `RuleSet::validate()`

For the fastest possible validation (batch imports, bulk APIs), `RuleSet::validate()` goes further. It adds per-item validation and compiled fast-checks on top of the wildcard optimization:

```php
$validated = RuleSet::from([
    'items' => FluentRule::array()->required()->each([
        'name' => FluentRule::string('Item Name')->required()->min(2),
        'qty'  => FluentRule::numeric()->required()->integer()->min(1),
    ]),
])->validate($request->all());
```

Instead of building one giant validator for all items, it reuses a small validator per item. For simple rules (required, string, numeric, min, max, in), it compiles native PHP closures that skip Laravel entirely for valid items.

### Benchmarks

| Scenario | Native Laravel | HasFluentRules | RuleSet::validate() |
|---|---|---|---|
| 500 items, 7 fields | ~165ms | ~135ms | **~2.1ms (77x)** |
| 100 items, 47 fields with `exclude_unless` | ~3,000ms | ~2,400ms | **~76ms (40x)** |

Benchmarks run automatically on PRs via GitHub Actions.

## RuleSet

`RuleSet` provides a structured way to define and validate complete rule sets. You may create one from an array of rules or build it fluently:

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

| Method | Returns | Description |
|---|---|---|
| `RuleSet::from([...])` | `RuleSet` | Create from a rules array |
| `RuleSet::make()->field(...)` | `RuleSet` | Fluent builder |
| `->merge($ruleSet)` | `RuleSet` | Merge another RuleSet or array into this one |
| `->when($cond, $callback)` | `RuleSet` | Conditionally add fields (also: `unless`) |
| `->toArray()` | `array` | Flat rules with `each()` expanded to wildcards |
| `->validate($data)` | `array` | Validate with full optimization (see [Performance](#performance)) |
| `->prepare($data)` | `PreparedRules` | Expand, extract metadata, compile. For custom Validators |
| `->expandWildcards($data)` | `array` | Pre-expand wildcards without validating |
| `RuleSet::compile($rules)` | `array` | Compile fluent rules to native Laravel format |

### Using with custom Validators

If you extend `Illuminate\Validation\Validator` directly (e.g., for import jobs), extend `FluentValidator` instead. It handles the full pipeline automatically:

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

`FluentValidator` resolves the translator and presence verifier from the container, calls `prepare()` on the rules, and sets implicit attributes. No manual wiring needed.

> **Note:** When rules reference other fields using wildcards (e.g., `requiredUnless('*.type', ...)`), `FluentValidator` and `HasFluentRules` handle this automatically. Standalone FluentRule objects self-validate in isolation and can't resolve cross-field wildcard references.

> **Tip:** For validators with many cross-field references using a dynamic prefix, a simple helper reduces repetition:
>
> ```php
> protected function ref(string ...$parts): string
> {
>     return $this->prefix . '*.' . implode('.', $parts);
> }
>
> // Then: ->excludeUnless($this->ref('type'), ...) instead of
> //       ->excludeUnless($this->prefix . '*.' . ExternalInteraction::TYPE, ...)
> ```

---

## Rule reference

### String

Validate string values with length, pattern, format, and comparison constraints:

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

Validate email addresses with configurable strictness:

```php
FluentRule::email()->rfcCompliant()->strict()->validateMxRecord()->preventSpoofing()
FluentRule::email()->withNativeValidation(allowUnicode: true)
FluentRule::email()->required()->unique('users', 'email')
```

> **Tip:** `FluentRule::string()->email()` is also available if you prefer keeping email as a string modifier.

### Password

Validate password strength with readable, chainable requirements:

```php
FluentRule::password(min: 12)->letters()->mixedCase()->numbers()->symbols()->uncompromised()
```

### Numeric

Validate numbers with type, size, digit, and comparison constraints:

```php
FluentRule::numeric()->integer(strict: true)->decimal(2)->min(0)->max(100)->between(1, 99)
FluentRule::numeric()->digits(4)->digitsBetween(4, 6)->minDigits(3)->maxDigits(5)->multipleOf(5)
FluentRule::numeric()->greaterThan('field')->lessThan('field')  // also: greaterThanOrEqualTo(), lessThanOrEqualTo()
```

### Date

Validate dates with boundaries, convenience shortcuts, and format control. All comparison methods accept `DateTimeInterface|string`:

```php
FluentRule::date()->after('today')->before('2025-12-31')->between('2025-01-01', '2025-12-31')
FluentRule::date()->afterToday()->future()->nowOrPast()  // also: beforeToday(), todayOrAfter(), past(), nowOrFuture()
FluentRule::date()->format('Y-m-d')->dateEquals('2025-06-15')
FluentRule::dateTime()->afterToday()                     // shortcut for format('Y-m-d H:i:s')
```

### Boolean

Validate boolean values and acceptance/decline:

```php
FluentRule::boolean()->accepted()->declined()
FluentRule::boolean()->acceptedIf('role', 'admin')->declinedIf('type', 'free')
```

### Array

Validate arrays with size constraints, structure requirements, and allowed keys:

```php
FluentRule::array()->min(1)->max(10)->between(1, 5)->exactly(3)->list()
FluentRule::array()->requiredArrayKeys('name', 'email')
FluentRule::array(['name', 'email'])  // restrict allowed keys
FluentRule::array(MyEnum::cases())    // BackedEnum keys
```

### File

Validate uploaded files with size and type constraints. Size methods accept integers (kilobytes) or human-readable strings:

```php
FluentRule::file()->max('5mb')->between('1mb', '10mb')
FluentRule::file()->extensions('pdf', 'docx')->mimes('jpg', 'png')->mimetypes('application/pdf')
```

### Image

Validate images with dimension constraints. Inherits all file methods:

```php
FluentRule::image()->max('5mb')->allowSvg()
FluentRule::image()->minWidth(100)->maxWidth(1920)->minHeight(100)->maxHeight(1080)
FluentRule::image()->width(800)->height(600)->ratio(16 / 9)
```

### Field (untyped)

When a field needs modifiers but no type constraint, you may use `FluentRule::field()`:

```php
FluentRule::field()->present()
FluentRule::field()->requiredIf('type', 'special')
FluentRule::field('Answer')->nullable()->in(['yes', 'no'])
```

### AnyOf

Validate that a value passes at least one of the given rule sets (Laravel's `Rule::anyOf` equivalent):

```php
FluentRule::anyOf([
    FluentRule::string()->required()->min(2),
    FluentRule::numeric()->required()->integer(),
])
```

### Embedded rules

String, numeric, and date rules support embedded Laravel rule objects for `in`, `unique`, `exists`, and `enum`. Both `in()` and `notIn()` accept either an array of values or a `BackedEnum` class:

```php
FluentRule::string()->in(['draft', 'published'])
FluentRule::string()->in(StatusEnum::class)          // all enum values
FluentRule::string()->notIn(DeprecatedStatus::class)
FluentRule::string()->enum(StatusEnum::class)
FluentRule::string()->unique('users', 'email')
FluentRule::string()->exists('roles', 'name')
```

### Field modifiers

All rule types share common modifiers for controlling field presence, prohibition, and exclusion:

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

> **Note:** `exclude` rules only affect `validated()` output when placed at the outer validator level. To exclude a field from validated data, place `exclude` alongside the fluent rule: `'field' => ['exclude', FluentRule::string()]`

### Conditional rules

All rule types use Laravel's `Conditionable` trait, so you may conditionally apply rules using `when()`:

```php
FluentRule::string()->required()->when($isAdmin, fn ($r) => $r->min(12))->max(255)
```

For data-dependent conditions that need to inspect the input at validation time, you may use `whenInput()`:

```php
FluentRule::string()->whenInput(
    fn ($input) => $input->role === 'admin',
    fn ($r) => $r->required()->min(12),
    fn ($r) => $r->sometimes()->max(100),
)
```

The condition closure receives the full input as a `Fluent` object and is evaluated during validation, not at build time. You may also pass string rules instead of closures: `->whenInput($condition, 'required|min:12')`.

### Escape hatch

You may add any Laravel validation rule via `rule()`. Accepts strings, objects, and array tuples:

```php
FluentRule::string()->rule('email:rfc,dns')
FluentRule::string()->rule(new MyCustomRule())
FluentRule::file()->rule(['mimetypes', ...$acceptedTypes])
```

### Macros

Macros let you create reusable rule chains that can be shared across fields and files:

```php
// In a service provider
NumericRule::macro('percentage', fn () => $this->integer()->min(0)->max(100));
StringRule::macro('slug', fn () => $this->alpha(true)->lowercase());

// Then use anywhere
FluentRule::numeric()->percentage()
FluentRule::string()->slug()
```

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

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
