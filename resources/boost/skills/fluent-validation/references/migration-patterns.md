# Common Migration Patterns

When converting existing validation rules to FluentRule, use the native method — do NOT use `->rule()` escape hatch unless the rule is app-specific or third-party.

---

## Choosing the right type

| Original rule | FluentRule | Why |
|---|---|---|
| `'required\|string\|max:255'` | `FluentRule::string()->required()->max(255)` | Has `string` type |
| `'required\|integer'` | `FluentRule::integer()->required()` | Shorthand for `numeric()->integer()` |
| `'required\|email'` | `FluentRule::email()->required()` | Has `email` type |
| `'required\|boolean'` | `FluentRule::boolean()->required()` | Has `boolean` type |
| `'required\|date'` | `FluentRule::date()->required()` | Has `date` type |
| `'required'` (no type) | `FluentRule::field()->required()` | No type constraint |
| `'email'` (no required) | `FluentRule::email()` | Validates when present |
| `'sometimes\|bool'` | `FluentRule::boolean()->sometimes()` | Chain order doesn't matter |
| `['required', Rule::in([0,1])]` | `FluentRule::boolean()->required()` | `boolean()` accepts 0/1/"0"/"1" |
| `['required', Rule::in([1,3,2])]` | `FluentRule::field()->required()->in([1,3,2])` | Use `field()` for integer enums |

**Key rules:** If it has a type constraint, use the matching typed factory. If no type, use `field()`. If it has `max`/`min` on length, it's a `string()`. If the values are integers, use `field()->in()` not `string()->in()`.

---

## Converting Laravel Rule:: methods

### Conditional modifiers (closures/bools)

All conditional modifiers accept BOTH `(string $field, ...$values)` AND `(Closure|bool)`:

```php
// WRONG: ->rule(Rule::excludeIf(fn () => ...))
// CORRECT:
->excludeIf(fn () => $this->user()->isGuest())
->requiredIf(fn () => $someCondition)
->prohibitedIf(true)
```

### Password

```php
// FluentRule::password() uses Password::default() automatically:
FluentRule::password()->required()->confirmed()

// Override the default min:
FluentRule::password(min: 12)->required()->confirmed()
```

### Email

```php
// FluentRule::email() is basic email validation:
FluentRule::email()->required()  // compiles to 'string|email'

// For explicit modes:
FluentRule::email()->rfcCompliant()->strict()

// For app-configured defaults (Email::defaults() in AppServiceProvider):
FluentRule::field()->required()->rule(Email::default())
```

### In/notIn

```php
->in([0, 1])                  // mixed types, casts to strings
->in(['draft', 'published'])  // string values
->in(StatusEnum::class)       // BackedEnum class
->in($collection)             // Arrayable/Collection
->notIn('admin')              // scalar accepted
```

### Exists/Unique with callbacks

```php
->exists('users', 'id', fn ($r) => $r->where('active', true))
->unique('users', 'email', fn ($r) => $r->ignore($user->id))
->unique('users', 'email', fn ($r) => $r->withoutTrashed())
```

Note: omitting column defaults to the field name. Always pass column explicitly when it differs: `->exists('articles', 'id')`.

### Different/Same/Confirmed

Available on StringRule, NumericRule, AND FieldRule:

```php
->different('other_field')
->same('other_field')
->confirmed()
```

### File sizes

`FileRule` and `ImageRule` accept human-readable strings:

```php
FluentRule::file()->max('5mb')
FluentRule::image()->max('2mb')->mimes('jpg', 'png')
FluentRule::file()->between('500kb', '10mb')
```

Units: `kb`, `mb`, `gb`, `tb`. Decimal (1 MB = 1000 KB), matching Laravel.

### Date format

`format()` REPLACES the base `date` type:

```php
FluentRule::date()->format('H:i')  // → date_format:H:i (no 'date' prefix)
FluentRule::date()->format('Y-m-d H:i:s')  // → date_format:Y-m-d H:i:s
```

### Image vs Rule::imageFile()

`FluentRule::image()` compiles to string rules. `Rule::imageFile()` is Laravel's File builder. For simple cases they're equivalent. Use `->rule(Rule::imageFile()->...)` only for File-specific builder methods.

### Rule::when() with mixed arrays

Keep the escape hatch — `whenInput()` doesn't support mixed arrays:

```php
->rule(Rule::when($condition, ['bail', Rule::numeric(), $closure]))
```

---

## Advanced patterns

### Custom error messages

```php
// Position-based (attaches to preceding rule):
->required()->message('We need your name!')->min(2)->message('Too short!')

// Name-based (can be called anywhere in chain):
->required()->min(2)->messageFor('required', 'We need your name!')

// Field-level fallback:
->required()->min(2)->fieldMessage('Something is wrong.')
```

### Cross-field references with constants

```php
private const TYPE = 'interactions.*.type';

self::TYPE => FluentRule::string()->required()->in($types),
'interactions.*.text' => FluentRule::string()->nullable()
    ->excludeUnless(self::TYPE, 'button', 'hotspot', 'text'),
```

### Extending parent rules in child FormRequests

```php
$rules = parent::rules();
$rules['type'] = (clone $rules['type'])->rule(function ($attr, $val, $fail) { ... });
return $rules;
```

Don't use `mergeRecursive` — it deconstructs objects.

### Password rules trait (Fortify/Breeze)

Keep mixed rule arrays as-is: `'password' => $this->passwordRules()`. For new code: `FluentRule::password()->required()->confirmed()`.

### Mixing fluent with custom rules

`->rule()` appends to the chain. Mix freely:

```php
FluentRule::file()->sometimes()->max('8mb')
    ->rule(new FormAttachmentExtensions())
    ->rule(new BlockCodeFiles())
```

---

## Testing

```php
// 1. Quick compilation (no validation):
$compiled = RuleSet::compile(['name' => FluentRule::string()->required()]);

// 2. Full validation with each/children expansion:
$validated = RuleSet::from($rules)->validate($data);

// 3. Validator instance for inspection:
$prepared = RuleSet::from($rules)->prepare($data);
$validator = Validator::make($data, $prepared->rules, $prepared->messages, $prepared->attributes);
```

`RuleSet::compile()` does NOT expand `each()`/`children()`. Use `prepare()` or `validate()` for nested rules.

---

## Correct escape hatches

These are appropriate uses of `->rule()`:

```php
->rule(new MyCustomRule())          // app-specific ValidationRule
->rule(new Iban())                  // third-party rule
->rule(new EnumValue(Status::class)) // bensampo/enum
->rule('custom_string_rule')        // registered via Validator::extend()
->rule(fn ($attr, $val, $fail) => ...) // inline closure
->rule(Email::default())            // app-configured email (if Email::defaults() is set)
->rule(Rule::when($cond, [...]))    // mixed-array conditional blocks
```
