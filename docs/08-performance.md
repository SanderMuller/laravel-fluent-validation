# Performance

The win is real for endpoints that validate **a lot of fields** or **a lot of items at once**: CSV/JSON ingest, bulk-edit, settings pages, anywhere a single request hits wildcard arrays like `items.*.id` or `orders.*.line_items.*.product_id`. On a 3-field login form FluentRule is still faster than native, but you won't notice; the saving is in microseconds.

When you use one of the optimized entry points (`HasFluentRules` on a FormRequest, `HasFluentValidation` on a Livewire component, `FluentValidator`, or `RuleSet::validate()`), FluentRule objects compile down to native Laravel format before validation runs and pick up five extra optimizations:

- [**O(n) wildcard expansion**](#on-wildcard-expansion): replaces Laravel's O(n²) `Arr::dot()` + regex expansion with a single tree walk
- [**Pre-evaluation of conditional rules**](#pre-evaluation-of-conditional-rules): resolves `exclude_unless`/`exclude_if` before validation and removes excluded attributes from the rule set
- [**Fast-check closures**](#fast-check-closures): compiles 30+ common rules into PHP closures that skip Laravel's validator entirely for passing values
- [**Batched database validation**](#batched-database-validation): turns N `exists`/`unique` queries into a single `whereIn`
- [**Rule-parse memoization**](#rule-parse-memoization): caches Laravel's rule-string parsing worker-wide so the residual slow path — rules that fall through fast-check — parses each string once instead of on every internal probe and every array item

## Benchmarks

| Scenario                                                                    | Optimizations                          | Native Laravel | Optimized   | Speedup |
|-----------------------------------------------------------------------------|----------------------------------------|----------------|-------------|---------|
| [Product import](#product-import), 500 items, simple rules                  | Wildcard, fast-check                   | ~163ms         | **~3ms**    | ~62x    |
| [Nested order lines](#nested-order-lines), 1000 orders × 5 line items       | Wildcard, fast-check (nested)          | ~2,491ms       | **~15ms**   | ~163x   |
| [Conditional import](#conditional-import), 100 items, 47 conditional fields | Wildcard, pre-evaluation               | ~2,928ms       | **~47ms**   | ~62x    |
| [Event scheduling](#event-scheduling), 100 items, field-ref dates           | Wildcard, fast-check (field-ref dates) | ~19ms          | **~0.7ms**  | ~28x    |
| [Article submission](#article-submission), 50 items, custom Rule objects    | Wildcard only                          | ~8ms           | **~2ms**    | ~3x     |
| [Login form](#login-form), 3 fields, no wildcards                           | Fast-check (flat)                      | ~0.1ms         | **~0.01ms** | ~12x    |

All numbers are from `php benchmark.php` (macOS, PHP 8.4, OPcache); CI runs produce the same scenarios on Ubuntu.

<a id="on-wildcard-expansion"></a>

## O(n) wildcard expansion

Laravel's `explodeWildcardRules()` flattens data with `Arr::dot()` and matches regex patterns against every key. For each wildcard rule, it scans every key in the flattened array, making the expansion O(n²). The package replaces this with a tree traversal that walks the data once and emits concrete paths as it descends.

## Pre-evaluation of conditional rules

Rules like `exclude_unless` and `exclude_if` are evaluated before the validator starts. Excluded attributes are removed from the rule set entirely, so the validator only sees the rules that actually apply. For a payload with 100 items and 47 conditional fields, this reduces the rule set from ~4,700 to ~200.

## Fast-check closures

The package compiles 30+ common rules into PHP closures that bypass Laravel's validator when values pass. Coverage:

- **Type checks:** `string`, `numeric`, `email`, `date`, `array`, `boolean`, `in`, `regex`
- **Presence gates:** `required`, `prohibited`
- **Date / size / equality comparisons:** literal dates plus wildcard-sibling references (`after:start_date`, `gte:min_price`, `same:password`, `confirmed`)
- **Presence-conditional:** `required_with`, `required_without`, `required_with_all`, `required_without_all`
- **Value-conditional:** `required_if`, `required_unless`, `prohibited_if`, `prohibited_unless`

The two conditional families are pre-evaluated per item against the current row's data: rewritten to bare `required`/`prohibited` when active, or dropped when inactive, so the remainder of the chain fast-checks normally. Dotted dependent paths (`required_without:profile.birthdate`, `required_if:profile.role,admin`) are resolved via `data_get` against the item during reduction.

What the closure does is simpler than what Laravel does. A `string|max:255` rule becomes `is_string($v) && strlen($v) <= 255`. No rule parsing, no method dispatch, no `BigNumber` size comparison. Values that pass never touch the validator. Values that fail fall through to Laravel so the error message stays identical, with no custom-formatting layer to maintain.

Rules that can't be fast-checked (custom Rule objects, closures, `distinct`, `exists`/`unique` with closure callbacks) go through Laravel as normal.

Fast-checks apply to both wildcard rules (`items.*.name`) and flat top-level rules. A simple `RuleSet::from(['name' => 'string|max:255'])->validate($data)` skips Laravel's validator entirely when the value passes.

## Batched database validation

When wildcard arrays use `exists` or `unique` rules, Laravel fires one database query per item. 500 items means 500 queries. `HasFluentRules` and `RuleSet::validate()` batch these into a single `whereIn` query automatically.

Rules with scalar `where()` clauses are batched too. Rules with closure callbacks fall through to per-item validation. Batching is transparent: error messages, custom messages, and `validated()` output are unchanged.

DB batching impact depends on driver and network latency; it is measured in the test suite (`--group=benchmark`) rather than in `benchmark.php`.

**Guards against hostile input.** Because values are batched from raw input before per-item rules run, batching is protected by three layered safeguards so a 100k-element POST body cannot trigger a hundred `whereIn` queries or crash a strict database:

- **Parent `max:N` is honoured.** If the parent array is declared `max:100` but the request sends 1_000 items, batching short-circuits before any query runs, and you see a normal `ValidationException` on the parent attribute. Only the *immediate* parent's `max:N` is inspected (not `size:N`, `between:a,b`, or outer ancestors in nested-wildcard chains). The check also assumes numerically-indexed wildcards (`items.0.id`, `orders.0.items.0.id`); if your API accepts string-keyed collections (`{"items": {"foo": {...}}}`), rely on the hard cap below for defence-in-depth.
- **Per-item type rules filter the batch.** `integer`, `numeric`, `uuid`, `ulid`, `string` rules on each item drop values that couldn't pass validation anyway, so malformed input like `{"id": "abc"}` never reaches a PostgreSQL `INTEGER` column (which would otherwise raise `invalid input syntax for type integer`). End-user error semantics are unchanged; the per-item rule still reports the error.
- **Hard cap.** `BatchDatabaseChecker::$maxValuesPerGroup` (default `10_000`) is a defence-in-depth ceiling per `(table, column, rule-type)` group. Exceeding it throws `SanderMuller\FluentValidation\Exceptions\BatchLimitExceededException`, which the trait and `RuleSet::validate()` / `check()` remap to the standard `ValidationException`. Override once during boot if your legitimate bulk-import endpoints need more headroom:

```php
// app/Providers/AppServiceProvider.php
use SanderMuller\FluentValidation\BatchDatabaseChecker;

public function boot(): void
{
    BatchDatabaseChecker::$maxValuesPerGroup = 50_000;
}
```

Power users who want to handle `parent-max` and `hard-cap` differently (e.g. map to HTTP 413) can catch `BatchLimitExceededException` before the remap; it carries `$reason`, `$ruleType`, `$valueCount`, `$limit`, and `$attribute` for routing decisions.

## Rule-parse memoization

Laravel re-parses each string rule (`max:255` → `['Max', ['255']]`) on every internal probe — `hasRule`, `isValidatable`, dependent-field checks — so a single `passes()` re-parses the same string many times, and a validator reused across array items pays that cost per item. The optimized entry points memoize each parse in a worker-global static, collapsing the repeats to one hash lookup. Output stays byte-identical; only string rules are cached (object rules and closures parse live, exactly as Laravel does). On a large array whose per-item rules fall through to Laravel — custom `Rule` objects, cross-field references — this roughly halves the residual validation time.

The cache is a bounded, pure memoization: soft-capped and reset on overflow, so long-running workers can't grow it without bound, and it holds only rule-string → parse-result pairs, never request data. If your app registers a custom `Validator::resolver()`, the per-item path uses that validator unchanged, so any resolver-provided behaviour is preserved.

## `RuleSet::validate()`

For inline validation outside form requests, `RuleSet::validate()` applies the same optimizations:

```php
$validated = RuleSet::from([
    'items' => FluentRule::array()->required()->each([
        'name' => FluentRule::string('Item Name')->required()->min(2),
        'qty'  => FluentRule::numeric()->required()->integer()->min(1),
    ]),
])->validate($request->all());
```

Benchmarks run automatically on PRs via GitHub Actions. All optimizations are Octane-safe: the shared validation factory's resolver is never mutated, and the one piece of cross-request state — the [rule-parse cache](#rule-parse-memoization) — is a bounded, pure memoization (soft-capped, reset on overflow) that holds no request data.

## Benchmark scenarios

### Product import

500 products with simple, fully fast-checkable rules. All fields pass through PHP closures without touching Laravel's validator.

```php
'products'              => FluentRule::array()->required()->each([
    'sku'               => FluentRule::string()->required()->max(50)->regex('/^SKU-/'),
    'name'              => FluentRule::string()->required()->min(2)->max(255),
    'price'             => FluentRule::numeric()->required()->min(0),
    'quantity'           => FluentRule::numeric()->required()->integer()->min(0),
    'category'          => FluentRule::string()->required()->in(['electronics', 'clothing', 'food']),
    'active'            => FluentRule::boolean()->required(),
    'tags'              => FluentRule::string()->nullable()->max(50),
]),
```

**Optimizations**: O(n) wildcard expansion + fast-check closures for all fields.

### Nested order lines

1000 orders, each with 5 line items. Nested wildcards (`orders.*.line_items.*.product_id`) are expanded within the per-item closure.

```php
'orders'                            => FluentRule::array()->required()->each([
    'order_number'                  => FluentRule::string()->required()->alphaDash()->min(5),
    'status'                        => FluentRule::string()->required()->in(['pending', 'processing', 'shipped']),
    'line_items'                    => FluentRule::array()->required()->each([
        'product_id'                => FluentRule::numeric()->required()->integer(),
        'quantity'                  => FluentRule::numeric()->required()->integer()->min(1),
        'price'                     => FluentRule::numeric()->required()->min(0.01),
    ]),
]),
```

**Optimizations**: O(n) wildcard expansion + fast-check closures, including the nested level.

### Conditional import

100 interactive media items with 47 wildcard patterns. Most fields use `exclude_unless` to conditionally apply rules based on the item's `type` field. Only ~4 fields apply per item type out of 47 total.

```php
// String rules work through the same optimization path as FluentRule objects.
'interactions'                                          => 'required|array|min:1',
'interactions.*.type'                                   => ['required', 'string', Rule::in([...])],
'interactions.*.title'                                  => ['nullable', 'string'],
'interactions.*.start_time'                             => ['required', 'numeric', 'min:0'],
'interactions.*.end_time'                               => ['required', 'numeric', 'gte:interactions.*.start_time'],
// Only validated when type = 'chapter':
'interactions.*.should_start_collapsed'                 => [['exclude_unless', 'interactions.*.type', 'chapter'], 'boolean'],
'interactions.*.should_collapse_after_menu_item_click'  => [['exclude_unless', 'interactions.*.type', 'chapter'], 'boolean'],
// Only validated when type = 'chapter' or 'menu':
'interactions.*.position'                               => ['bail', ['exclude_unless', 'interactions.*.type', 'chapter', 'menu'], 'string'],
// ... 40+ more conditional fields across 9 interaction types
```

**Optimizations**: O(n) wildcard expansion + pre-evaluation removes ~95% of rules before validation starts.

### Event scheduling

100 events with date fields. Both literal date comparisons and wildcard-sibling field references fast-check.

```php
'events'                        => FluentRule::array()->required()->each([
    'name'                      => FluentRule::string()->required()->min(3)->max(255),
    'start_date'                => FluentRule::date()->required()->after('2025-01-01'),        // literal → fast-checked
    'end_date'                  => FluentRule::date()->required()->after('start_date'),          // field ref → fast-checked
    'registration_deadline'     => FluentRule::date()->required()->before('start_date'),         // field ref → fast-checked
]),
```

**Optimizations**: O(n) wildcard expansion + fast-check for every field. Sibling field references (`after:start_date`, `before:start_date`) resolve against the current wildcard item at call time via a second closure variant, so date comparisons don't fall through to Laravel.

### Article submission

50 articles where most rules are custom `ValidationRule` objects. Custom objects bypass fast-check compilation entirely, so only the wildcard expansion helps.

```php
'articles'                      => FluentRule::array()->required()->each([
    'title'                     => FluentRule::string()->required()->min(3)->max(255),
    'slug'                      => FluentRule::string()->required()->alphaDash()->max(255),
    'content'                   => ['required', 'string', new MinimumWordCount(100)],
    'category'                  => ['required', new ValidCategory()],
    'priority'                  => ['required', new ValidPriority()],
]),
```

**Optimizations**: O(n) wildcard expansion only. Custom Rule objects bypass fast-check compilation, so most fields go through Laravel's validator.

### Login form

3 fields, no wildcards. All three rules are fully fast-checkable, so `RuleSet::validate()` skips Laravel's validator entirely when values pass.

```php
'email'    => FluentRule::email()->required()->max(255),
'password' => FluentRule::string()->required()->min(8),
'remember' => FluentRule::boolean()->nullable(),
```

**Optimizations**: Fast-check closures for all three fields, with the compiled closures cached by rule string so repeat FormRequest validations skip recompilation. Absolute savings are small (~0.1ms → ~0.01ms), but the relative speedup is ~12x since a simple form doesn't give Laravel much wildcard work to amortize against.

## When this won't help

The performance optimizations target wildcard array validation. These cases see little or no speedup:

- **`gt`/`gte`/`lt`/`lte` without a type flag.** Laravel derives comparison type from an accompanying rule (`string`/`array`/`numeric`/`integer`). Without one, these fall through to Laravel. With a type flag, sibling-field comparisons like `numeric|gt:min_price` are fast-checked.
- **`date_format` + date field-ref.** Laravel parses both sides with the declared format and has lenient missing-ref handling our strtotime-based closure can't match. Falls through to Laravel.
- **Multi-param `different:a,b,c`.** Single-field `different:a` is fast-checked; comma-list forms fall through.
- **Custom `ValidationRule` objects and closures.** Opaque to the fast-check compiler. Performance depends on what the rule does.
- **`distinct` rules.** Require comparing values across all items in the array, not per-item.
- **Database rules with closure callbacks** (`exists`/`unique` with `->where(fn ...)`). Can't be batched; each item fires its own query.

If you're not sure whether validation is your bottleneck, profile first. Laravel Telescope shows total request time breakdowns.

> [!TIP]
> **Using Boost?**  
> The `fluent-validation-optimize` skill finds form requests with wildcard rules that are missing `HasFluentRules`, prioritizes them by impact, and adds the trait automatically.
