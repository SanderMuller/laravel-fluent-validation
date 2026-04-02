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
Rule::string()->required()
Rule::string()->nullable()
Rule::string()->sometimes()
Rule::string()->bail()
Rule::string()->filled()
Rule::string()->present()
Rule::string()->prohibited()
Rule::string()->missing()
```

Conditional presence modifiers accept either a field reference or a closure/boolean:

```php
Rule::string()->requiredIf('role', 'admin')
Rule::string()->requiredIf(fn () => $user->isAdmin())
Rule::string()->requiredUnless('type', 'guest')
Rule::string()->requiredWith('first_name', 'last_name')
Rule::string()->requiredWithAll('first_name', 'last_name')
Rule::string()->requiredWithout('nickname')
Rule::string()->requiredWithoutAll('first_name', 'last_name')
Rule::string()->prohibitedIf('status', 'archived')
Rule::string()->prohibitedUnless('status', 'active')
Rule::string()->prohibits('other_field')
Rule::string()->excludeIf('type', 'internal')
Rule::string()->excludeUnless('type', 'public')
Rule::string()->excludeWith('other_field')
Rule::string()->excludeWithout('other_field')
```

### String rules

```php
Rule::string()->min(2)->max(255)
Rule::string()->between(2, 255)
Rule::string()->exactly(10)
Rule::string()->alpha()->lowercase()
Rule::string()->alpha(ascii: true)           // ASCII-only alpha
Rule::string()->alphaDash()                  // letters, numbers, dashes, underscores
Rule::string()->alphaNumeric()
Rule::string()->ascii()
Rule::string()->startsWith('prefix_')
Rule::string()->endsWith('.txt')
Rule::string()->doesntStartWith('temp_')
Rule::string()->doesntEndWith('.tmp')
Rule::string()->url()
Rule::string()->activeUrl()
Rule::string()->uuid()
Rule::string()->ulid()
Rule::string()->json()
Rule::string()->ip()                         // IPv4 or IPv6
Rule::string()->ipv4()
Rule::string()->ipv6()
Rule::string()->macAddress()
Rule::string()->timezone()
Rule::string()->hexColor()
Rule::string()->regex('/^[A-Z]+$/')
Rule::string()->notRegex('/\d/')
Rule::string()->date()                       // valid date string
Rule::string()->dateFormat('d/m/Y')
Rule::string()->confirmed()                  // requires {field}_confirmation
Rule::string()->currentPassword()            // optionally: currentPassword('api')
Rule::string()->same('other_field')
Rule::string()->different('other_field')
Rule::string()->inArray('allowed_values.*')
Rule::string()->inArrayKeys('options.*')
Rule::string()->distinct()                   // optionally: distinct('strict')
```

### Numeric rules

```php
Rule::numeric()->integer()->min(0)->max(100)
Rule::numeric()->integer(strict: true)       // strict integer type checking
Rule::numeric()->between(1.5, 99.9)
Rule::numeric()->decimal(2)                  // exactly 2 decimal places
Rule::numeric()->decimal(1, 3)               // 1 to 3 decimal places
Rule::numeric()->digits(4)
Rule::numeric()->digitsBetween(4, 6)
Rule::numeric()->minDigits(3)
Rule::numeric()->maxDigits(5)
Rule::numeric()->multipleOf(5)
Rule::numeric()->exactly(42)                 // exact integer value (size)
Rule::numeric()->greaterThan('other_field')
Rule::numeric()->greaterThanOrEqualTo('min_field')
Rule::numeric()->lessThan('other_field')
Rule::numeric()->lessThanOrEqualTo('max_field')
Rule::numeric()->confirmed()
Rule::numeric()->same('other_field')
Rule::numeric()->different('other_field')
Rule::numeric()->inArray('allowed.*')
Rule::numeric()->inArrayKeys('options.*')
Rule::numeric()->distinct()
```

### Date rules

```php
Rule::date()->after('today')
Rule::date()->before('2025-12-31')
Rule::date()->beforeOrEqual('2025-12-31')
Rule::date()->afterOrEqual('2025-01-01')
Rule::date()->between('2025-01-01', '2025-12-31')
Rule::date()->betweenOrEqual('2025-01-01', '2025-12-31')
Rule::date()->dateEquals('2025-06-15')
Rule::date()->format('Y-m-d')
Rule::date()->afterToday()
Rule::date()->beforeToday()
Rule::date()->todayOrAfter()
Rule::date()->todayOrBefore()
Rule::date()->future()                       // after now (datetime precision)
Rule::date()->past()                         // before now (datetime precision)
Rule::date()->nowOrFuture()
Rule::date()->nowOrPast()
Rule::date()->same('other_date')
Rule::date()->different('other_date')

// DateTime shortcut (format: Y-m-d H:i:s)
Rule::dateTime()->afterToday()

// Accepts DateTimeInterface objects
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
Rule::array(['name', 'email'])  // restrict allowed keys
Rule::array(MyEnum::cases())    // BackedEnum keys
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
Rule::numeric()->enum(PriorityEnum::class)
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
