# Rule Type Reference

## String

- Length: `min($n)`, `max($n)`, `between($min, $max)`, `exactly($n)`
- Pattern: `alpha(ascii?)`, `alphaDash(ascii?)`, `alphaNumeric(ascii?)`, `ascii()`, `regex($p)`, `notRegex($p)`
- Starts/ends: `startsWith(...$v)`, `endsWith(...$v)`, `doesntStartWith(...$v)`, `doesntEndWith(...$v)`
- Case: `lowercase()`, `uppercase()`
- Email: `email(...$modes)` — e.g. `email()`, `email('rfc', 'dns')`
- Format: `url()`, `activeUrl()`, `uuid()`, `ulid()`, `json()`, `ip()`, `ipv4()`, `ipv6()`, `macAddress()`, `timezone()`, `hexColor()`
- Date: `date()`, `dateFormat($format)`
- Auth: `currentPassword($guard?)`
- Comparison: `confirmed()`, `same($field)`, `different($field)`, `inArray($field)`, `inArrayKeys($field)`, `distinct($mode?)`

## Email

- Modes: `rfcCompliant(strict?)`, `strict()`, `validateMxRecord()`, `preventSpoofing()`, `withNativeValidation(allowUnicode?)`
- Constraints: `max($n)`, `confirmed()`, `same($field)`, `different($field)`
- Embedded: `in($values)`, `notIn($values)`, `enum($class, $callback?)`, `unique($table, $column?)`, `exists($table, $column?)`
- Also available as `FluentRule::string()->email(...$modes)` for inline use

## Password

- `FluentRule::password()` uses `Password::default()` from AppServiceProvider. Pass explicit min to override: `FluentRule::password(min: 12)`
- Length: `min($n)`, `max($n)` — `min()` overrides the default minimum
- Strength: `letters()`, `mixedCase()`, `numbers()`, `symbols()`
- Security: `uncompromised($threshold?)` — check against breached password databases
- Comparison: `confirmed()`

## Numeric

- Type: `integer(strict?)`, `decimal($min, $max?)`
- Size: `min($n)`, `max($n)`, `between($min, $max)`, `exactly($n)` — `exactly()` implicitly adds `integer()`
- Digits: `digits($n)`, `digitsBetween($min, $max)`, `minDigits($n)`, `maxDigits($n)`
- Comparison: `greaterThan($field)`, `greaterThanOrEqualTo($field)`, `lessThan($field)`, `lessThanOrEqualTo($field)`, `multipleOf($n)`, `confirmed()`, `same($field)`, `different($field)`, `inArray($field)`, `inArrayKeys($field)`, `distinct($mode?)`

## Date

All comparison methods accept `DateTimeInterface|string`:

- Format: `format($format)` — REPLACES the `date` base type with `date_format:$format`. Use for time-only: `FluentRule::date()->format('H:i')` → `date_format:H:i`
- Today: `beforeToday()`, `afterToday()`, `todayOrBefore()`, `todayOrAfter()`
- Now: `past()`, `future()`, `nowOrPast()`, `nowOrFuture()`
- Compare: `before($date)`, `after($date)`, `beforeOrEqual($date)`, `afterOrEqual($date)`, `between($from, $to)`, `betweenOrEqual($from, $to)`, `dateEquals($date)`, `same($field)`, `different($field)`

## Boolean

- `accepted()`, `acceptedIf($field, ...$values)`
- `declined()`, `declinedIf($field, ...$values)`

## Array

- Size: `min($n)`, `max($n)`, `between($min, $max)`, `exactly($n)`
- Structure: `list()`, `requiredArrayKeys(...$keys)`
- Wildcard children: `each($rule)` for scalar items, `each([...])` for object items → produces `items.*.name`
- Fixed-key children: `children([...])` for known-key objects → produces `search.value` (no wildcard). Also available on `FluentRule::field()`
- Polymorphic fields: `FluentRule::field()->rule(FluentRule::anyOf([...]))->children([...])` for fields that can be different types with optional child keys
- Constructor: `FluentRule::array(['name', 'email'])` — restrict allowed keys; accepts `BackedEnum` values

## File

- Size: `min($size)`, `max($size)`, `between($min, $max)`, `exactly($size)` — accepts int (KB) or human-readable strings (`'5mb'`, `'1gb'`)
- Type: `extensions(...$ext)`, `mimes(...$mimes)`, `mimetypes(...$types)`

## Image (extends File)

- `allowSvg()` — allow SVG uploads
- `dimensions(Dimensions)` — pass an `Illuminate\Validation\Rules\Dimensions` instance
- `width($n)`, `height($n)` — exact dimensions
- `minWidth($n)`, `maxWidth($n)`, `minHeight($n)`, `maxHeight($n)`
- `ratio($value)` — aspect ratio (e.g. `16/9`)
- Inherits all file methods

## Field (untyped)

- No base type constraint — use for fields that need modifiers without a type
- Supports `children([...])` for fixed-key child rules
- Supports all field modifiers and embedded rules
- Comparison: `same($field)`, `different($field)`, `confirmed()`

## Embedded Rules (string, numeric, date, email)

- `in($values)`, `notIn($values)` — accepts an array or a `BackedEnum` class string: `in(StatusEnum::class)`
- `unique($table, $column?)`, `exists($table, $column?)`
- `enum($class, $callback?)` — callback receives the `Illuminate\Validation\Rules\Enum` instance

## Combinators

- `FluentRule::anyOf([...])` — value passes if it matches any of the given rules. Requires Laravel 13+ (`AnyOf` class). Guarded by `class_exists()` at runtime.
