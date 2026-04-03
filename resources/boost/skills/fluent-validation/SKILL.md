---
name: fluent-validation
description: "Use when writing or modifying Laravel validation rules. Provides fluent rule builders via SanderMuller\\FluentValidation\\Rule instead of string-based or array-based validation rules."
---

# Fluent Validation Rules

When `sandermuller/laravel-fluent-validation` is installed, use the `Rule` factory class for type-safe, fluent validation rule building instead of string-based or array-based Laravel validation rules.

## Entry Point

```php
use SanderMuller\FluentValidation\Rule;
use SanderMuller\FluentValidation\RuleSet;
```

## Available Rule Types

| Factory Method     | Returns        | Base Laravel Rule |
|--------------------|----------------|-------------------|
| `Rule::string()`   | `StringRule`   | `'string'`        |
| `Rule::numeric()`  | `NumericRule`  | `'numeric'`       |
| `Rule::date()`     | `DateRule`     | `'date'`          |
| `Rule::dateTime()` | `DateRule`     | `'date_format:Y-m-d H:i:s'` |
| `Rule::boolean()`  | `BooleanRule`  | `'boolean'`       |
| `Rule::array()`    | `ArrayRule`    | `'array'`         |

## Usage Tiers

### Tier 1: Plain array (simplest, no RuleSet needed)

`each()` works directly in plain arrays — no RuleSet wrapper required:

```php
public function rules(): array
{
    return [
        'name' => Rule::string()->required()->min(2)->max(255),
        'age' => Rule::numeric()->nullable()->integer()->min(0),
        'role' => Rule::string()->required()->in(['admin', 'editor', 'viewer']),
        'starts_at' => Rule::date()->required()->after('today'),
        'tags' => Rule::array()->required()->each(Rule::string()->max(50)),
        'items' => Rule::array()->required()->each([
            'name' => Rule::string()->required(),
            'qty' => Rule::numeric()->required()->integer()->min(1),
        ]),
        'accept_tos' => Rule::boolean()->accepted(),
    ];
}
```

### Tier 2: RuleSet with each() for nested arrays

Use `each()` on ArrayRule to co-locate child rules with the parent array:

```php
public function rules(): array
{
    return RuleSet::from([
        'name' => Rule::string()->required()->min(2),
        'items' => Rule::array()->required()->each([
            'name' => Rule::string()->required(),
            'qty' => Rule::numeric()->required()->integer()->min(1),
        ]),
        'tags' => Rule::array()->each(Rule::string()->max(50)),
    ])->toArray();
}
```

### Tier 3: Optimized wildcard validation for large arrays

For form requests with large arrays, add `ExpandsWildcards` to bypass Laravel's O(n²) wildcard expansion:

```php
use SanderMuller\FluentValidation\ExpandsWildcards;

class ImportRequest extends FormRequest
{
    use ExpandsWildcards;

    public function rules(): array
    {
        return [
            'items' => Rule::array()->required()->each([
                'name' => Rule::string()->required()->min(2),
                'email' => Rule::string()->required()->rule('email'),
            ]),
        ];
    }
}
```

For inline validation, use `RuleSet::validate()` directly:

```php
$validated = RuleSet::from([
    'items' => Rule::array()->required()->each([
        'name' => Rule::string()->required(),
    ]),
])->validate($request->all());
```

## Important Behavior

- **Optional by default**: Fields without a presence modifier (`required()`, `nullable()`, etc.) are optional — absent fields pass validation, but present fields are still validated against the rules.
- **Not every Laravel rule has a fluent method**: Use `rule()` as an escape hatch for any rule without a dedicated method (e.g. `email`, `file`, `image`, `mimes`, `password`):

```php
Rule::string()->required()->rule('email')->unique('users')
```

## Field Modifiers (all rule types)

Presence:
- `required()`, `nullable()`, `sometimes()`, `filled()`, `present()`, `missing()`
- `requiredIf($field, ...$values)` — also accepts `Closure|bool`: `requiredIf(fn () => true)`, `requiredIf(true)`
- `requiredUnless($field, ...$values)` — also accepts `Closure|bool`
- `requiredWith(...$fields)`, `requiredWithAll(...$fields)`
- `requiredWithout(...$fields)`, `requiredWithoutAll(...$fields)`

Prohibition:
- `prohibited()`, `prohibits(...$fields)`
- `prohibitedIf($field, ...$values)` — also accepts `Closure|bool`
- `prohibitedUnless($field, ...$values)` — also accepts `Closure|bool`

Exclusion:
- `exclude()`
- `excludeIf($field, ...$values)`, `excludeUnless($field, ...$values)`
- `excludeWith($field)`, `excludeWithout($field)`

Other:
- `bail()` — stop on first failure
- `rule($rule)` — escape hatch to add any Laravel validation rule (string or `ValidationRule` object)

**Caveat: `exclude` and `validated()`.** Rules like `exclude`, `exclude_if`, etc. only affect `validated()` output when placed at the outer validator level. When used inside a fluent rule, they influence presence detection but do NOT exclude the field from `validated()`. To exclude a field, place `exclude` alongside the fluent rule:

```php
// Correct — field excluded from validated():
'internal_id' => ['exclude', Rule::string()]

// Does NOT exclude — exclude is inside the sub-validator:
'internal_id' => Rule::string()->exclude()
```

## Conditional Rule Building

All rule types use Laravel's `Conditionable` trait:

```php
Rule::string()->required()->when($isAdmin, fn ($r) => $r->min(12))->max(255)
```

## Macros

All rule types use Laravel's `Macroable` trait, so you can register reusable rule chains:

```php
StringRule::macro('slug', function () {
    return $this->alpha(true)->lowercase();
});

// Then use: Rule::string()->slug()
```

## Embedded Rules (string, numeric, date)

- `in($values)`, `notIn($values)`
- `unique($table, $column?)`, `exists($table, $column?)`
- `enum($class, $callback?)` — callback receives the `Illuminate\Validation\Rules\Enum` instance:

```php
Rule::string()->enum(StatusEnum::class, fn ($rule) => $rule->only(StatusEnum::Active, StatusEnum::Pending))
Rule::numeric()->enum(PriorityEnum::class, fn ($rule) => $rule->except(PriorityEnum::Deprecated))
```

## String-Specific Methods

- Length: `min($n)`, `max($n)`, `between($min, $max)`, `exactly($n)`
- Pattern: `alpha(ascii?)`, `alphaDash(ascii?)`, `alphaNumeric(ascii?)`, `ascii()`, `regex($p)`, `notRegex($p)` — pass `ascii: true` to restrict to ASCII characters
- Starts/ends: `startsWith(...$v)`, `endsWith(...$v)`, `doesntStartWith(...$v)`, `doesntEndWith(...$v)`
- Case: `lowercase()`, `uppercase()`
- Format: `url()`, `activeUrl()`, `uuid()`, `ulid()`, `json()`, `ip()`, `ipv4()`, `ipv6()`, `macAddress()`, `timezone()`, `hexColor()`
- Date: `date()`, `dateFormat($format)`
- Auth: `currentPassword($guard?)` — optionally specify auth guard
- Comparison: `confirmed()`, `same($field)`, `different($field)`, `inArray($field)`, `inArrayKeys($field)`, `distinct($mode?)` — mode can be `'strict'` or `'ignore_case'`

## Numeric-Specific Methods

- Type: `integer(strict?)`, `decimal($min, $max?)` — `integer(strict: true)` for strict type checking; `decimal(2)` for exact places, `decimal(1, 3)` for range
- Size: `min($n)`, `max($n)`, `between($min, $max)`, `exactly($n)`
- Digits: `digits($n)`, `digitsBetween($min, $max)`, `minDigits($n)`, `maxDigits($n)`
- Comparison: `greaterThan($field)`, `greaterThanOrEqualTo($field)`, `lessThan($field)`, `lessThanOrEqualTo($field)`, `multipleOf($n)`, `confirmed()`, `same($field)`, `different($field)`, `inArray($field)`, `inArrayKeys($field)`, `distinct($mode?)`

## Date-Specific Methods

All date comparison methods accept `DateTimeInterface|string`:

- Format: `format($format)`
- Today: `beforeToday()`, `afterToday()`, `todayOrBefore()`, `todayOrAfter()`
- Now: `past()`, `future()`, `nowOrPast()`, `nowOrFuture()`
- Compare: `before($date)`, `after($date)`, `beforeOrEqual($date)`, `afterOrEqual($date)`, `between($from, $to)`, `betweenOrEqual($from, $to)`, `dateEquals($date)`, `same($field)`, `different($field)`

## Boolean-Specific Methods

- `accepted()`, `acceptedIf($field, ...$values)`
- `declined()`, `declinedIf($field, ...$values)`

## Array-Specific Methods

- Size: `min($n)`, `max($n)`, `between($min, $max)`, `exactly($n)`
- Structure: `list()`, `requiredArrayKeys(...$keys)`
- Children: `each($rule)` for scalar items, `each([...])` for object items (see Tier 2 above)
- Constructor: `Rule::array(['name', 'email'])` — restrict allowed keys; accepts `BackedEnum` values
