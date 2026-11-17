# Validating with a RuleSet

`validate()` throws `ValidationException` on failure. `check()` returns the outcome as data. Both take an `array` or an `Illuminate\Http\Request`; passing the request satisfies static-analysis rules that flag unsafe input access. The options below chain before either call.

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

    $safe = $result->safe();        // ValidatedInput, gives you ->only(), ->except(), ->collect(), ->file(), ->files()
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

`safe()` returns the package's `ValidatedInput`, a subclass of Laravel's. It adds `->file($key)`, which returns the `UploadedFile`, or the default when that is a file, or `null`, and `->files($key)`, which returns a list of the `UploadedFile`s under the key. `->file()` comes with Laravel 13.3 too; the subclass gives it to older versions. A form request that uses `HasFluentRules` returns the same class from `$request->safe()`.

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

`dropUnknownFields()` removes unknown keys from the `validated()` output instead of rejecting them. Laravel already leaves out unknown top-level keys; this also covers arrays declared with `children()`, `each()` or dotted keys:

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

When both are set, `failOnUnknownFields()` wins.

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

`->withBag($name)` sets the error bag of the thrown `ValidationException`, for pages with more than one form:

```php
RuleSet::from([
    'current_password' => FluentRule::string()->required()->currentPassword(),
    'password'         => FluentRule::string()->required()->min(12),
])
    ->withBag('updatePassword')
    ->validate($input);
```

It works like Laravel's `validateWithBag()`. `check()` does not throw, so the bag does not apply to it.
