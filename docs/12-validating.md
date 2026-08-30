# Validating with a RuleSet

`validate()` and `check()` are the terminal calls on a [RuleSet](11-ruleset.md). This page covers both, and the per-call options that chain before them.

`validate()` throws `ValidationException` on failure; `check()` returns the outcome as data. Both take an `array` or an `Illuminate\Http\Request`, and passing the request keeps the `$request->all()` read inside the library, which satisfies static-analysis rules that flag unsafe input access. `failOnUnknownFields`, `dropUnknownFields`, `stopOnFirstFailure` and `withBag` chain before either terminal call.

## Errors-as-data with `check()`

For import pipelines, batch jobs, and any flow where an exception is the wrong control structure. Returns an immutable `Validated`:

```php
use SanderMuller\FluentValidation\RuleSet;

foreach ($rows as $row) {
    $result = RuleSet::from($rules)->check($row);

    if ($result->fails()) {
        Log::warning('row rejected', $result->errors()->all());
        continue;
    }

    $safe = $result->safe();        // Illuminate\Support\ValidatedInput, gives you ->only(), ->except(), ->collect()
    $array = $result->validated();  // plain array (throws if the result failed)
    insert_row($safe->all());
}
```

| Method                 | Returns             | Description                                                                          |
|------------------------|---------------------|--------------------------------------------------------------------------------------|
| `->passes()`           | `bool`              | Did validation pass?                                                                 |
| `->fails()`            | `bool`              | Inverse of `passes()`                                                                |
| `->errors()`           | `MessageBag`        | All validation errors (empty bag on success)                                         |
| `->firstError($field)` | `?string`           | First error message for a field, or `null`                                           |
| `->validated()`        | `array`             | Validated data; throws `ValidationException` if it failed                            |
| `->safe()`             | `ValidatedInput`    | Same data as `validated()`, wrapped for `->only()`/`->except()`/`->collect()` access |
| `->validator()`        | `ValidatorContract` | Escape hatch for deep Laravel integration (`->after()`, `->sometimes()`, extensions) |

`check()` runs the same engine as `validate()` (fast-check closures, wildcard expansion, batched DB queries) and wraps the outcome rather than re-parsing.

## Rejecting unknown fields

`failOnUnknownFields()` rejects input keys that don't match any rule in the set. If someone sends `role` when you only defined `name` and `email`, validation fails:

```php
$validated = RuleSet::from([
    'name'  => FluentRule::string()->required(),
    'email' => FluentRule::email()->required(),
])->failOnUnknownFields()->validate($request->all());
// Input: ['name' => 'John', 'email' => 'john@example.com', 'role' => 'admin']
// → ValidationException: "The role field is prohibited."
```

Wildcard arrays are checked too. `items.0.hack` fails if only `items.*.name` is defined. You can customize the error message per field:

```php
->validate($data, messages: ['role.prohibited' => 'This field is not allowed.']);
```

> [!TIP]
> For form requests, Laravel 13.4+ has a native `#[FailOnUnknownFields]` attribute that works automatically with `HasFluentRules`.

## Silently dropping unknown fields

`dropUnknownFields()` is the lenient counterpart to `failOnUnknownFields()`: instead of rejecting unknown keys, it strips them from the `validated()` output. Top-level keys outside the rule set are already excluded; this flag extends the same behavior to nested array shapes declared via `children()`, `each()`, or dotted rule keys:

```php
$validated = RuleSet::from([
    'name' => FluentRule::string()->required(),
    'meta' => FluentRule::array()->required()->children([
        'type' => FluentRule::string()->required(),
    ]),
])->dropUnknownFields()->validate($request);
// Input:  ['name' => 'John', 'meta' => ['type' => 'admin', 'secret' => 'leak']]
// Output: ['name' => 'John', 'meta' => ['type' => 'admin']]
```

If both `dropUnknownFields()` and `failOnUnknownFields()` are set, `failOnUnknownFields()` wins: unknown keys trigger a validation error before the drop ever applies.

## Stopping on first failure

`stopOnFirstFailure()` bails after the first field error. If the file upload fails, the 500 `exists` queries for items never run:

```php
$validated = RuleSet::from([
    'file'   => FluentRule::file()->required()->max('10mb'),
    'items'  => FluentRule::array()->required()->each([
        'sku' => FluentRule::string()->required()->exists('products', 'sku'),
    ]),
])->stopOnFirstFailure()->validate($request->all());
```

The same applies inside wildcard arrays. If the first item fails, the rest are skipped.

## Named error bags (`withBag`)

Multiple forms on one page (Fortify's update-password + reset-password, a Livewire multi-card screen, etc.) need separate error bags so their validation errors don't collide. Chain `->withBag($name)` on the rule set; the thrown `ValidationException`'s `errorBag` is set to that name:

```php
RuleSet::from([
    'current_password' => FluentRule::string()->required()->currentPassword(),
    'password'         => FluentRule::string()->required()->min(12),
])
    ->withBag('updatePassword')
    ->validate($input);
```

Mirrors Laravel's `Validator::validateWithBag()` without forcing you back to the `Validator::make(...)` incantation. Only affects the thrown exception's bag; `check()` never throws and is unaffected.
