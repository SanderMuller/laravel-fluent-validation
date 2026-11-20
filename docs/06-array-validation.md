# Array validation with `each()` and `children()`

|                | `each()`                                           | `children()`                                 |
|----------------|----------------------------------------------------|----------------------------------------------|
| **Data shape** | Array of items (`[{...}, {...}, ...]`)             | Single object with known keys (`{key: ...}`) |
| **Produces**   | Wildcard paths (`items.*.name`)                    | Fixed paths (`search.value`)                 |
| **Use when**   | You have a list of N items with the same structure | You have one object with specific sub-keys   |

To validate each item in an array, use the `each()` method:

```php
// Scalar items: each tag must be a string under 255 characters
FluentRule::array()->each(FluentRule::string()->max(255))

// Object items: each item has named fields
FluentRule::array()->required()->each([
    'name'  => FluentRule::string('Item Name')->required(),
    'email' => FluentRule::email()->required(),
    'qty'   => FluentRule::numeric()->required()->integer()->min(1),
])

// Nested arrays
FluentRule::array()->each([
    'items' => FluentRule::array()->each([
        'qty' => FluentRule::numeric()->required()->min(1),
    ]),
])
```

`each()` works standalone and through form requests with `HasFluentRules`. The trait and [`RuleSet`](11-ruleset.md) both [optimize wildcard expansion](09-performance.md).

To compare with another field of the same item, use its full wildcard path: `->after('items.*.starts_at')`, `->requiredIf('items.*.type', 'digital')`. That form means the same in a form request and in a `RuleSet`. In a form request, a bare name such as `->after('starts_at')` means the top-level field, as in native Laravel.

```php
'items' => FluentRule::array()->required()->each([
    'type'      => FluentRule::string()->required()->in(['digital', 'physical']),
    'starts_at' => FluentRule::date()->required(),
    'ends_at'   => FluentRule::date()->required()->after('items.*.starts_at'),
    'url'       => FluentRule::string()->url()->requiredIf('items.*.type', 'digital'),
    'weight'    => FluentRule::numeric()->excludeUnless('items.*.type', 'physical')->required()->min(0),
]),
```

Each item is checked against its own fields: a digital item needs a `url`, and only a physical item gets its `weight` validated.

> [!TIP]
> **Catch unbounded `each()` at analyse time.** The companion [PHPStan package](16-static-analysis.md) flags `each()` chains without a size cap (`->max()`, `->between()`, `->exactly()`, or a key whitelist). That's the shape that turns into an N+1 / DoS footgun with `->exists()` or closure rules on large payloads.

## Fixed-key children with `children()`

When validating an object with known keys (not a wildcard array), you may use `children()` to keep child rules with their parent:

```php
// Instead of:
'search'       => FluentRule::array()->required(),
'search.value' => FluentRule::string()->nullable(),
'search.regex' => FluentRule::string()->nullable()->in(['true', 'false']),

// Write:
'search' => FluentRule::array()->required()->children([
    'value' => FluentRule::string()->nullable(),
    'regex' => FluentRule::string()->nullable()->in(['true', 'false']),
]),
```

`children()` produces fixed paths (`search.value`), while `each()` produces wildcard paths (`items.*.name`). `children()` is also available on `FluentRule::field()` for untyped fields with known sub-keys.

## Combining `each()` and `children()`

Both may be used together on the same array. This example validates a datatable with columns that have nested search and render options:

```php
'columns' => FluentRule::array()->required()->each([
    'data' => FluentRule::field()->nullable()
        ->rule(FluentRule::anyOf([FluentRule::string(), FluentRule::array()]))
        ->children([
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

The rule tree mirrors the data shape. Compare this with the flat dot-notation alternative: `columns.*.data`, `columns.*.data.sort`, `columns.*.data.render.display`, `columns.*.search.value`, each defined separately.

> [!TIP]
> **Using Boost?**  
> The `fluent-validation-optimize` skill automatically detects flat dot-notation keys that can be grouped with `each()` and `children()`, and converts them for you.
