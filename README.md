# Fluent validation rule builders for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sandermuller/laravel-fluent-validation.svg?style=flat-square)](https://packagist.org/packages/sandermuller/laravel-fluent-validation)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/sandermuller/laravel-fluent-validation/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/sandermuller/laravel-fluent-validation/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/sandermuller/laravel-fluent-validation/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/sandermuller/laravel-fluent-validation/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)

Type-safe, fluent validation rule builders for Laravel. Write validation rules with full IDE autocompletion instead of memorizing string syntax.

```php
// Before
['name' => 'required|string|min:2|max:255']

// After
['name' => Rule::string()->required()->min(2)->max(255)]
```

## Installation

```bash
composer require sandermuller/laravel-fluent-validation
```

Requires PHP 8.2+ and Laravel 11+.

## Usage

### Basic rules

Use `Rule::string()`, `Rule::numeric()`, `Rule::date()`, `Rule::boolean()`, and `Rule::array()` to create typed rule builders:

```php
use SanderMuller\FluentValidation\Rule;

$rules = [
    'name'  => Rule::string()->required()->min(2)->max(255),
    'email' => Rule::string()->required()->max(255),
    'age'   => Rule::numeric()->nullable()->integer()->min(0),
    'date'  => Rule::date()->required()->afterToday(),
    'agree' => Rule::boolean()->accepted(),
    'tags'  => Rule::array()->required()->min(1)->max(10),
];
```

These rule objects implement Laravel's `ValidationRule` interface and work anywhere Laravel expects validation rules -- in Form Requests, `Validator::make()`, `Rule::forEach()`, and `Rule::when()`.

### Field modifiers

All rule types share common field modifiers:

```php
// Presence
Rule::string()->required()
Rule::string()->nullable()
Rule::string()->sometimes()
Rule::string()->filled()
Rule::string()->present()
Rule::string()->missing()

// Prohibition
Rule::string()->prohibited()
Rule::string()->prohibitedIf('status', 'archived')
Rule::string()->prohibitedUnless('status', 'active')
Rule::string()->prohibits('other_field')

// Exclusion
Rule::string()->exclude()
Rule::string()->excludeIf('type', 'internal')
Rule::string()->excludeUnless('type', 'public')
Rule::string()->excludeWith('other_field')
Rule::string()->excludeWithout('other_field')

// Other
Rule::string()->bail()
```

Conditional required modifiers accept either a field reference or a closure/boolean:

```php
Rule::string()->requiredIf('role', 'admin')
Rule::string()->requiredIf(fn () => $user->isAdmin())
Rule::string()->requiredUnless('type', 'guest')
Rule::string()->requiredWith('first_name', 'last_name')
Rule::string()->requiredWithAll('first_name', 'last_name')
Rule::string()->requiredWithout('nickname')
Rule::string()->requiredWithoutAll('first_name', 'last_name')
```

> **Note:** `exclude`, `exclude_if`, etc. only affect `validated()` output when placed at the outer validator level. To exclude a field from validated data, place `exclude` alongside the fluent rule:
>
> ```php
> // Correct — field excluded from validated():
> 'internal_id' => ['exclude', Rule::string()]
> ```

### String rules

```php
// Length
Rule::string()->min(2)->max(255)
Rule::string()->between(2, 255)
Rule::string()->exactly(10)

// Pattern
Rule::string()->alpha()                      // also: alphaDash(), alphaNumeric()
Rule::string()->alpha(ascii: true)           // ASCII-only variant (also on alphaDash, alphaNumeric)
Rule::string()->ascii()
Rule::string()->regex('/^[A-Z]+$/')
Rule::string()->notRegex('/\d/')

// Starts/ends
Rule::string()->startsWith('prefix_')        // also: doesntStartWith()
Rule::string()->endsWith('.txt')             // also: doesntEndWith()

// Case
Rule::string()->lowercase()
Rule::string()->uppercase()

// Format
Rule::string()->url()                        // also: activeUrl()
Rule::string()->uuid()                       // also: ulid()
Rule::string()->json()
Rule::string()->ip()                         // also: ipv4(), ipv6()
Rule::string()->macAddress()
Rule::string()->timezone()
Rule::string()->hexColor()
Rule::string()->date()                       // also: dateFormat('d/m/Y')

// Comparison
Rule::string()->confirmed()                  // requires {field}_confirmation
Rule::string()->currentPassword()            // optionally: currentPassword('api')
Rule::string()->same('other_field')
Rule::string()->different('other_field')
Rule::string()->inArray('allowed_values.*')  // also: inArrayKeys()
Rule::string()->distinct()                   // optionally: distinct('strict')
```

### Numeric rules

```php
// Type
Rule::numeric()->integer()                   // also: integer(strict: true)
Rule::numeric()->decimal(2)                  // exact places; also: decimal(1, 3) for range

// Size
Rule::numeric()->min(0)->max(100)
Rule::numeric()->between(1.5, 99.9)
Rule::numeric()->exactly(42)

// Digits
Rule::numeric()->digits(4)
Rule::numeric()->digitsBetween(4, 6)
Rule::numeric()->minDigits(3)
Rule::numeric()->maxDigits(5)
Rule::numeric()->multipleOf(5)

// Field comparison
Rule::numeric()->greaterThan('other_field')  // also: greaterThanOrEqualTo()
Rule::numeric()->lessThan('other_field')     // also: lessThanOrEqualTo()
Rule::numeric()->confirmed()                 // also: same(), different(), inArray(), distinct()
```

### Date rules

All date comparison methods accept `DateTimeInterface|string`:

```php
// Boundaries
Rule::date()->after('today')
Rule::date()->before('2025-12-31')
Rule::date()->beforeOrEqual('2025-12-31')    // also: afterOrEqual()
Rule::date()->between('2025-01-01', '2025-12-31')
Rule::date()->betweenOrEqual('2025-01-01', '2025-12-31')
Rule::date()->dateEquals('2025-06-15')

// Convenience
Rule::date()->afterToday()                   // also: beforeToday(), todayOrAfter(), todayOrBefore()
Rule::date()->future()                       // also: past(), nowOrFuture(), nowOrPast()

// Format and comparison
Rule::date()->format('Y-m-d')
Rule::date()->same('other_date')             // also: different()

// DateTime shortcut (format: Y-m-d H:i:s)
Rule::dateTime()->afterToday()

// DateTimeInterface objects
Rule::date()->after(now()->addDays(7))
```

### Boolean rules

```php
Rule::boolean()->accepted()
Rule::boolean()->acceptedIf('role', 'admin')
Rule::boolean()->declined()
Rule::boolean()->declinedIf('type', 'free')
```

### Array rules

```php
Rule::array()->min(1)->max(10)
Rule::array()->between(1, 5)
Rule::array()->exactly(3)
Rule::array()->list()
Rule::array()->requiredArrayKeys('name', 'email')
Rule::array(['name', 'email'])               // restrict allowed keys
Rule::array(MyEnum::cases())                 // BackedEnum keys
```

### Embedded rules

String, numeric, and date rules support embedded Laravel rules:

```php
Rule::string()->in(['draft', 'published', 'archived'])
Rule::string()->notIn(['banned', 'deleted'])
Rule::string()->enum(StatusEnum::class)
Rule::string()->enum(StatusEnum::class, fn ($e) => $e->only(StatusEnum::Active))
Rule::string()->unique('users', 'email')
Rule::string()->exists('roles', 'name')
```

### Escape hatch

Add any validation rule (string or object) via `rule()`:

```php
Rule::string()->required()->rule('email:rfc,dns')
Rule::string()->required()->rule(new MyCustomRule())
```

### Conditional rules

All rule types use Laravel's `Conditionable` trait:

```php
Rule::string()
    ->required()
    ->when($isAdmin, fn ($rule) => $rule->min(12))
    ->max(255)
```

### Macros

All rule types use Laravel's `Macroable` trait, so you can add reusable methods:

```php
StringRule::macro('slug', function () {
    return $this->alpha(true)->lowercase();
});

Rule::string()->slug()
```

## RuleSet

`RuleSet` provides a structured way to define and validate complete rule sets with `each()` support for arrays:

```php
use SanderMuller\FluentValidation\RuleSet;

$validated = RuleSet::make()
    ->field('name', Rule::string()->required()->min(2)->max(255))
    ->field('email', Rule::string()->required()->max(255))
    ->field('items', Rule::array()->required()->each([
        'name'  => Rule::string()->required()->min(2),
        'price' => Rule::numeric()->required()->min(0),
    ]))
    ->validate($request->all());
```

### Array each() rules

Define validation rules for array items using `each()`:

```php
// Scalar items
Rule::array()->each(Rule::string()->max(255))

// Object items with field mappings
Rule::array()->each([
    'name'  => Rule::string()->required(),
    'email' => Rule::string()->required(),
])

// Nested arrays
Rule::array()->each(
    Rule::array()->each(Rule::numeric()->min(0))
)
```

`each()` works both standalone (passed directly to a validator) and through `RuleSet`. When used through `RuleSet`, wildcard expansion is optimized for better performance on large datasets.

### Wildcard expansion

`RuleSet` uses a custom `WildcardExpander` that resolves `*` patterns via direct tree traversal instead of Laravel's `Arr::dot()` + regex approach. This is significantly faster when validating large arrays with many wildcard rules.

```php
// Expands wildcards against data, returns concrete paths
$rules = RuleSet::from($rules)->expandWildcards($data);
// ['items.0.name' => StringRule, 'items.1.name' => StringRule, ...]
```

### FormRequest integration

Use the `ExpandsWildcards` trait in a Form Request to automatically optimize wildcard expansion:

```php
use Illuminate\Foundation\Http\FormRequest;
use SanderMuller\FluentValidation\ExpandsWildcards;
use SanderMuller\FluentValidation\Rule;

class ImportRequest extends FormRequest
{
    use ExpandsWildcards;

    public function rules(): array
    {
        return [
            'items' => Rule::array()->required()->each([
                'name' => Rule::string()->required()->min(2),
            ]),
        ];
    }
}
```

### Rule compilation

When rules contain only string constraints (no object rules like `In`, `Unique`, `RequiredIf` with closures), `RuleSet` compiles them to native Laravel format, eliminating the per-field validation wrapper overhead entirely. This happens automatically during `validate()`.

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
