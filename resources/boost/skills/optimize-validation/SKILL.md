---
name: optimize-validation
description: "Scan existing validation code for performance and DX improvements using laravel-fluent-validation. Finds missing HasFluentRules traits, convertible string rules, and opportunities for labels and each(). Activates when: optimizing validation, converting to fluent rules, migrating validation, or improving validation performance."
---

# Optimize Validation

Scan the codebase for validation improvements using `sandermuller/laravel-fluent-validation`. Analyze first, present findings, then apply changes with confirmation.

## When to Activate

- User asks to optimize validation, improve validation performance, or convert to fluent rules
- User mentions: "optimize validation", "convert rules", "fluent migration"
- After installing the package for the first time

## Step 1: Verify installation

Check that the package is installed:

```
rg "sandermuller/laravel-fluent-validation" composer.json
```

If not installed, suggest: `composer require sandermuller/laravel-fluent-validation`

## Step 2: Find validation code

```
rg "extends FormRequest" --type php -l
rg "Validator::make" --type php -l
rg "extends Validator" --type php -l
rg "'\*\." --type php -l
```

## Step 3: Analyze and present findings

Read each file. Look for these specific patterns:

**Detection patterns:**
- `items.*.name` with no `HasFluentRules` → suggest the trait
- `required_unless:items.*.type` or `gte:items.*.field` (cross-field wildcard refs) → MUST use `HasFluentRules` or `FluentValidator`, flag as risk
- `field.child1`, `field.child2` sharing a parent → suggest `children()`
- `field.*` + `field.*.child1` + `field.*.child2` → suggest `each()`
- `attributes()` array entries → suggest `->label()`
- `messages()` entries with `field.rule` keys → suggest `->message()`
- `Rule::excludeIf` / `Rule::when` with closures → note caveats

**Present the summary to the user before making any changes.** Start with the simplest files to build confidence. Save complex custom Validators for last.

Format the summary as:

```
## Validation Optimization Report

### High impact (performance)
- `app/Http/Requests/ImportRequest.php` — 12 wildcard rules, no HasFluentRules trait
- `app/Validators/JsonImportValidator.php` — custom Validator with cross-field wildcards, needs FluentValidator

### Medium impact (DX)
- `app/Http/Requests/StorePostRequest.php` — 8 string rules convertible to fluent
- `app/Http/Requests/StorePostRequest.php` — attributes() array replaceable with labels

### Low impact (cleanup)
- `app/Http/Requests/SearchRequest.php` — 3 fixed children can use children()
```

Ask the user which files/categories to proceed with.

### Priority categories

**High impact (performance):**
1. FormRequests with wildcard rules that don't use `HasFluentRules`
2. Custom Validator subclasses with wildcard rules that don't extend `FluentValidator`

**Medium impact (DX):**
3. String rules convertible to fluent chains
4. Separate `attributes()` arrays replaceable with `->label()`
5. Separate `messages()` entries replaceable with `->message()` / `->fieldMessage()`
6. Manual `items.*.name` patterns groupable with `each()`

**Low impact (cleanup):**
7. Fixed children (`search.value`) groupable with `children()`
8. `Rule::forEach()` calls replaceable with `->each()`

## Step 4: Apply changes

Apply one file at a time. Run tests after each file.

### 4a: Add HasFluentRules trait

The minimal performance change. Works with existing wildcard rules without converting to fluent:

```php
use SanderMuller\FluentValidation\HasFluentRules;

class ImportRequest extends FormRequest
{
    use HasFluentRules;

    // existing rules() method unchanged — wildcards are optimized automatically
}
```

For even better performance, also convert wildcards to `each()`:

```php
// Before
'items' => 'required|array',
'items.*.name' => 'required|string|max:255',

// After
'items' => FluentRule::array()->required()->each([
    'name' => FluentRule::string()->required()->max(255),
]),
```

### 4b: Extend FluentValidator for custom Validators

Replace `extends Validator` with `extends FluentValidator`:

```php
// Before
class MyValidator extends Validator
{
    public function __construct($translator, $data, $rules) {
        parent::__construct($translator, $data, $rules);
    }
}

// After
use SanderMuller\FluentValidation\FluentValidator;

class MyValidator extends FluentValidator
{
    public function __construct(array $data) {
        parent::__construct($data, $this->buildRules());
    }
}
```

### 4c: Convert string rules to fluent

Add the import to the top of the file:

```php
use SanderMuller\FluentValidation\FluentRule;
```

Then convert one field at a time:

| String rule | Fluent equivalent |
|---|---|
| `'required\|string\|min:2\|max:255'` | `FluentRule::string()->required()->min(2)->max(255)` |
| `'required\|numeric\|integer\|min:0'` | `FluentRule::numeric()->required()->integer()->min(0)` |
| `'required\|date\|after:today'` | `FluentRule::date()->required()->afterToday()` |
| `'required\|boolean'` | `FluentRule::boolean()->required()` |
| `'required\|email\|unique:users'` | `FluentRule::email()->required()->unique('users')` |
| `'nullable\|file\|max:2048'` | `FluentRule::file()->nullable()->max('2mb')` |
| `'nullable\|image\|max:5120'` | `FluentRule::image()->nullable()->max('5mb')` |
| `['required', Rule::in([...])]` | `FluentRule::string()->required()->in([...])` |
| `['required', Rule::in(Enum::cases())]` | `FluentRule::string()->required()->in(Enum::class)` |
| `['required', Rule::enum(X::class)]` | `FluentRule::string()->required()->enum(X::class)` |
| `['present']` (no type) | `FluentRule::field()->present()` |

**Conditional rules:**

| Laravel pattern | Fluent equivalent |
|---|---|
| `Rule::when($cond, 'required')` | `->whenInput($cond, fn ($r) => $r->required())` (validation-time) |
| `Rule::excludeIf(fn () => ...)` | `->excludeIf(fn () => ...)` |
| `Rule::requiredIf(fn () => ...)` | `->requiredIf(fn () => ...)` |
| `Rule::forEach(fn () => ...)` | `FluentRule::array()->each(...)` |

### 4d: Replace attributes() with labels

```php
// Before
public function attributes(): array
{
    return ['name' => 'full name', 'email' => 'email address'];
}

// After — remove attributes(), add labels to rules
'name' => FluentRule::string('Full Name')->required()->max(255),
'email' => FluentRule::email('Email Address')->required(),
```

### 4e: Replace messages() with ->message() / ->fieldMessage()

Only for simple rule-specific messages. Keep `messages()` for complex/conditional messages.

```php
// Rule-specific message
->required()->message('We need your name!')

// Field-level fallback (any rule failure)
->fieldMessage('Something is wrong with this field.')
```

### 4f: Group wildcards with each()

```php
// Before
'items' => 'required|array',
'items.*.name' => 'required|string|max:255',
'items.*.email' => 'required|email',

// After
'items' => FluentRule::array()->required()->each([
    'name' => FluentRule::string()->required()->max(255),
    'email' => FluentRule::email()->required(),
]),
```

### 4g: Group fixed keys with children()

```php
// Before
'search' => 'required|array',
'search.value' => 'nullable|string',
'search.regex' => 'nullable|string|in:true,false',

// After
'search' => FluentRule::array()->required()->children([
    'value' => FluentRule::string()->nullable(),
    'regex' => FluentRule::string()->nullable()->in(['true', 'false']),
]),
```

### 4h: Converting deeply nested dot-notation trees

When a file has many flat keys sharing a common prefix, group them into a nested tree using `each()` for wildcard levels and `children()` for fixed-key levels. Work from the deepest keys upward.

**Step-by-step process:**

1. **Identify the tree.** Sort all keys and group by prefix. For example:
   - `columns.*.data` → wildcard level
   - `columns.*.data.sort` → fixed child of `data`
   - `columns.*.data.render.display` → fixed child of `render`, which is a fixed child of `data`
   - `columns.*.search.value` → fixed child of `search`

2. **Build from the leaves.** Start with the deepest keys and work up:
   ```php
   // Level 3: render.display
   'render' => FluentRule::array()->nullable()->children([
       'display' => FluentRule::string()->nullable(),
   ]),
   
   // Level 2: data.sort + data.render
   'data' => FluentRule::field()->nullable()->children([
       'sort'   => FluentRule::string()->nullable(),
       'render' => FluentRule::array()->nullable()->children([
           'display' => FluentRule::string()->nullable(),
       ]),
   ]),
   ```

3. **Combine into the wildcard parent.** The `*.` level uses `each()`:
   ```php
   'columns' => FluentRule::array()->required()->each([
       'data' => FluentRule::field()->nullable()->children([
           'sort'   => FluentRule::string()->nullable(),
           'render' => FluentRule::array()->nullable()->children([
               'display' => FluentRule::string()->nullable(),
           ]),
       ]),
       'search' => FluentRule::array()->required()->children([
           'value' => FluentRule::string()->nullable(),
       ]),
   ]),
   ```

4. **Remove all the flat keys.** The nested structure replaces them all. Delete `columns.*.data`, `columns.*.data.sort`, `columns.*.data.render.display`, `columns.*.search.value`, etc.

**Rules for choosing each() vs children():**
- `*.` in the key → `each()` (wildcard: unknown number of items)
- `.fixed_name` in the key → `children()` (known keys on an object)
- Both can be combined: `each([...])` for the wildcard level, `children([...])` inside it for fixed sub-keys

**Use `FluentRule::field()` when a parent key has children but no type constraint.** For example, `columns.*.data` might accept strings or arrays. Use `FluentRule::field()->nullable()->children([...])` or add `->rule(FluentRule::anyOf([...]))` for union types.

## Step 5: Verify after each file

1. Run the file's specific tests immediately (not the full suite until the end)
2. Check error messages (labels change `:attribute` text)
3. Verify `validated()` output structure if using `HasFluentRules`

## Recommended file order

1. Simple FormRequests with few rules and no cross-field references
2. FormRequests with wildcards (add `HasFluentRules`)
3. FormRequests with `attributes()`/`messages()` (replace with labels)
4. Complex custom Validator subclasses (extend `FluentValidator`)

## Risk flags

Flag these to the user before applying changes:

- **Cross-field wildcard references** (`requiredUnless('items.*.type', ...)`) MUST use `HasFluentRules` or `FluentValidator`. They don't work through standalone FluentRule self-validation. Flag any file that has these.
- **`exclude` rules** only affect `validated()` output when placed at the outer validator level. When converting, keep `['exclude', FluentRule::string()]` not `FluentRule::string()->exclude()`.
- **`anyOf()`** requires Laravel 13+. Use `class_exists(\Illuminate\Validation\Rules\AnyOf::class)` to check. Skip on older versions.
- **Gradual adoption is fine.** Convert one field at a time. Fluent rules mix with string rules in the same array.
