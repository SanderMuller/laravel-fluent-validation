# Common Migration Patterns

When converting existing validation rules to FluentRule, these patterns come up frequently. Use the native FluentRule method — do NOT use `->rule()` escape hatch for these.

## Conditional exclusion with closures

```php
// BEFORE (Laravel):
Rule::excludeIf(fn () => $this->user()->isGuest())

// WRONG (unnecessary escape hatch):
->rule(Rule::excludeIf(fn () => $this->user()->isGuest()))

// CORRECT:
->excludeIf(fn () => $this->user()->isGuest())
```

All conditional modifiers accept `Closure|bool` directly: `excludeIf`, `excludeUnless`, `requiredIf`, `requiredUnless`, `prohibitedIf`, `prohibitedUnless`.

## Password with app defaults

```php
// BEFORE:
['required', 'confirmed', Password::default()]

// WRONG:
FluentRule::string()->required()->confirmed()->rule(Password::default())

// CORRECT (FluentRule::password() uses Password::default() automatically):
FluentRule::password()->required()->confirmed()

// To override the default min:
FluentRule::password(min: 12)->required()->confirmed()
```

## In/notIn with integers or mixed types

```php
// BEFORE:
'in:0,1'
Rule::in([0, 1])

// WRONG:
->rule('in:0,1')

// CORRECT (in() handles mixed types, casts to strings):
->in([0, 1])
->in(['draft', 'published', 'archived'])
->in(StatusEnum::class)  // BackedEnum class
->in($collection)        // Arrayable/Collection
```

## Exists/Unique with where clauses

```php
// BEFORE:
Rule::exists('users', 'id')->where('active', true)
Rule::unique('users', 'email')->ignore($user->id)

// WRONG:
->rule(Rule::exists('users', 'id')->where('active', true))

// CORRECT (callback parameter):
->exists('users', 'id', fn ($r) => $r->where('active', true))
->unique('users', 'email', fn ($r) => $r->ignore($user->id))
->unique('users', 'email', fn ($r) => $r->withoutTrashed())
```

## Email with app defaults

```php
// BEFORE:
Email::default()

// This is an APP-CONFIGURED email rule — keep the escape hatch:
->rule(Email::default())

// FluentRule::email() builds from scratch (not the same as Email::default()):
FluentRule::email()  // equivalent to 'string|email', not Email::default()
```

## Different/Same/Confirmed on untyped fields

```php
// BEFORE:
'different:other_field'

// WRONG:
->rule('different:other_field')

// CORRECT (available on StringRule, NumericRule, and FieldRule):
->different('other_field')
->same('other_field')
->confirmed()
```

## Rule::when() for complex conditional blocks

```php
// BEFORE:
Rule::when($condition, ['bail', Rule::numeric(), $closure])

// This pattern mixes strings, objects, and closures in one block.
// Keep the escape hatch — whenInput() doesn't support mixed arrays:
->rule(Rule::when($condition, ['bail', Rule::numeric(), $closure]))
```

## Choosing the right FluentRule type

| Original rule | FluentRule type | Why |
|---|---|---|
| `'required\|string\|max:255'` | `FluentRule::string()->required()->max(255)` | Has `string` type |
| `'required\|integer'` | `FluentRule::numeric()->required()->integer()` | Has numeric type |
| `'required\|email'` | `FluentRule::email()->required()` | Has `email` type |
| `'required\|boolean'` | `FluentRule::boolean()->required()` | Has `boolean` type |
| `'required\|date'` | `FluentRule::date()->required()` | Has `date` type |
| `'required'` (no type) | `FluentRule::field()->required()` | No type constraint |
| `'email'` (no required) | `FluentRule::email()` | Validates as email when present |
| `'sometimes\|bool'` | `FluentRule::boolean()->sometimes()` | Chain order doesn't matter — compiled output is always `sometimes\|boolean` |

## Checkbox values (0/1)

```php
// BEFORE:
['required', Rule::in([0, 1])]

// WRONG (verbose):
FluentRule::field()->required()->in([0, 1])

// CORRECT (boolean() accepts 0, 1, "0", "1", true, false):
FluentRule::boolean()->required()
```

## Mixing fluent methods with custom rules

`->rule()` appends to the same chain. You can mix freely:

```php
// This works — no need to wrap Rule::file() inside FluentRule::file()
FluentRule::file()->sometimes()->max('8mb')
    ->rule(new FormAttachmentExtensions())
    ->rule(new BlockCodeFiles())
```

## File sizes — human-readable strings

`FileRule` and `ImageRule` accept human-readable size strings on `max()`, `min()`, `between()`, and `exactly()`:

```php
// BEFORE:
['file', 'max:5120']
Rule::file()->max('5mb')

// WRONG (unnecessary escape hatch):
->rule(File::types('png')->max('5mb'))

// CORRECT:
FluentRule::file()->max('5mb')
FluentRule::image()->max('2mb')->mimes('jpg', 'png')
FluentRule::file()->between('500kb', '10mb')

// Raw kilobytes still work:
FluentRule::file()->max(5000)
```

Units: `kb`, `mb`, `gb`, `tb`. Uses decimal (1 MB = 1000 KB), matching Laravel's `File` rule.

## Integer enum values — use field(), not string()

```php
// BEFORE:
['required', Rule::in([1, 3, 2, 4])]

// WRONG (string type rejects integer values from forms):
FluentRule::string()->required()->in([1, 3, 2, 4])

// WRONG (unnecessary escape hatch):
FluentRule::field()->required()->rule('in:1,3,2,4')

// CORRECT (field() has in() via HasEmbeddedRules):
FluentRule::field()->required()->in([1, 3, 2, 4])
```

Use `field()` when the values are integers or mixed types. Use `string()` only when the `string` type constraint is needed.

## FluentRule::image() vs Rule::imageFile()

`FluentRule::image()` compiles to `image|...` (string rules + Dimensions objects). `Rule::imageFile()` is Laravel's `File` rule builder. For simple cases they're equivalent. Use `->rule(Rule::imageFile()->...)` only when you need `File`-specific builder methods.

## Untyped fields with max/min — use string(), not field()

```php
// WRONG — field() has no max():
FluentRule::field()->nullable()->rule(['max', '65535'])

// CORRECT — if it has a length constraint, it's a string:
FluentRule::string()->nullable()->max(65535)
```

If a field has `max`, `min`, `between`, or `exactly`, use the appropriate typed rule (`string()` for character count, `numeric()` for value).

## exists() column parameter

```php
// Omitting column defaults to the field name (Laravel behavior):
->exists('articles')  // checks articles.articleId (if field is 'articleId')

// Always pass the column explicitly when it differs from the field name:
->exists('articles', 'id')
->exists('articles', 'slug')
```

## Integer fields (IDs, counts)

`FluentRule::numeric()->integer()` is correct for integer fields. It's more explicit than `'integer'` but provides type safety — `numeric()` won't let you chain `alpha()` or `email()`.

```php
// IDs:
FluentRule::numeric()->required()->integer()->exists('users', 'id')

// Counts:
FluentRule::numeric()->required()->integer()->min(0)->max(100)
```

## Cross-field references with constants

Field references in `requiredIf`, `excludeUnless`, etc. are strings. Use class constants for maintainability:

```php
class StoreInteractionRequest extends FormRequest
{
    private const TYPE = 'interactions.*.type';

    public function rules(): array
    {
        return [
            self::TYPE => FluentRule::string()->required()->in($types),
            'interactions.*.text' => FluentRule::string()->nullable()
                ->excludeUnless(self::TYPE, 'button', 'hotspot', 'text'),
        ];
    }
}
```

## Password rules trait (Fortify/Breeze)

When a trait returns a mixed rule array, keep it as-is and spread it:

```php
// The trait returns a mixed array — don't convert:
protected function passwordRules(): array
{
    return ['required', 'string', Password::default(), 'confirmed'];
}

// Consumers spread it:
'password' => $this->passwordRules(),
```

For new code, use FluentRule instead:

```php
'password' => FluentRule::password()->required()->confirmed(),
```

## message() binds to the preceding rule

`->message()` attaches to the rule method called immediately before it. This is position-sensitive:

```php
// "We need your name" applies to 'required':
FluentRule::string()->required()->message('We need your name!')->min(2)

// "Too short" applies to 'min':
FluentRule::string()->required()->min(2)->message('Too short!')

// WRONG — message() before any rule throws LogicException:
FluentRule::string()->message('...')  // throws!
```

## Custom app rules and third-party rules

These are correct uses of `->rule()`:

```php
->rule(new MyCustomRule())          // app-specific ValidationRule
->rule(new Iban())                  // third-party rule
->rule(new EnumValue(Status::class)) // bensampo/enum
->rule('custom_string_rule')        // registered via Validator::extend()
->rule(fn ($attr, $val, $fail) => ...) // inline closure
```

## Testing FluentRule validation

Three approaches, depending on what you need:

```php
// 1. Quick compilation check (no actual validation):
$compiled = RuleSet::compile(['name' => FluentRule::string()->required()]);
// Returns: ['name' => 'required|string']

// 2. Full validation with nested rules (each/children expansion):
$validated = RuleSet::from($rules)->validate($data);
// Throws ValidationException on failure, returns validated data on success

// 3. Validator instance for inspection (failed(), errors(), passes()):
$prepared = RuleSet::from($rules)->prepare($data);
$validator = Validator::make($data, $prepared->rules, $prepared->messages, $prepared->attributes);
$validator->passes(); // or ->fails(), ->errors(), ->failed()
```

`RuleSet::compile()` does NOT expand `each()`/`children()` wildcards. Use `prepare()` or `validate()` for nested rules.
