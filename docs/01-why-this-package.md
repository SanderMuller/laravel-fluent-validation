# Why this package?

## Chainable, readable rules

A fluent chain reads as a sentence: the field is a date, it is required, it must be today or later, and it must fall before `ends_at`. Every constraint is a real method call, so a typo is "method not found" in your editor instead of a silently ignored substring inside a pipe-delimited string.

```php
// One string, four rules, two of them easy to get subtly wrong
'starts_at' => 'required|date|after_or_equal:today|before:ends_at',

// The same rules, each one visible and verifiable
'starts_at' => FluentRule::date()->required()->todayOrAfter()->before('ends_at'),
```

## One builder per type

`FluentRule::string()`, `integer()`, `date()`, `email()`, `file()`, and friends each return a builder that exposes only the methods valid for that type. `FluentRule::date()` has no `mimes()`; `FluentRule::string()` has no `digits()`. A whole class of copy-paste mistakes stops existing, because the wrong method is simply not there.

Type-scoping also removes the ambiguity baked into string rules. `min:5` means five characters on a string, a minimum value of five on a number, five elements on an array, and five kilobytes on a file — it depends on whichever type rule happens to sit next to it. With a typed builder the type comes first, so `->min(5)` has exactly one meaning.

## Your IDE does the remembering

Everything you used to look up now autocompletes:

- Which slot in `unique:users,email,$ignoreId,id` holds the ignored ID? It's a named call now: `->unique('users', 'email', fn ($r) => $r->ignore($id))`.
- `date_equals`, `same`, or `before_or_equal` to compare two dates? Type `->` on a date rule and pick from the list.
- Parameters are typed, so passing the wrong shape is flagged while you write, not when the request fails in production.

## Array notation

`each()` and `children()` group parent and child rules in one place instead of scattering them across 20 flat dot-notation keys. Wildcard children land under their parent definition; fixed-key children stay scoped to the field they belong to. Nested arrays nest naturally. The flat `'items.*.name'` form still works when you want it.

## Messages & attributes

Labels and per-rule messages attach to the rule itself, so there's no separate `messages()` or `attributes()` array to drift out of sync with `rules()`. `FluentRule::email('Email Address')->required(message: 'We need your :attribute.')` carries both the human-readable name and the failure copy with the rule definition.

## Performance

Where you'll actually feel this is on endpoints that validate **a lot of fields** or **a lot of items at once**: CSV/JSON imports, bulk-edit forms, settings pages, anything with wildcard arrays like `items.*.id` or `orders.*.line_items.*.product_id`. On a 3-field login form FluentRule is still faster than the native pipeline, but you won't notice; the saving is in microseconds.

Laravel's wildcard validation is O(n²) on large arrays; `HasFluentRules` rewrites the expansion as a single tree walk and makes it [up to 160x faster](08-performance.md#benchmarks) for nested wildcards, 62x faster for conditional-heavy payloads. Database `exists`/`unique` checks against wildcard arrays batch into a single `whereIn` query instead of one per item. Common rules compile to PHP closures that bypass Laravel's validator entirely on the happy path.

<details>
<summary><a name="compared-to-rule"></a>Compared to Laravel's <code>Rule</code> class</summary>

`FluentRule` is intentionally named differently from `Illuminate\Validation\Rule` so both can be used without aliasing. You generally don't need Laravel's `Rule` at all.

**Type starters**

| Laravel's `Rule`             | FluentRule equivalent                                 |
|------------------------------|-------------------------------------------------------|
| `Rule::string()`             | `FluentRule::string()`                                |
| `Rule::numeric()`            | `FluentRule::numeric()` / `FluentRule::integer()`     |
| `Rule::date()`               | `FluentRule::date()`                                  |
| `Rule::dateTime()`           | `FluentRule::dateTime()`                              |
| `Rule::email()`              | `FluentRule::email()`                                 |
| `Rule::file()`               | `FluentRule::file()`                                  |
| `Rule::imageFile($allowSvg)` | `FluentRule::image()->allowSvg()` (or just `image()`) |
| `Rule::array($keys = null)`  | `FluentRule::array($keys)`                            |
| `Rule::dimensions([...])`    | `FluentRule::image()->minWidth(...)->ratio(...)`      |

**Set membership and value spaces**

| Laravel's `Rule`                  | FluentRule equivalent                                |
|-----------------------------------|------------------------------------------------------|
| `Rule::in([...])`                 | `->in([...])` (on any typed builder)                 |
| `Rule::notIn([...])`              | `->notIn([...])`                                     |
| `Rule::contains([...])`           | `FluentRule::array()->contains(...)`                 |
| `Rule::doesntContain([...])`      | `FluentRule::array()->doesntContain(...)`            |
| `Rule::enum(Status::class)`       | `FluentRule::enum(Status::class)` / `->enum(...)`    |
| `Rule::anyOf([...])`              | `FluentRule::anyOf([...])`                           |

**Database lookups**

| Laravel's `Rule`                       | FluentRule equivalent                                  |
|----------------------------------------|--------------------------------------------------------|
| `Rule::unique('users')->where(...)`    | `->unique('users', 'col', fn ($r) => $r->where(...))`  |
| `Rule::exists('roles')->where(...)`    | `->exists('roles', 'col', fn ($r) => $r->where(...))`  |

**Conditional callables**

| Laravel's `Rule`                            | FluentRule equivalent                       |
|---------------------------------------------|---------------------------------------------|
| `Rule::when($cond, $rules, $default)`       | `->when($cond, fn ($r) => …, fn ($r) => …)` |
| `Rule::unless($cond, $rules, $default)`     | `->when(! $cond, …)`                        |
| `Rule::requiredIf(fn () => …)`              | `->requiredIf(fn () => …)`                  |
| `Rule::requiredUnless(fn () => …)`          | `->requiredUnless(fn () => …)`              |
| `Rule::excludeIf(fn () => …)`               | `->excludeIf(fn () => …)`                   |
| `Rule::excludeUnless(fn () => …)`           | `->excludeUnless(fn () => …)`               |
| `Rule::prohibitedIf(fn () => …)`            | `->prohibitedIf(fn () => …)`                |
| `Rule::prohibitedUnless(fn () => …)`        | `->prohibitedUnless(fn () => …)`            |

**Iteration**

| Laravel's `Rule`                  | FluentRule equivalent                                |
|-----------------------------------|------------------------------------------------------|
| `Rule::forEach(fn ($v, $k) => …)` | `FluentRule::array()->each(FluentRule::string()->…)` |

**Authorization (escape hatch only)**

| Laravel's `Rule`                  | FluentRule equivalent                                |
|-----------------------------------|------------------------------------------------------|
| `Rule::can('ability', …$args)`    | `->rule(['can', 'ability', …$args])`                 |

**FluentRule additions with no Laravel equivalent**

| Method                                      | What it does                            |
|---------------------------------------------|-----------------------------------------|
| `->each([key => FluentRule, …])`            | Co-locate wildcard child rules          |
| `->children([key => FluentRule, …])`        | Co-locate fixed-key child rules         |
| `->label('Full Name')`                      | Replaces `:attribute` in messages       |
| `->message('…')` / `->messageFor('…', '…')` | Per-rule custom messages                |
| `->fieldMessage('…')`                       | Field-level fallback message            |
| `->whenInput(fn ($input) => …)`             | Branch on full input at validation time |

</details>
