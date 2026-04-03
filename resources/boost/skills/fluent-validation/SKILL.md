---
name: fluent-validation
description: "Use when writing or modifying Laravel validation rules. Provides fluent rule builders via SanderMuller\\FluentValidation\\FluentRule instead of string-based or array-based validation rules."
---

# Fluent Validation Rules

When `sandermuller/laravel-fluent-validation` is installed, use the `FluentRule` factory class for type-safe, fluent validation rule building instead of string-based or array-based Laravel validation rules.

## Entry Point

```php
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\RuleSet;
```

## Available Rule Types

| Factory Method     | Returns        | Base Laravel Rule |
|--------------------|----------------|-------------------|
| `FluentRule::string()`   | `StringRule`   | `'string'`        |
| `FluentRule::numeric()`  | `NumericRule`  | `'numeric'`       |
| `FluentRule::date()`     | `DateRule`     | `'date'`          |
| `FluentRule::dateTime()` | `DateRule`     | `'date_format:Y-m-d H:i:s'` |
| `FluentRule::boolean()`  | `BooleanRule`  | `'boolean'`       |
| `FluentRule::array()`    | `ArrayRule`    | `'array'`         |
| `FluentRule::email()`    | `EmailRule`    | `'string\|email'` |
| `FluentRule::password()` | `PasswordRule` | `'string'` + `Password` |
| `FluentRule::file()`     | `FileRule`     | `'file'`          |
| `FluentRule::image()`    | `ImageRule`    | `'image'`         |
| `FluentRule::field()`    | `FieldRule`    | (no type constraint) |
| `FluentRule::anyOf([...])` | `AnyOf`      | OR combinator     |

## Usage Tiers

### Tier 1: Plain array (simplest, no RuleSet needed)

`each()` works directly in plain arrays — no RuleSet wrapper required:

```php
public function rules(): array
{
    return [
        'name' => FluentRule::string()->required()->min(2)->max(255),
        'age' => FluentRule::numeric()->nullable()->integer()->min(0),
        'role' => FluentRule::string()->required()->in(['admin', 'editor', 'viewer']),
        'starts_at' => FluentRule::date()->required()->after('today'),
        'tags' => FluentRule::array()->required()->each(FluentRule::string()->max(50)),
        'items' => FluentRule::array()->required()->each([
            'name' => FluentRule::string()->required(),
            'qty' => FluentRule::numeric()->required()->integer()->min(1),
        ]),
        'accept_tos' => FluentRule::boolean()->accepted(),
    ];
}
```

### Tier 2: RuleSet with each() for nested arrays

Use `each()` on ArrayRule to co-locate child rules with the parent array:

```php
public function rules(): array
{
    return RuleSet::from([
        'name' => FluentRule::string()->required()->min(2),
        'items' => FluentRule::array()->required()->each([
            'name' => FluentRule::string()->required(),
            'qty' => FluentRule::numeric()->required()->integer()->min(1),
        ]),
        'tags' => FluentRule::array()->each(FluentRule::string()->max(50)),
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
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->min(2),
                'email' => FluentRule::email()->required(),
            ]),
        ];
    }
}
```

**RuleSet API:** `RuleSet::from([...])` creates from array, `RuleSet::make()->field('name', rule)` uses fluent builder. Methods: `->toArray()`, `->validate($data)`, `->expandWildcards($data)`, `RuleSet::compile($rules)`.

For inline validation, use `RuleSet::validate()` directly:

```php
$validated = RuleSet::from([
    'items' => FluentRule::array()->required()->each([
        'name' => FluentRule::string()->required(),
    ]),
])->validate($request->all());
```

## Labels and Messages

### Labels — factory argument

Pass a label to improve all error messages for the field:

```php
'name' => FluentRule::string('Full Name')->required()->min(2)
// "The Full Name field is required."
```

Works on all types: `string('Label')`, `numeric('Label')`, `date('Label')`, `boolean('Label')`, `email('Label')`, `file('Label')`, `image('Label')`. For arrays and passwords use the named parameter: `array(label: 'Items')`, `password(label: 'Password')`.

### Per-rule messages — `->message()`

Attach a custom message to the preceding rule:

```php
FluentRule::string('Full Name')
    ->required()->message('We need your name!')
    ->min(2)->message('At least :min characters.')
    ->max(255)
```

## Important Behavior

- **Optional by default**: Fields without a presence modifier (`required()`, `nullable()`, etc.) are optional — absent fields pass validation, but present fields are still validated against the rules.
- **Not every Laravel rule has a fluent method**: Use `rule()` as an escape hatch for any rule without a dedicated method:

```php
FluentRule::string()->required()->rule(new MyCustomRule())
```

## Field Modifiers (all rule types)

Labels and messages:
- `label($label)` — set the `:attribute` name used in error messages
- `message($msg)` — custom error message for the most recently added rule

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
- `rule($rule)` — escape hatch to add any Laravel validation rule (string, `ValidationRule` object, or `Closure(string, mixed, Closure): void`)

**Caveat: `exclude` and `validated()`.** Rules like `exclude`, `exclude_if`, etc. only affect `validated()` output when placed at the outer validator level. When used inside a fluent rule, they influence presence detection but do NOT exclude the field from `validated()`. To exclude a field, place `exclude` alongside the fluent rule:

```php
// Correct — field excluded from validated():
'internal_id' => ['exclude', FluentRule::string()]

// Does NOT exclude — exclude is inside the sub-validator:
'internal_id' => FluentRule::string()->exclude()
```

## Conditional Rule Building

All rule types use Laravel's `Conditionable` trait:

```php
FluentRule::string()->required()->when($isAdmin, fn ($r) => $r->min(12))->max(255)

// With else branch (replaces Rule::when($cond, $rules, $default))
FluentRule::string()->when(
    $isAdmin,
    fn ($r) => $r->required()->min(12),
    fn ($r) => $r->sometimes()->max(100),
)
```

## Combinators

- `FluentRule::anyOf([...])` — value passes if it matches any of the given rules (replaces `Rule::anyOf`):

```php
'contact' => FluentRule::anyOf([FluentRule::string()->email(), FluentRule::string()->url()])
```

## Macros

All rule types use Laravel's `Macroable` trait, so you can register reusable rule chains:

```php
StringRule::macro('slug', function () {
    return $this->alpha(true)->lowercase();
});

// Then use: FluentRule::string()->slug()
```

## Embedded Rules (string, numeric, date)

- `in($values)`, `notIn($values)` — accepts an array or a `BackedEnum` class string: `in(StatusEnum::class)`
- `unique($table, $column?)`, `exists($table, $column?)`
- `enum($class, $callback?)` — callback receives the `Illuminate\Validation\Rules\Enum` instance:

```php
FluentRule::string()->enum(StatusEnum::class, fn ($rule) => $rule->only(StatusEnum::Active, StatusEnum::Pending))
FluentRule::numeric()->enum(PriorityEnum::class, fn ($rule) => $rule->except(PriorityEnum::Deprecated))
```

## String-Specific Methods

- Length: `min($n)`, `max($n)`, `between($min, $max)`, `exactly($n)`
- Pattern: `alpha(ascii?)`, `alphaDash(ascii?)`, `alphaNumeric(ascii?)`, `ascii()`, `regex($p)`, `notRegex($p)` — pass `ascii: true` to restrict to ASCII characters
- Starts/ends: `startsWith(...$v)`, `endsWith(...$v)`, `doesntStartWith(...$v)`, `doesntEndWith(...$v)`
- Case: `lowercase()`, `uppercase()`
- Email: `email(...$modes)` — e.g. `email()`, `email('rfc', 'dns')`, `email('rfc', 'spoof')`
- Format: `url()`, `activeUrl()`, `uuid()`, `ulid()`, `json()`, `ip()`, `ipv4()`, `ipv6()`, `macAddress()`, `timezone()`, `hexColor()`
- Date: `date()`, `dateFormat($format)`
- Auth: `currentPassword($guard?)` — optionally specify auth guard
- Comparison: `confirmed()`, `same($field)`, `different($field)`, `inArray($field)`, `inArrayKeys($field)`, `distinct($mode?)` — mode can be `'strict'` or `'ignore_case'`

## Email-Specific Methods

- Modes: `rfcCompliant(strict?)`, `strict()`, `validateMxRecord()`, `preventSpoofing()`, `withNativeValidation(allowUnicode?)`
- Constraints: `max($n)`, `confirmed()`, `same($field)`, `different($field)`
- Embedded: `in($values)`, `notIn($values)`, `enum($class, $callback?)`, `unique($table, $column?)`, `exists($table, $column?)`
- Also available as `FluentRule::string()->email(...$modes)` for inline use

## Password-Specific Methods

- Length: `FluentRule::password(12)` (min via constructor, default 8), `max($n)`
- Strength: `letters()`, `mixedCase()`, `numbers()`, `symbols()`
- Security: `uncompromised($threshold?)` — check against breached password databases

## Numeric-Specific Methods

- Type: `integer(strict?)`, `decimal($min, $max?)` — `integer(strict: true)` for strict type checking; `decimal(2)` for exact places, `decimal(1, 3)` for range
- Size: `min($n)`, `max($n)`, `between($min, $max)`, `exactly($n)` — **note:** `exactly()` accepts `int` only and implicitly adds `integer()`; do not use for decimal equality
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
- Constructor: `FluentRule::array(['name', 'email'])` — restrict allowed keys; accepts `BackedEnum` values

## File-Specific Methods

- Size: `min($size)`, `max($size)`, `between($min, $max)`, `exactly($size)` — accepts int (KB) or human-readable strings (`'5mb'`, `'1gb'`)
- Type: `extensions(...$ext)`, `mimes(...$mimes)`, `mimetypes(...$types)`

## Image-Specific Methods (extends File)

- `allowSvg()` — allow SVG uploads
- `dimensions(Dimensions)` — pass an `Illuminate\Validation\Rules\Dimensions` instance
- `width($n)`, `height($n)` — exact dimensions
- `minWidth($n)`, `maxWidth($n)`, `minHeight($n)`, `maxHeight($n)`
- `ratio($value)` — aspect ratio (e.g. `16/9`)
- Inherits all file methods: `min()`, `max()`, `between()`, `exactly()`, `extensions()`, `mimes()`, `mimetypes()`
