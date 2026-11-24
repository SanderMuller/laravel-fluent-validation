# Benchmarks

Measured numbers for the optimizations described in [Performance](09-performance.md).

| Scenario                                                                    | Optimizations                          | Native Laravel | Optimized   | Speedup |
|-----------------------------------------------------------------------------|----------------------------------------|----------------|-------------|---------|
| [Product import](#product-import), 500 items, simple rules                  | Wildcard, fast-check                   | ~93ms          | **~0.6ms**  | ~161x   |
| [Nested order lines](#nested-order-lines), 1000 orders × 5 line items       | Wildcard, fast-check (nested)          | ~476ms         | **~2ms**    | ~244x   |
| [Conditional import](#conditional-import), 100 items, 47 conditional fields | Wildcard, pre-evaluation               | ~143ms         | **~1.3ms**  | ~108x   |
| [Event scheduling](#event-scheduling), 100 items, field-ref dates           | Wildcard, fast-check (field-ref dates) | ~14ms          | **~0.5ms**  | ~26x    |
| [Article submission](#article-submission), 50 items, custom Rule objects    | Wildcard, fast-check (custom objects)  | ~5ms           | **~0.2ms**  | ~34x    |
| [Login form](#login-form), 3 fields, no wildcards                           | Fast-check (flat)                      | ~0.1ms         | **~0.01ms** | ~11x    |

All numbers are from `php benchmark.php` (macOS, PHP 8.5, OPcache), which measures `RuleSet::validate()`; CI runs produce the same scenarios on Ubuntu. A form request with `HasFluentRules` gains less on the same scenarios: about 24x for the product import, 17x for the nested order lines, 6x for the conditional import and 3x for the article submission.

## Instruction counts

Wall-clock times depend on the machine, so CI also counts the CPU instructions of each benchmark on Linux with `phpstan/tally`, after one warm-up call that is not counted. The warm-up call also fills the caches that the package keeps between calls in one process, so the counts show repeated validation, as in an import loop or a queue worker. When a change raises a count above its allowed tolerance, the Tally job fails until the new numbers are accepted. The table shows the accepted numbers from `benchmarks/*.php`. The form request import and the conditional form request go through `HasFluentRules`, and the other fluent counts come from `RuleSet::validate()`. The native counts come from `Validator::make(...)->validate()` with the same rules, compiled to Laravel's own format.

<!-- tally-table -->

<a id="on-wildcard-expansion"></a>

## Benchmark scenarios

<details>
<summary>The six rule sets behind the numbers above</summary>

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

An excerpt of the same rules in fluent form:

```php
'interactions' => FluentRule::array()->required()->min(1)->each([
    'type'                   => FluentRule::string()->required()->in(['button', 'hotspot', 'scroll_area', 'image', 'chapter', 'menu', 'frame', 'text', 'iframe']),
    'title'                  => FluentRule::string()->nullable(),
    'start_time'             => FluentRule::numeric()->required()->min(0),
    'end_time'               => FluentRule::numeric()->required()->greaterThanOrEqualTo('interactions.*.start_time'),
    'should_start_collapsed' => FluentRule::boolean()->excludeUnless('interactions.*.type', 'chapter'),
    'position'               => FluentRule::string()->bail()->excludeUnless('interactions.*.type', 'chapter', 'menu'),
    // ...
]),
```

**Optimizations**: O(n) wildcard expansion + pre-evaluation removes ~95% of rules before validation starts.

### Event scheduling

100 events with date fields. Both literal date comparisons and wildcard-sibling field references fast-check.

```php
'events'                        => FluentRule::array()->required()->each([
    'name'                      => FluentRule::string()->required()->min(3)->max(255),
    'start_date'                => FluentRule::date()->required()->after('2025-01-01'),        // literal → fast-checked
    'end_date'                  => FluentRule::date()->required()->after('events.*.start_date'),  // field ref → fast-checked
    'registration_deadline'     => FluentRule::date()->required()->before('events.*.start_date'), // field ref → fast-checked
]),
```

**Optimizations**: O(n) wildcard expansion + fast-check for every field. Sibling field references (`after:events.*.start_date`, `before:events.*.start_date`) resolve against the current wildcard item at call time via a second closure variant, so date comparisons don't fall through to Laravel.

### Article submission

50 articles where most rules are custom `ValidationRule` objects. The fast check runs plain strings and calls each object's `validate()` directly, so a passing item never reaches Laravel's validator.

```php
'articles'                      => FluentRule::array()->required()->each([
    'title'                     => FluentRule::string()->required()->min(3)->max(255),
    'slug'                      => FluentRule::string()->required()->alphaDash()->max(255),
    'content'                   => ['required', 'string', new MinimumWordCount(100)],
    'category'                  => ['required', new ValidCategory()],
    'priority'                  => ['required', new ValidPriority()],
]),
```

**Optimizations**: O(n) wildcard expansion + fast-check closures that call the custom Rule objects. An object that reads the validator or its data (`DataAwareRule`, `ValidatorAwareRule`) or is implicit stays with Laravel.

### Login form

3 fields, no wildcards. All three rules are fully fast-checkable, so `RuleSet::validate()` skips Laravel's validator entirely when values pass.

```php
'email'    => FluentRule::email()->required()->max(255),
'password' => FluentRule::string()->required()->min(8),
'remember' => FluentRule::boolean()->nullable(),
```

**Optimizations**: Fast-check closures for all three fields, with the compiled closures cached by rule string so repeat FormRequest validations skip recompilation. Absolute savings are small (~0.1ms → ~0.01ms), but the relative speedup is ~11x since a simple form doesn't give Laravel much wildcard work to amortize against.

</details>
