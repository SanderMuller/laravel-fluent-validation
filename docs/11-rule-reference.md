# Rule reference

Available types: `FluentRule::string()`, `integer()`, `numeric()`, `email()`, `password()`, `date()`, `dateTime()`, `boolean()`, `array()`, `file()`, `image()`, `field()`, `anyOf()`. Shortcuts: `url()`, `uuid()`, `ulid()`, `ip()`.

<details>
<summary><a name="rule-string"></a><strong>String</strong>: length, pattern, format, comparison</summary>

```php
// Length
FluentRule::string()->min(2)->max(255)                       // also: between(2, 255), exactly(10)

// Character classes (pick one — each is a complete pattern)
FluentRule::string()->alpha()                                // letters only; also: alphaDash(), alphaNumeric(); each accepts ascii: true
FluentRule::string()->ascii()                                // 7-bit ASCII

// Pattern matching
FluentRule::string()->regex('/^SKU-\d+$/')                   // also: notRegex('/\s/')

// Affixes
FluentRule::string()->startsWith('prefix_')->endsWith('.txt') // also: doesntStartWith(), doesntEndWith()

// Case (mutually exclusive — pick one)
FluentRule::string()->lowercase()                            // or: uppercase()

// Formats (pick the one that matches your field)
FluentRule::string()->url()                                  // also: activeUrl(), uuid(), ulid(), json(), ip(),
                                                             //       ipv4(), ipv6(), macAddress(), timezone(), hexColor()
FluentRule::string()->encoding('UTF-8')

// Cross-field & confirmation
FluentRule::string()->confirmed()                            // pairs with `<field>_confirmation`
FluentRule::string()->currentPassword()                      // matches the authed user's password; accepts a guard
FluentRule::string()->same('confirm_field')                  // also: different('other_field')

// Wildcards & uniqueness in arrays
FluentRule::string()->inArray('values.*')                    // also: inArrayKeys('values.*')
FluentRule::string()->distinct()                             // for `'tags.*'` rules; also: distinct('strict'), distinct('ignore_case')
```

> [!TIP]
> Top-level shortcuts for the most common single-rule strings: `FluentRule::url()`, `uuid()`, `ulid()`, `ip()`, `ipv4()`, `ipv6()`, `macAddress()`, `json()`, `timezone()`, `hexColor()`, `activeUrl()`, `regex($pattern)`. All accept an optional `$label`. Each is `FluentRule::string()->X()`; use the shortcut when the string type is the only constraint beyond the format.

</details>

<details>
<summary><a name="rule-email"></a><strong>Email</strong>: app defaults, modes, uniqueness</summary>

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
<summary><a name="rule-password"></a><strong>Password</strong>: strength, confirmation, defaults</summary>

```php
FluentRule::password(min: 12)->letters()->mixedCase()->numbers()->symbols()->uncompromised()
FluentRule::password()->min(10)->max(64)                     // length bounds
FluentRule::password()->uncompromised(threshold: 3)          // allow up to N HIBP hits
FluentRule::password()->confirmed()                          // requires `password_confirmation` field
FluentRule::password()->confirmed('Passwords do not match.') // custom mismatch message
```

`FluentRule::password()` uses your app's `Password::default()` configuration (set via `Password::defaults()` in AppServiceProvider). Pass `defaults: false` for a plain `Password::min(8)`: `FluentRule::password(defaults: false)`.

</details>

<details>
<summary><a name="rule-numeric"></a><strong>Numeric / Integer</strong>: type, size, digits, comparison</summary>

```php
// Type
FluentRule::integer()->required()->min(0)              // shorthand for numeric()->integer()
FluentRule::integer(strict: true)->required()          // reject numeric strings ("42"); requires Laravel 12.23+
FluentRule::numeric()->required()                      // any numeric value (int or float)

// Decimal precision
FluentRule::numeric()->decimal(2)                      // exactly 2 decimal places (e.g. money)
FluentRule::numeric()->decimal(0, 2)                   // up to 2 decimal places

// Size & multiples
FluentRule::numeric()->min(0)->max(100)                // also: between(0, 100), exactly(42)
FluentRule::numeric()->multipleOf(5)

// Digit-count constraints (pick one)
FluentRule::integer()->digits(4)                       // exactly 4 digits, e.g. PIN, ZIP
FluentRule::integer()->digitsBetween(4, 6)             // also: minDigits(3), maxDigits(8)

// Cross-field comparisons
FluentRule::numeric()->greaterThan('min_price')->lessThan('max_price')   // also: greaterThanOrEqualTo(), lessThanOrEqualTo()

// Sign helpers
FluentRule::numeric()->positive()                      // gt:0, same as greaterThan(0); also: negative() for lt:0
FluentRule::numeric()->nonNegative()                   // gte:0 (allows zero); also: nonPositive() for lte:0
```

</details>

<details>
<summary><a name="rule-date"></a><strong>Date</strong>: boundaries, shortcuts, format</summary>

All comparison methods accept `DateTimeInterface|string`:

```php
// Boundary comparisons
FluentRule::date()->after('today')->before('2025-12-31')         // also: afterOrEqual(), beforeOrEqual()
FluentRule::date()->between('2025-01-01', '2025-12-31')          // also: betweenOrEqual()

// Today-relative shortcuts (mutually exclusive — pick one)
FluentRule::date()->afterToday()                                  // also: beforeToday(), todayOrAfter(), todayOrBefore()
FluentRule::date()->future()                                      // also: past(), nowOrFuture(), nowOrPast()

// Format & equality
FluentRule::date()->format('Y-m-d')->dateEquals('2025-06-15')
FluentRule::dateTime()->afterToday()                              // shortcut for date()->format('Y-m-d H:i:s')

// Cross-field
FluentRule::date()->same('start_date')                            // also: different('other_field')
```

</details>

<details>
<summary><a name="rule-other-types"></a><strong>Boolean, Array, File, Image, Field, AnyOf</strong></summary>

**Boolean.** `boolean()` accepts `true`, `false`, `1`, `0`, `'1'`, `'0'`. Use `accepted()` for `'yes'`, `'on'`, `'1'`, `true` and `declined()` for `'no'`, `'off'`, `'0'`, `false`:

```php
FluentRule::boolean()->required()                       // strict boolean
FluentRule::boolean()->acceptedIf('role', 'admin')      // also: declinedIf('type', 'free')
```

**Accepted / Declined.** Standalone factories for the permissive `accepted`/`declined` families without a strict `boolean` base. Useful for terms-of-service / opt-in checkboxes where form posts deliver `'yes'` or `'on'` values that Laravel's `boolean` rule rejects:

```php
FluentRule::accepted()                          // true | 1 | '1' | 'yes' | 'on' | 'true'
FluentRule::accepted()->acceptedIf('role', 'admin')
FluentRule::declined()                          // false | 0 | '0' | 'no' | 'off' | 'false'
FluentRule::declined()->declinedIf('under_18', 'yes')
```

> **Footgun:** `FluentRule::boolean()->accepted()` compiles to `boolean|accepted`; `boolean` rejects `'yes'` / `'on'` which `accepted` would otherwise permit. Use `FluentRule::accepted()` (or `::declined()`) when the input shape is HTML-form-ish.

**Array.** Size, structure, allowed keys:

```php
// Size
FluentRule::array()->min(1)->max(10)                  // also: between(1, 5), exactly(3)

// Shape
FluentRule::list()                                    // shortcut for array()->list(), sequentially-indexed
FluentRule::array(['name', 'email'])                  // restrict allowed keys
FluentRule::array(MyEnum::cases())                    // BackedEnum keys
FluentRule::array()->requiredArrayKeys('name', 'email')

// Element membership
FluentRule::array()->contains('required_value')       // also: doesntContain('forbidden_value')
FluentRule::array()->distinct()                       // unique elements; also: distinct('strict'), distinct('ignore_case')
```

**File.** Size methods accept integers (kilobytes) or human-readable strings:

```php
// Size
FluentRule::file()->max('5mb')                        // also: min('100kb'), between('1mb', '10mb'), exactly('2mb')

// Type (pick the check that matches your trust model)
FluentRule::file()->extensions('pdf', 'docx')         // by filename extension only
FluentRule::file()->mimes('jpg', 'png')               // by mime guessed via extension
FluentRule::file()->mimetypes('application/pdf')      // by actual mime sniffed from contents
```

**Image.** Dimension constraints, inherits all file methods:

```php
// Size & format
FluentRule::image()->max('5mb')->allowSvg()

// Dimension bounds
FluentRule::image()->minWidth(100)->maxWidth(1920)->minHeight(100)->maxHeight(1080)

// Exact dimensions OR aspect ratio (mutually exclusive — pick one)
FluentRule::image()->width(800)->height(600)
FluentRule::image()->ratio(16 / 9)                    // also: ratio('16/9'), ratio(1) for square
```

**Field (untyped).** Modifiers without a type constraint. Use `field()` when the input has no inherent type (e.g. a value that could be a string OR integer depending on context), or when your only validation is modifiers (`required`, `nullable`, `in`, conditional presence). It's also the escape hatch Rector reaches for when it can't narrow the type from pipe/array rules. If you see `FluentRule::field()` in migrated code, consider whether a typed factory (`string()`, `integer()`) better expresses intent.

```php
FluentRule::field()->present()
FluentRule::field()->requiredIf('type', 'special')
FluentRule::field('Answer')->nullable()->in(['yes', 'no'])
```

**AnyOf.** Value passes if it matches any rule set (Laravel 13+):

```php
FluentRule::anyOf([
    FluentRule::string()->required()->min(2),
    FluentRule::numeric()->required()->integer(),
])
```

</details>

<details>
<summary><a name="embedded-rules"></a><strong>Embedded rules</strong>: in, unique, exists, enum</summary>

String, numeric, and date rules support `in`, `unique`, `exists`, and `enum`. `in()` and `notIn()` accept arrays or a `BackedEnum` class:

```php
FluentRule::string()->in(['draft', 'published'])
FluentRule::string()->in(StatusEnum::class)          // all enum values
FluentRule::string()->notIn(DeprecatedStatus::class)
FluentRule::string()->enum(StatusEnum::class)
FluentRule::string()->enum(StatusEnum::class, fn ($r) => $r->only(StatusEnum::Active))
FluentRule::enum(StatusEnum::class)   // top-level shortcut, returns an untyped FieldRule
FluentRule::string()->unique('users', 'email')
FluentRule::string()->unique('users', 'email', fn ($r) => $r->ignore($this->user()->id))
FluentRule::string()->exists('roles', 'name')
FluentRule::string()->exists('subjects', 'id', fn ($r) => $r->where('active', true))
```

`unique()`, `exists()`, and `enum()` accept an optional callback as the last argument. The callback receives the underlying Laravel rule object, so you can chain `->where()`, `->ignore()`, `->only()`, etc.

</details>

<details>
<summary><a name="field-modifiers"></a><strong>Field modifiers</strong>: presence, prohibition, exclusion, messages</summary>

Shared by all rule types:

```php
// Presence
->required()  ->nullable()  ->sometimes()  ->filled()  ->present()  ->missing()

// Conditional presence: accepts field references or Closure|bool.
// Value args on *If / *Unless accept BackedEnum, so ->requiredIf('role', Role::Admin) works; no ->value needed.
->requiredIf('role', 'admin')  ->requiredUnless('type', 'guest')  ->requiredIf(fn () => $cond)
->requiredWith('field')  ->requiredWithAll('a', 'b')  ->requiredWithout('field')  ->requiredWithoutAll('a', 'b')
->requiredIfAccepted('terms')  ->requiredIfDeclined('terms')
->presentIf('type', 'admin')  ->presentUnless('type', 'guest')  ->presentWith('field')  ->presentWithAll('a', 'b')

// Prohibition & exclusion
->prohibited()  ->prohibitedIf('field', 'val')  ->prohibitedUnless('field', 'val')  ->prohibits('other')
->prohibitedIfAccepted('terms')  ->prohibitedIfDeclined('terms')
->exclude()  ->excludeIf('field', 'val')  ->excludeUnless('field', 'val')  ->excludeWith('f')  ->excludeWithout('f')

// Messages
->label('Name') // sets :attribute for this field's messages
->required(message: 'Please enter your :attribute') // custom message for this rule
->requiredIf('type', 'admin', message: 'Admins must provide :attribute') // custom message for this conditional rule

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

**Conditional rules.** All rule types use Laravel's `Conditionable` trait. A single form request can handle both create and update:

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

**Escape hatch.** Add any Laravel validation rule with `rule()`:

```php
FluentRule::string()->rule('email:rfc,dns')
FluentRule::string()->rule(new MyCustomRule())
FluentRule::file()->rule(['mimetypes', ...$acceptedTypes])
```

**Macros.** Define reusable rule chains in a service provider:

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
