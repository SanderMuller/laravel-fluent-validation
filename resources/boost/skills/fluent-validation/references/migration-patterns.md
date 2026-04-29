# Common Migration Patterns

When converting existing validation rules to FluentRule, use the native method — do NOT use `->rule()` escape hatch unless the rule is app-specific or third-party.

> Paired with the README's [Migrating existing validation with Rector](../../../../../README.md#migrating-existing-validation-with-rector) section. Start with the Rector run there; reach for this doc when you hit a file Rector left alone, or when you want the decision matrix for picking the right factory (`string()` vs `field()` vs `integer()` etc).

---

## Automating the bulk migration with Rector

The companion package [`sandermuller/laravel-fluent-validation-rector`](https://github.com/sandermuller/laravel-fluent-validation-rector) provides Rector rules that handle the common patterns automatically. In real-world testing (Laravel app with ~120 FormRequests and 3469 tests), the rules converted 448 files with 0 regressions.

```bash
composer require --dev sandermuller/laravel-fluent-validation-rector
```

```php
// rector.php
use Rector\Config\RectorConfig;
use SanderMuller\FluentValidationRector\Set\FluentValidationSetList;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/app'])
    ->withSets([FluentValidationSetList::ALL]);
```

See the [Rector package README](https://github.com/sandermuller/laravel-fluent-validation-rector) for the full rule reference, granular set options, base class trait propagation, and known limitations.

After running Rector, review these manual follow-ups:
- Files extending custom `Validator` subclasses (need `FluentValidator` base change)
- Abstract FormRequests whose subclasses use `collect(parent::rules())->mergeRecursive(...)` (skipped by design — see Extending parent rules below)
- Conditional tuples with enum cases written as arrays (`['exclude_unless', $field, Enum::CASE]`) — ensure enum cases use `->value` when moving to fluent form

**Non-standard rules methods:** if a class holds validation rules in a method that isn't called `rules()` (e.g. `rulesWithoutPrefix()` on a `FluentValidator` subclass for JSON-import pipelines), mark the method with `#[SanderMuller\FluentValidation\FluentRules]` so Rector detects it. The attribute is migration-only — it has no runtime effect and is safe to leave in place.

**Debugging skipped files:** if Rector left a file untouched that you expected to be converted, re-run with `FLUENT_VALIDATION_RECTOR_VERBOSE=1 vendor/bin/rector process --dry-run`. The package rules emit `[fluent-validation:skip pid=N] RuleName ClassName (file): reason` lines on stderr explaining each decision ("abstract class", "detected as Livewire", "unsafe parent: a subclass manipulates parent::rules()", "already has HasFluentRules trait", etc). With `--parallel`, per-worker dedup means you may see the same skip from multiple `pid=` values — that's process-level duplication, not a logic bug.

---

## Choosing the right type

| Original rule | FluentRule | Why |
|---|---|---|
| `'required\|string\|max:255'` | `FluentRule::string()->required()->max(255)` | Has `string` type |
| `'required\|integer'` | `FluentRule::integer()->required()` | Shorthand for `numeric()->integer()` |
| `'required\|integer:strict'` | `FluentRule::integer(strict: true)->required()` | Strict mode rejects numeric strings (`"42"`) |
| `'required\|email'` | `FluentRule::email()->required()` | Has `email` type |
| `'required\|boolean'` | `FluentRule::boolean()->required()` | Has `boolean` type |
| `'required\|accepted'` | `FluentRule::accepted()->required()` | Permissive; accepts `'yes'`/`'on'`. Do NOT chain `->accepted()` on `boolean()` — `boolean` rejects those values |
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

### Passing BackedEnum cases to conditional modifiers

Conditional modifiers accept `string|int|bool` values — they serialize via `implode(',', $values)`. Enum cases must be unwrapped via `->value`:

```php
// WRONG — fatal: "Object of class Status could not be converted to string"
->excludeUnless('type', Status::DRAFT, Status::PUBLISHED)

// CORRECT — pass the backing value
->excludeUnless('type', Status::DRAFT->value, Status::PUBLISHED->value)
```

This also applies to `requiredIf`, `excludeIf`, `prohibitedIf`, `missingIf`, `presentIf`, etc. when called with the `(field, ...values)` signature. The `->in()` method is the exception — it accepts a BackedEnum class string directly (`->in(Status::class)`).

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

// FluentRule::email() uses Email::default() automatically when configured.
// For basic validation without app defaults:
FluentRule::email(defaults: false)->required()
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

### Style: prefer explicit parent rules

When writing new rules with wildcard children, define the parent array alongside them even when there's no type constraint:

```php
// Preferred — explicit parent
'items'        => FluentRule::array()->required(),
'items.*.name' => FluentRule::string()->required(),

// Works — synthesis kicks in
'items.*.name' => FluentRule::string()->required(),
```

When no explicit parent rule exists, `GroupWildcardRulesToEachRector` synthesizes `FluentRule::array()->nullable()` to preserve Laravel's flat-rule null-parity. That's safe, but explicit parents are self-documenting and give you direct control over `required`, `nullable`, `min`, `max`, and allowed keys at the array level.

Grouping fires when the prefix has **≥1 typed/nested child** (e.g. `items.*.name`, `items.address`). Bare wildcards alone (`items.*` without a suffix) don't trigger grouping — they stay flat.

Side effect of synthesis: scalar inputs where an array was expected (`items: "string"`) will start failing with `validation.array`. That's typically a latent bug in upstream code (a frontend sending the wrong type) getting surfaced for the first time — not a regression to apologize for.

### Custom error messages

```php
// Preferred — inline named arg, colocated with the rule:
->required(message: 'We need your name!')->min(2, message: 'Too short!')

// Also on factories:
FluentRule::email(message: 'Invalid email.')
FluentRule::string(message: 'Must be text.')

// Chained shorthand (binds to the most recent rule):
->required()->message('We need your name!')->min(2)->message('Too short!')

// Escape hatch — variadic methods, custom rule objects, non-last composite sub-rule:
->requiredWith('email', 'phone')->messageFor('required_with', 'Required when email or phone is set.')
->digits(5, message: 'Must be 5 digits.')->messageFor('integer', 'Must be a whole number.')

// Field-level fallback (any rule failure):
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

#### Extending a parent's `each([...])` / `children([...])` shape

When the parent defines a keyed `each()` or `children()` map and the child needs to add one sub-rule, use the extend helpers instead of `getEachRules()` + `Arr::wrap()` round-trips:

```php
// Parent: SaveQuestionRequest
protected const ANSWERS = 'answers';

public function rules(): RuleSet
{
    return RuleSet::from([
        self::ANSWERS => FluentRule::array()->nullable()->max(20)->each([
            'text' => FluentRule::string()->required(),
        ]),
    ]);
}

// Child: UpdateQuestionRequest — sugar form
public function rules(): RuleSet
{
    return parent::rules()->modifyEach(self::ANSWERS, [
        'id' => FluentRule::numeric()->nullable(),
    ]);
}
```

- `RuleSet::modifyEach($field, $rules)` / `RuleSet::modifyChildren($field, $rules)` — later-wins merge sugar; wraps `$r->mergeEachRules(...)` / `$r->mergeChildRules(...)` so you skip the `modify()` + lambda.
- `addEachRule($key, $rule)` / `addChildRule($key, $rule)` — append one keyed sub-rule via the primitive `modify()` form. **Throws** on existing-key collision (use `mergeEachRules` / `mergeChildRules` if replacement is intentional).
- `mergeEachRules($rules)` / `mergeChildRules($rules)` — later-wins merge primitive (what `modifyEach` / `modifyChildren` wrap).
- Base constraints (`nullable`, `max:20`, `required`, etc.) on the ArrayRule/FieldRule survive every call.
- `addEachRule` / `mergeEachRules` / `modifyEach` throw `CannotExtendListShapedEach` when invoked on a rule whose `each()` was set to a single `ValidationRule` (list form, e.g. `each(FluentRule::string())`). Convert to keyed form first: `each(['key' => FluentRule::…])`.
- Reading the stored shape: prefer the narrow getters `ArrayRule::getEachKeyedRules(): ?array<string, ValidationRule>` + `ArrayRule::getEachListRule(): ?ValidationRule`. The legacy union-returning `getEachRules()` is `@deprecated` since 1.24.0 — its list-form branch is scheduled for removal in 1.25.0.

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
->rule(Email::default())            // only if you need Email::default() alongside other escape-hatch rules
->rule(Rule::when($cond, [...]))    // mixed-array conditional blocks
```
