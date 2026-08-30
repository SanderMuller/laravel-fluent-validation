# Benchmarks

Measured numbers for the optimizations described in [Performance](09-performance.md).

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

</details>
