# Fluent validation rule builders for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sandermuller/laravel-fluent-validation.svg?style=flat-square)](https://packagist.org/packages/sandermuller/laravel-fluent-validation)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/sandermuller/laravel-fluent-validation/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/sandermuller/laravel-fluent-validation/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/sandermuller/laravel-fluent-validation/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/sandermuller/laravel-fluent-validation/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)

Type-safe, fluent validation rule builders for Laravel. Write validation rules with full IDE autocompletion instead of memorizing string syntax.

```php
// Before
['name' => 'required|string|min:2|max:255']

// After
['name' => FluentRule::string()->required()->min(2)->max(255)]
```

## Installation

```bash
composer require sandermuller/laravel-fluent-validation
```

Requires PHP 8.2+ and Laravel 11+.

## Quick start

### In a FormRequest

```php
use Illuminate\Foundation\Http\FormRequest;
use SanderMuller\FluentValidation\FluentRule;

class StorePostRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title'  => FluentRule::string()->required()->min(2)->max(255),
            'body'   => FluentRule::string()->required(),
            'status' => FluentRule::string()->required()->in(['draft', 'published']),
        ];
    }
}
```

### With `Validator::make()`

```php
use SanderMuller\FluentValidation\FluentRule;

$validator = Validator::make($request->all(), [
    'name'  => FluentRule::string()->required()->min(2)->max(255),
    'email'  => FluentRule::email()->required()->max(255),
    'age'    => FluentRule::numeric()->nullable()->integer()->min(0),
    'date'   => FluentRule::date()->required()->afterToday(),
    'agree'  => FluentRule::boolean()->accepted(),
    'tags'   => FluentRule::array()->required()->min(1)->max(10),
    'avatar' => FluentRule::image()->nullable()->max('2mb'),
]);
```

These rule objects implement Laravel's `ValidationRule` interface and work anywhere Laravel expects validation rules -- in Form Requests, `Validator::make()`, `Rule::forEach()`, and `Rule::when()`.

### Gradual adoption

Fluent rules can be mixed freely with string rules and native rule objects in the same array. Adopt one field at a time:

```php
$rules = [
    'name'   => FluentRule::string()->required()->min(2)->max(255),  // fluent
    'email'  => 'required|string|email|max:255',               // string — still works
    'role'   => ['required', LaravelRule::in(['admin', 'user'])],  // array — still works
];
```

### Relationship with Laravel's `Rule` class

`FluentRule` is intentionally named differently from `Illuminate\Validation\Rule` so both can be used without aliasing. You generally don't need Laravel's `Rule` at all — `FluentRule` provides fluent alternatives for the most common use cases:

| Laravel's `Rule`                              | FluentRule equivalent                                     |
|-----------------------------------------------|-----------------------------------------------------------|
| `Rule::forEach(fn () => ...)`                 | `FluentRule::array()->each(...)`                          |
| `Rule::when($cond, $rules, $default)`         | `->when($cond, fn ($r) => ..., fn ($r) => ...)`           |
| `Rule::unique('users')`                       | `FluentRule::string()->unique('users')`                   |
| `Rule::exists('roles')`                       | `FluentRule::string()->exists('roles')`                   |
| `Rule::in([...])`                             | `FluentRule::string()->in([...])`                         |
| `Rule::enum(Status::class)`                   | `FluentRule::string()->enum(Status::class)`               |
| `Rule::anyOf([...])`                          | `FluentRule::anyOf([...])`                                |

## Available rules

### String rules

```php
// Length
FluentRule::string()->min(2)->max(255)
FluentRule::string()->between(2, 255)
FluentRule::string()->exactly(10)

// Pattern
FluentRule::string()->alpha()                      // also: alphaDash(), alphaNumeric()
FluentRule::string()->alpha(ascii: true)           // ASCII-only variant (also on alphaDash, alphaNumeric)
FluentRule::string()->ascii()
FluentRule::string()->regex('/^[A-Z]+$/')
FluentRule::string()->notRegex('/\d/')

// Starts/ends
FluentRule::string()->startsWith('prefix_')        // also: doesntStartWith()
FluentRule::string()->endsWith('.txt')             // also: doesntEndWith()

// Case
FluentRule::string()->lowercase()
FluentRule::string()->uppercase()

// Format
FluentRule::string()->url()                        // also: activeUrl()
FluentRule::string()->uuid()                       // also: ulid()
FluentRule::string()->json()
FluentRule::string()->ip()                         // also: ipv4(), ipv6()
FluentRule::string()->macAddress()
FluentRule::string()->timezone()
FluentRule::string()->hexColor()
FluentRule::string()->email()                        // also: email('rfc', 'dns')
FluentRule::string()->date()                         // also: dateFormat('d/m/Y')

// Comparison
FluentRule::string()->confirmed()                  // requires {field}_confirmation
FluentRule::string()->currentPassword()            // optionally: currentPassword('api')
FluentRule::string()->same('other_field')
FluentRule::string()->different('other_field')
FluentRule::string()->inArray('allowed_values.*')  // also: inArrayKeys()
FluentRule::string()->distinct()                   // optionally: distinct('strict')
```

### Email rules

```php
FluentRule::email()->required()->max(255)

// Validation modes
FluentRule::email()->rfcCompliant()                  // RFC 5321
FluentRule::email()->strict()                        // strict RFC (no warnings)
FluentRule::email()->validateMxRecord()              // check DNS MX record
FluentRule::email()->preventSpoofing()               // prevent unicode spoofing
FluentRule::email()->withNativeValidation()           // PHP filter_var
FluentRule::email()->withNativeValidation(allowUnicode: true)

// Combine modes
FluentRule::email()->rfcCompliant()->preventSpoofing()->validateMxRecord()

// Also supports: confirmed(), same(), different(), unique(), exists()
FluentRule::email()->required()->unique('users', 'email')
```

> **Tip:** `FluentRule::string()->email()` is also available if you prefer keeping email as a string modifier — it accepts mode strings like `email('rfc', 'dns')`.

### Password rules

```php
FluentRule::password()->required()
FluentRule::password(min: 12)->required()             // custom minimum length

// Strength requirements
FluentRule::password()->letters()                     // at least one letter
FluentRule::password()->mixedCase()                   // upper and lowercase
FluentRule::password()->numbers()                     // at least one number
FluentRule::password()->symbols()                     // at least one symbol
FluentRule::password()->max(255)

// Combine them
FluentRule::password()->required()->letters()->mixedCase()->numbers()->symbols()

// Check against breached password databases
FluentRule::password()->required()->uncompromised()
```

### Numeric rules

```php
// Type
FluentRule::numeric()->integer()                   // also: integer(strict: true)
FluentRule::numeric()->decimal(2)                  // exact places; also: decimal(1, 3) for range

// Size
FluentRule::numeric()->min(0)->max(100)
FluentRule::numeric()->between(1.5, 99.9)
FluentRule::numeric()->exactly(42)

// Digits
FluentRule::numeric()->digits(4)
FluentRule::numeric()->digitsBetween(4, 6)
FluentRule::numeric()->minDigits(3)
FluentRule::numeric()->maxDigits(5)
FluentRule::numeric()->multipleOf(5)

// Field comparison
FluentRule::numeric()->greaterThan('other_field')  // also: greaterThanOrEqualTo()
FluentRule::numeric()->lessThan('other_field')     // also: lessThanOrEqualTo()
FluentRule::numeric()->confirmed()                 // also: same(), different(), inArray(), distinct()
```

### Date rules

All date comparison methods accept `DateTimeInterface|string`:

```php
// Boundaries
FluentRule::date()->after('today')
FluentRule::date()->before('2025-12-31')
FluentRule::date()->beforeOrEqual('2025-12-31')    // also: afterOrEqual()
FluentRule::date()->between('2025-01-01', '2025-12-31')
FluentRule::date()->betweenOrEqual('2025-01-01', '2025-12-31')
FluentRule::date()->dateEquals('2025-06-15')

// Convenience
FluentRule::date()->afterToday()                   // also: beforeToday(), todayOrAfter(), todayOrBefore()
FluentRule::date()->future()                       // also: past(), nowOrFuture(), nowOrPast()

// Format and comparison
FluentRule::date()->format('Y-m-d')
FluentRule::date()->same('other_date')             // also: different()

// DateTime shortcut (format: Y-m-d H:i:s)
FluentRule::dateTime()->afterToday()

// DateTimeInterface objects
FluentRule::date()->after(now()->addDays(7))
```

### Boolean rules

```php
FluentRule::boolean()->accepted()
FluentRule::boolean()->acceptedIf('role', 'admin')
FluentRule::boolean()->declined()
FluentRule::boolean()->declinedIf('type', 'free')
```

### Array rules

```php
FluentRule::array()->min(1)->max(10)
FluentRule::array()->between(1, 5)
FluentRule::array()->exactly(3)
FluentRule::array()->list()
FluentRule::array()->requiredArrayKeys('name', 'email')
FluentRule::array(['name', 'email'])               // restrict allowed keys
FluentRule::array(MyEnum::cases())                 // BackedEnum keys
```

### File rules

```php
// Size (accepts integers in KB, or human-readable strings)
FluentRule::file()->min(100)->max(2048)
FluentRule::file()->max('5mb')
FluentRule::file()->between('1mb', '10mb')
FluentRule::file()->exactly(512)

// Type restrictions
FluentRule::file()->extensions('pdf', 'docx')
FluentRule::file()->mimes('jpg', 'png', 'pdf')
FluentRule::file()->mimetypes('application/pdf', 'image/jpeg')
```

### Image rules

```php
FluentRule::image()->required()->max('5mb')
FluentRule::image()->allowSvg()

// Dimensions
FluentRule::image()->minWidth(100)->maxWidth(1920)
FluentRule::image()->minHeight(100)->maxHeight(1080)
FluentRule::image()->width(800)->height(600)           // exact dimensions
FluentRule::image()->ratio(16 / 9)

// Inherits all file methods
FluentRule::image()->extensions('jpg', 'png')->max('10mb')

// Pass a Laravel Dimensions instance directly
use Illuminate\Validation\Rules\Dimensions;
FluentRule::image()->dimensions(new Dimensions(['min_width' => 100, 'ratio' => 1.0]))
```

### Embedded rules

String, numeric, and date rules support embedded Laravel rules:

```php
FluentRule::string()->in(['draft', 'published', 'archived'])
FluentRule::string()->notIn(['banned', 'deleted'])
FluentRule::string()->enum(StatusEnum::class)
FluentRule::string()->enum(StatusEnum::class, fn ($e) => $e->only(StatusEnum::Active))
FluentRule::string()->unique('users', 'email')
FluentRule::string()->exists('roles', 'name')
```

### Field modifiers

All rule types share common field modifiers:

```php
// Presence
FluentRule::string()->required()
FluentRule::string()->nullable()
FluentRule::string()->sometimes()
FluentRule::string()->filled()
FluentRule::string()->present()
FluentRule::string()->missing()

// Prohibition
FluentRule::string()->prohibited()
FluentRule::string()->prohibitedIf('status', 'archived')
FluentRule::string()->prohibitedUnless('status', 'active')
FluentRule::string()->prohibits('other_field')

// Exclusion
FluentRule::string()->exclude()
FluentRule::string()->excludeIf('type', 'internal')
FluentRule::string()->excludeUnless('type', 'public')
FluentRule::string()->excludeWith('other_field')
FluentRule::string()->excludeWithout('other_field')

// Other
FluentRule::string()->bail()
```

Conditional required modifiers accept either a field reference or a closure/boolean:

```php
FluentRule::string()->requiredIf('role', 'admin')
FluentRule::string()->requiredIf(fn () => $user->isAdmin())
FluentRule::string()->requiredUnless('type', 'guest')
FluentRule::string()->requiredWith('first_name', 'last_name')
FluentRule::string()->requiredWithAll('first_name', 'last_name')
FluentRule::string()->requiredWithout('nickname')
FluentRule::string()->requiredWithoutAll('first_name', 'last_name')
```

> **Note:** `exclude`, `exclude_if`, etc. only affect `validated()` output when placed at the outer validator level. To exclude a field from validated data, place `exclude` alongside the fluent rule:
>
> ```php
> // Correct — field excluded from validated():
> 'internal_id' => ['exclude', FluentRule::string()]
> ```

### Conditional rules

All rule types use Laravel's `Conditionable` trait:

```php
FluentRule::string()
    ->required()
    ->when($isAdmin, fn ($rule) => $rule->min(12))
    ->max(255)

// With an else branch (third argument)
FluentRule::string()->when(
    $isAdmin,
    fn ($rule) => $rule->required()->min(12),
    fn ($rule) => $rule->sometimes()->max(100),
)
```

### Escape hatch

Add any validation rule (string or object) via `rule()`:

```php
FluentRule::string()->required()->rule('email:rfc,dns')
FluentRule::string()->required()->rule(new MyCustomRule())
```

### Macros

All rule types use Laravel's `Macroable` trait, so you can add reusable methods:

```php
StringRule::macro('slug', function () {
    return $this->alpha(true)->lowercase();
});

FluentRule::string()->slug()
```

## Error messages

### Labels

Pass a label to the factory method and every error message automatically uses it as the `:attribute` name:

```php
return [
    'name'  => FluentRule::string('Full Name')->required()->min(2)->max(255),
    'email' => FluentRule::string('Email Address')->required()->rule('email'),
    'age'   => FluentRule::numeric('Your Age')->nullable()->integer()->min(0),
    'items' => FluentRule::array(label: 'Import Items')->required()->min(1),
];
// "The Full Name field is required."
// "The Email Address field must be a valid email address."
// "The Import Items field must have at least 1 items."
```

Labels work everywhere — in Form Requests, `Validator::make()`, and `RuleSet::validate()`. No separate `attributes()` array needed.

For `FluentRule::array()`, use the `label:` named parameter (since the first parameter is `$keys`). All other types accept the label as the first positional argument.

### Per-rule messages

Attach a custom message to the most recently added rule using `->message()`:

```php
FluentRule::string('Full Name')
    ->required()->message('We need your name!')
    ->min(2)->message('At least :min characters.')
    ->max(255)
```

Labels and messages compose naturally — labels improve ALL error messages for the field, while `->message()` overrides specific rules.

### Traditional approach

All standard Laravel approaches still work:

```php
// Validator::make() with messages array
$validator = Validator::make(
    ['name' => ''],
    ['name' => FluentRule::string()->required()->min(2)],
    ['name.required' => 'Please enter your name.'],
    ['name' => 'full name'],
);

// RuleSet::validate() with messages array
RuleSet::from([
    'name' => FluentRule::string()->required(),
])->validate(
    ['name' => ''],
    ['name.required' => 'Please provide your name.'],
    ['name' => 'full name'],
);
```

### With FormRequest

Use Laravel's standard `messages()` and `attributes()` methods, or combine with labels:

```php
class ImportRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'items' => FluentRule::array(label: 'Import Items')->required()->each([
                'name' => FluentRule::string('Item Name')->required()->min(2),
            ]),
        ];
    }

    // Optional — labels above already handle :attribute replacement.
    // Only needed for fully custom messages:
    public function messages(): array
    {
        return [
            'items.*.name.required' => 'Each item must have a name.',
        ];
    }

    public function attributes(): array
    {
        return [
            'items.*.name' => 'item name',
        ];
    }
}
```

## Performance

Laravel's wildcard validation (`items.*.name`) has [known O(n²) performance issues](https://github.com/laravel/framework/issues/49375) for large arrays. This package addresses them at multiple levels:

### The problem

With 500 items and 7 wildcard rules, native Laravel validation takes **~165ms**. With 100 items and 47 conditional rules (a real-world import validator), it takes **~3,000ms**. The bottleneck is `explodeWildcardRules()` flattening and `shouldBeExcluded()` scanning.

### The solution

`RuleSet::validate()` applies three optimizations automatically:

| Optimization | What it does | Speedup |
|---|---|---|
| **Per-item validation** | Reuses one small validator per item instead of one giant validator for all items | **~40x** for complex rules |
| **Compiled fast-checks** | Compiles string rules to native PHP closures, skipping Laravel entirely for valid items | **~77x** for simple rules |
| **Conditional rule rewriting** | Rewrites `exclude_unless` references to relative paths for per-item context | Enables per-item for real-world validators |

### How to use it

**Simple (works everywhere, no optimization):**

```php
public function rules(): array
{
    return [
        'items.*.name' => FluentRule::string()->required()->min(2),
    ];
}
```

**With `RuleSet::validate()` (full optimization):**

```php
$validated = RuleSet::from([
    'items' => FluentRule::array()->required()->each([
        'name' => FluentRule::string()->required()->min(2),
    ]),
])->validate($request->all());
```

**With `ExpandsWildcards` trait (full optimization in FormRequests):**

```php
use SanderMuller\FluentValidation\ExpandsWildcards;

class ImportRequest extends FormRequest
{
    use ExpandsWildcards;

    public function rules(): array
    {
        return [
            'items' => FluentRule::array()->required()->each([
                'name'  => FluentRule::string()->required()->min(2),
                'email' => FluentRule::string()->required()->rule('email'),
            ]),
        ];
    }
}
```

### Benchmarks

| Scenario | Native Laravel | RuleSet::validate() | Speedup |
|---|---|---|---|
| 500 items, 7 fields (string, numeric, date, boolean, in) | ~165ms | ~2.1ms | **77x** |
| 100 items, 47 fields with `exclude_unless` | ~3,000ms | ~76ms | **40x** |

Benchmarks run automatically on PRs via GitHub Actions.

## Array validation with `each()`

Define validation rules for array items using `each()`:

```php
// Scalar items
FluentRule::array()->each(FluentRule::string()->max(255))

// Object items with field mappings
FluentRule::array()->each([
    'name'  => FluentRule::string()->required(),
    'email' => FluentRule::string()->required(),
])

// Nested arrays
FluentRule::array()->each(
    FluentRule::array()->each(FluentRule::numeric()->min(0))
)
```

`each()` works both standalone (passed directly to a validator) and through `RuleSet`. When used through `RuleSet`, wildcard expansion is optimized for better performance on large datasets.

## RuleSet

`RuleSet` provides a structured way to define and validate complete rule sets with `each()` support for arrays:

```php
use SanderMuller\FluentValidation\RuleSet;

$validated = RuleSet::make()
    ->field('name', FluentRule::string()->required()->min(2)->max(255))
    ->field('email', FluentRule::string()->required()->max(255))
    ->field('items', FluentRule::array()->required()->each([
        'name'  => FluentRule::string()->required()->min(2),
        'price' => FluentRule::numeric()->required()->min(0),
    ]))
    ->validate($request->all());
```

### Methods

| Method | Returns | Description |
|---|---|---|
| `RuleSet::from([...])` | `RuleSet` | Create from a rules array |
| `RuleSet::make()->field(...)` | `RuleSet` | Fluent builder |
| `->toArray()` | `array` | Flat rules with `each()` expanded to wildcards |
| `->validate($data)` | `array` | Validate with full optimization (see [Performance](#performance)) |
| `->expandWildcards($data)` | `array` | Pre-expand wildcards without validating |
| `RuleSet::compile($rules)` | `array` | Compile fluent rules to native Laravel format |

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
