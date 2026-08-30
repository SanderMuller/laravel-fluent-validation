# Performance

The measured numbers are on [Benchmarks](10-benchmarks.md); this page is the mechanisms behind them.

The win is real for endpoints that validate **a lot of fields** or **a lot of items at once**: CSV/JSON ingest, bulk-edit, settings pages, anywhere a single request hits wildcard arrays like `items.*.id` or `orders.*.line_items.*.product_id`. On a 3-field login form FluentRule is still faster than native, but you won't notice; the saving is in microseconds.

When you use one of the optimized entry points (`HasFluentRules` on a FormRequest, `HasFluentValidation` on a Livewire component, `FluentValidator`, or `RuleSet::validate()`), FluentRule objects compile down to native Laravel format before validation runs and pick up five extra optimizations:

- [**O(n) wildcard expansion**](#on-wildcard-expansion): replaces Laravel's O(n²) `Arr::dot()` + regex expansion with a single tree walk
- [**Pre-evaluation of conditional rules**](#pre-evaluation-of-conditional-rules): resolves `exclude_unless`/`exclude_if` before validation and removes excluded attributes from the rule set
- [**Fast-check closures**](#fast-check-closures): compiles 30+ common rules into PHP closures that skip Laravel's validator entirely for passing values
- [**Batched database validation**](#batched-database-validation): turns N `exists`/`unique` queries into a single `whereIn`
- [**Rule-parse memoization**](#rule-parse-memoization): caches Laravel's rule-string parsing worker-wide so the residual slow path (rules that fall through fast-check) parses each string once instead of on every internal probe and every array item

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

Laravel re-parses each string rule (`max:255` → `['Max', ['255']]`) on every internal probe: `hasRule`, `isValidatable`, dependent-field checks. One `passes()` re-parses the same string many times, and a validator reused across array items pays that cost per item.

The optimized entry points memoize each parse in a worker-global static, so the repeats collapse to one hash lookup. Output stays byte-identical, and only string rules are cached; object rules and closures parse live, as in Laravel. On a large array whose per-item rules fall through to Laravel, this roughly halves the residual time.

The cache is bounded and pure: soft-capped, reset on overflow, holding only rule-string to parse-result pairs and never request data. A custom `Validator::resolver()` is used unchanged on the per-item path, so resolver behaviour is preserved.

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

Benchmarks run automatically on PRs via GitHub Actions. All optimizations are Octane-safe: the shared validation factory's resolver is never mutated, and the one piece of cross-request state (the [rule-parse cache](#rule-parse-memoization)) is a bounded, pure memoization (soft-capped, reset on overflow) that holds no request data.

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
