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

## Custom app rules and third-party rules

These are correct uses of `->rule()`:

```php
->rule(new MyCustomRule())          // app-specific ValidationRule
->rule(new Iban())                  // third-party rule
->rule(new EnumValue(Status::class)) // bensampo/enum
->rule('custom_string_rule')        // registered via Validator::extend()
->rule(fn ($attr, $val, $fail) => ...) // inline closure
```
