# Performance

The measured numbers are on [Benchmarks](10-benchmarks.md); this page is the mechanisms behind them.

The gain is large for requests with many fields or wildcard arrays, such as imports and bulk edits. On a 3-field form it is microseconds.

The optimized entry points are `HasFluentRules` on a form request, `HasFluentValidation` on a Livewire component, `FluentValidator`, and `RuleSet::validate()`. They add five optimizations:

- [**O(n) wildcard expansion**](#on-wildcard-expansion): replaces Laravel's O(n²) `Arr::dot()` + regex expansion with a single tree walk
- [**Pre-evaluation of conditional rules**](#pre-evaluation-of-conditional-rules): resolves `exclude_unless`/`exclude_if` before validation and removes excluded attributes from the rule set
- [**Fast-check closures**](#fast-check-closures): compiles 30+ common rules into PHP closures that skip Laravel's validator entirely for passing values
- [**Batched database validation**](#batched-database-validation): turns N `exists`/`unique` queries into a single `whereIn`
- [**Rule-parse memoization**](#rule-parse-memoization): parses each rule string once per worker for the rules that fall through to Laravel

## O(n) wildcard expansion

Laravel's `explodeWildcardRules()` flattens data with `Arr::dot()` and matches regex patterns against every key. For each wildcard rule, it scans every key in the flattened array, making the expansion O(n²). The package walks the data once instead.

## Pre-evaluation of conditional rules

Rules like `exclude_unless` and `exclude_if` are evaluated before the validator starts. Excluded attributes are removed from the rule set entirely, so the validator only sees the rules that actually apply. For a payload with 100 items and 47 conditional fields, this reduces the rule set from ~4,700 to ~200.

For fields inside `each()`, `RuleSet::validate()` gives the fluent `->excludeIf()` and `->excludeUnless()` with a field reference the same pre-evaluation, for example `FluentRule::boolean()->excludeUnless('interactions.*.type', 'chapter')`.

## Fast-check closures

The package compiles 30+ common rules into PHP closures that bypass Laravel's validator when values pass. Coverage:

- **Type checks:** `string`, `numeric`, `email`, `date`, `array`, `boolean`, `in`, `regex`
- **Presence gates:** `required`, `prohibited`
- **Date / size / equality comparisons:** literal dates plus wildcard-sibling references (`after:items.*.start_date`, `gte:items.*.min_price`, `same:items.*.password`, `confirmed`)
- **Presence-conditional:** `required_with`, `required_without`, `required_with_all`, `required_without_all`
- **Value-conditional:** `required_if`, `required_unless`, `prohibited_if`, `prohibited_unless`

The conditional rules are resolved per item: they become `required` or `prohibited` when active, and are dropped when not.

A `string|max:255` rule becomes `is_string($v) && strlen($v) <= 255`. Values that pass never reach the validator. Values that fail go to Laravel, so the error messages stay the same. Fast-checks apply to wildcard rules and to flat top-level rules.

With `RuleSet`, a field that mixes rule strings with plain `ValidationRule` objects (`['required', 'string', new MinWords(100)]`) is fast-checked too. Closures, rule objects that read the validator or its data, implicit rule objects, `distinct`, and `exists`/`unique` with closure callbacks go through Laravel.

## Batched database validation

Laravel runs one query per item for `exists` and `unique` on a wildcard array. `HasFluentRules` and `RuleSet::validate()` run one `whereIn` query per distinct query instead, also with scalar `where()` clauses. Error messages and `validated()` output do not change.

These rules are not batched and run per item: closure callbacks, more than one `where()` on the same column, and (on newer Laravel releases) a literal `where()` value of `NULL`, `NOT_NULL` or one that starts with `!`.

**Guards against hostile input.** Batching reads raw input before the per-item rules run. Three safeguards stop a 100k-item POST body from running a hundred `whereIn` queries or crashing a strict database:

- **Parent `max:N` is honoured.** If the parent array is declared `max:100` but the request sends 1_000 items, batching short-circuits before any query runs, and you see a normal `ValidationException` on the parent attribute. Only the *immediate* parent's `max:N` is inspected (not `size:N`, `between:a,b`, or outer ancestors in nested-wildcard chains). The check also assumes numerically-indexed wildcards (`items.0.id`, `orders.0.items.0.id`); if your API accepts string-keyed collections (`{"items": {"foo": {...}}}`), rely on the hard cap below for defence-in-depth.
- **Per-item type rules filter the batch.** `integer`, `numeric`, `uuid`, `ulid`, `string` rules on each item drop values that couldn't pass validation anyway, so malformed input like `{"id": "abc"}` never reaches a PostgreSQL `INTEGER` column (which would otherwise raise `invalid input syntax for type integer`). End-user error semantics are unchanged; the per-item rule still reports the error. This holds when the type rule comes before `exists` or `unique`, as `FluentRule` orders it: Laravel then skips the database rule for a value that failed its type rule. With `exists` first, Laravel queries the value per item, as it does without batching.
- **Hard cap.** `BatchDatabaseChecker::$maxValuesPerGroup` (default `10_000`) is a defence-in-depth ceiling per batched query: one per connection, table, column, rule type, set of `where()` clauses and ignored id. Exceeding it throws `SanderMuller\FluentValidation\Exceptions\BatchLimitExceededException`, which the trait and `RuleSet::validate()` / `check()` remap to the standard `ValidationException`. Override once during boot if your legitimate bulk-import endpoints need more headroom:

```php
// app/Providers/AppServiceProvider.php
use SanderMuller\FluentValidation\BatchDatabaseChecker;

public function boot(): void
{
    BatchDatabaseChecker::$maxValuesPerGroup = 50_000;
}
```

To handle a limit differently, for example as HTTP 413, catch `BatchLimitExceededException` before the remap. It carries `$reason`, `$ruleType`, `$valueCount`, `$limit` and `$attribute`.

## Rule-parse memoization

Laravel parses a rule string such as `max:255` again on every internal check, and again for every array item. The optimized entry points cache each parse in a worker-wide static. Only string rules are cached. On a large array whose rules fall through to Laravel, this roughly halves the remaining time.

All optimizations are Octane-safe. The shared validation factory is never changed. The parse cache is the only state kept across requests: it holds rule strings and their parse results, never request data, and it resets when it reaches its size cap.

## When this won't help

These cases get little or no speedup:

- **`gt`/`gte`/`lt`/`lte` without a type flag.** Laravel derives comparison type from an accompanying rule (`string`/`array`/`numeric`/`integer`). Without one, these fall through to Laravel. With a type flag, sibling-field comparisons like `numeric|gt:min_price` are fast-checked.
- **`date_format` + date field-ref.** Laravel parses both sides with the declared format and has lenient missing-ref handling our strtotime-based closure can't match. Falls through to Laravel.
- **Multi-param `different:a,b,c`.** Single-field `different:a` is fast-checked; comma-list forms fall through.
- **Closures, and custom `ValidationRule` objects outside `RuleSet` or that read the validator or its data.** Opaque to the fast-check compiler. Performance depends on what the rule does.
- **`distinct` rules.** Require comparing values across all items in the array, not per-item.
- **`items.*.*`, and two rule keys that reach the same field.** These run in Laravel's own validator, so the rules merge as they do in Laravel.
- **Database rules with closure callbacks** (`exists`/`unique` with `->where(fn ...)`). Can't be batched; each item fires its own query.

> [!TIP]
> **Using Boost?**  
> The `fluent-validation-optimize` skill finds form requests with wildcard rules that are missing `HasFluentRules`, prioritizes them by impact, and adds the trait automatically.
