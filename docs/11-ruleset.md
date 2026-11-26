# RuleSet

`RuleSet` is an immutable rule container. Use it when the rules are not bound to one HTTP request: inline validation, shared rule libraries, conditional fields, or errors as data.

On this page: [Building](#building-a-rule-set) · [Composing](#composing-rule-sets) · [Inspecting and exporting](#inspecting-and-exporting-a-rule-set) · [Validating](12-validating.md) · [Raw Validator](#integrating-with-a-raw-validator) · [Custom Validators](#using-with-custom-validators) · [Compile pipeline](#compile-pipeline) · [Method reference](#method-reference)

## Building a rule set

Three entry points build the same thing. `from()` takes an array, `make()` adds fields one by one, and `define()` gives you a `FluentSchema` so you can drop the `FluentRule::` prefix.

```php
use SanderMuller\FluentValidation\FluentSchema;
use SanderMuller\FluentValidation\RuleSet;

// From an array
$validated = RuleSet::from([
    'name'  => FluentRule::string('Full Name')->required()->min(2)->max(255),
    'items' => FluentRule::array()->required()->each([
        'name'  => FluentRule::string()->required()->min(2),
        'price' => FluentRule::numeric()->required()->min(0),
    ]),
    'role'        => FluentRule::string()->when($isAdmin, fn ($r) => $r->required()->in(['admin', 'editor'])),
    'permissions' => FluentRule::array()->when($isAdmin, fn ($r) => $r->required()),
])
    ->merge($sharedAddressRules)
    ->validate($request->all());

// Or fluently, with conditional fields and merging
$validated = RuleSet::make()
    ->field('name', FluentRule::string('Full Name')->required())
    ->field('items', FluentRule::array()->required()->each([
        'name'  => FluentRule::string()->required()->min(2),
        'price' => FluentRule::numeric()->required()->min(0),
    ]))
    ->when($isAdmin, fn (RuleSet $set) => $set
        ->field('role', FluentRule::string()->required()->in(['admin', 'editor']))
        ->field('permissions', FluentRule::array()->required())
    )
    ->merge($sharedAddressRules)
    ->validate($request->all());

// Or with a FluentSchema builder — no `FluentRule::` prefix on each line
$validated = RuleSet::define(fn (FluentSchema $rules) => [
    'name'  => $rules->string('Full Name')->required()->min(2)->max(255),
    'email' => $rules->email()->required(),
    'items' => $rules->array()->required()->each([
        'name' => $rules->string()->required(),
    ]),
])->validate($request->all());
```

## Composing rule sets

**Slice and combine**: `merge`, `only`, `except`, `put`, `get`. `merge()` accepts a `RuleSet` or a plain array; later wins on key collision.

```php
return UserRules::base()
    ->only(['email', 'password'])
    ->put('email_confirmation', FluentRule::email()->required()->same('email'));
```

**Read-modify-write**: `modify`, `modifyEach`, `modifyChildren`. Each one works on a shallow clone of the rule, so the stored rule object does not change. Nested `each()` and `children()` rules are not cloned, and the `RuleSet` itself changes in place. `modifyEach()` and `modifyChildren()` merge into an `each([...])` or `children([...])` map. [Extending parent rules](07-extending-rules.md) shows `modify` and `modifyEach`; `modifyChildren` works the same way:

```php
// Parent
return RuleSet::from([
    'address' => FluentRule::field()->required()->children([
        'street' => FluentRule::string()->required(),
        'city'   => FluentRule::string()->required(),
    ]),
]);

// Child — later-wins merge into the children() map
return parent::rules()->modifyChildren('address', [
    'postal_code' => FluentRule::string()->required(),
]);
```

**Conditionals**: `when()` and `unless()` from Laravel's `Conditionable` trait, shown in the first example.

## Inspecting and exporting a rule set

- `toArray()` / `all()`: compiled flat output, ready for `Validator::make()`. `all()` is a Collection-style alias.
- `[...$ruleSet]`: spread via `IteratorAggregate`; yields the `toArray()` shape, so `[...$parent, 'extra' => $rule]` works.
- `isEmpty()`: `true` when no fields are registered.
- `hasObjectRules()`: `true` when at least one field uses `each()` or `children()`.
- `flattenRules()`: the rules in flat dotted and wildcard form:

```php
RuleSet::from([
    'address' => FluentRule::field()->required()->children([
        'street' => FluentRule::string()->required(),
    ]),
    'items' => FluentRule::array()->each([
        'sku' => FluentRule::string()->required(),
    ]),
])->flattenRules();

// [
//     'address'        => FluentRule::field()->required()->children([...]),
//     'address.street' => FluentRule::string()->required(),
//     'items'          => FluentRule::array()->each([...]),
//     'items.*.sku'    => FluentRule::string()->required(),
// ]
```

- `dump()`: returns `['rules' => ..., 'messages' => ..., 'attributes' => ...]` for inspection; does not terminate.
- `dd()`: dumps the same shape and terminates.

## Validating

`validate()` and `check()`, the unknown-field flags, `stopOnFirstFailure()` and `withBag()` have their own page: [Validating with a RuleSet](12-validating.md).

## Integrating with a raw `Validator`

For inspection (`->failed()`, `->errors()`, `->valid()`), `check()` already exposes the underlying `Validator`:

```php
$validator = RuleSet::from($rules)
    ->check($request->all(), $customMessages)
    ->validator();
```

That validator has already run, so a hook you add to it does not change the result of `check()`. For hooks such as `after()` or `sometimes()`, build your own validator: `prepare()` returns the compiled rules, messages and attributes for `Validator::make(...)`. A `distinct` rule on a wildcard field does not work in a validator built that way, because Laravel finds the other items through the wildcard pattern, which `Validator::make()` does not receive. Extend [`FluentValidator`](#using-with-custom-validators) for those rules.

## Using with custom Validators

If your application extends `Illuminate\Validation\Validator` directly (for example, in import jobs), you may extend `FluentValidator` instead:

```php
use SanderMuller\FluentValidation\FluentValidator;

class JsonImportValidator extends FluentValidator
{
    public function __construct(array $data, protected ?User $user = null)
    {
        parent::__construct($data, $this->buildRules());
    }

    private function buildRules(): array
    {
        return [
            '*.type' => FluentRule::string()->required()->in(InteractionType::cases()),
            '*.end_time' => FluentRule::numeric()
                ->requiredUnless('*.type', ...InteractionType::withoutDuration())
                ->greaterThanOrEqualTo('*.start_time'),
        ];
    }
}
```

Cross-field wildcard references such as `requiredUnless('*.type', ...)` work.

`validated()` keeps the array keys that no rule validates, as a Laravel validator does when you build it with `new`. `Validator::make()` leaves them out. To leave them out here too, set `$this->excludeUnvalidatedArrayKeys = true;` after `parent::__construct()`.

If the rules live in a method that is not named `rules()`, mark it with `#[SanderMuller\FluentValidation\FluentRules]` so the [migration Rector rules](https://github.com/sandermuller/laravel-fluent-validation-rector#opting-in-fluentrules-attribute) find it. The attribute does nothing at runtime.

## Compile pipeline

<details>
<summary>Escape hatches for tooling, codegen and framework interop</summary>

For Rector rules, PHPStan extensions and the Livewire bridge. Application code uses `validate()`, `check()`, `prepare()` and `toArray()`.

- `RuleSet::compile($rules)`: compile a fluent-rules array to native Laravel `string|array` rule format. The lowest-level transform.
- `RuleSet::compileToArrays($rules)`: compile to the array-of-rules shape Livewire's `$this->validate()` expects. Used by `HasFluentValidation` under the hood.
- `RuleSet::compileWithMetadata($rules)`: compile alongside extracted custom messages and attribute labels in one pass. For tooling that needs the metadata without re-walking the rule tree.
- `RuleSet::extractMetadata($rules)`: extract `[messages, attributes]` from labelled fluent rules without compiling. For tooling that only wants the metadata side.
- `$set->expandWildcards($data)`: pre-expand wildcard rules against a concrete payload without validating. Useful when generating per-row error keys ahead of time.

</details>

## Method reference

<details>
<summary>Every public method, alphabetically</summary>

| Method | Returns | Description |
|---|---|---|
| `->all()` | `array` | Collection-style alias of `->toArray()`. |
| `->check($data, $messages = [], $attributes = [])` | `Validated` | Validate without throwing. `$data` accepts `array` or `Illuminate\Http\Request`. See [Errors-as-data with `check()`](12-validating.md#errors-as-data-with-check). |
| `RuleSet::compile($rules)` | `array` | Compile fluent rules to native Laravel format. |
| `RuleSet::compileToArrays($rules)` | `array` | Compile to array-of-rules shape for Livewire's `$this->validate()`. |
| `RuleSet::compileWithMetadata($rules)` | `array` | Compile + return extracted messages and attributes in one pass. |
| `->dd()` | `never` | Dump the rule set and terminate. |
| `RuleSet::define(fn ($rules))` | `RuleSet` | Create from a closure given a `FluentSchema` builder; drops the `FluentRule::` prefix. |
| `->dump()` | `array` | Return `{rules, messages, attributes}` for debugging. |
| `->except(...$fields)` | `RuleSet` | Drop the named fields (variadic strings or single array). |
| `->expandWildcards($data)` | `array` | Pre-expand wildcards against `$data` without validating. |
| `RuleSet::extractMetadata($rules)` | `array` | Extract `[messages, attributes]` from labelled fluent rules. |
| `->dropUnknownFields()` | `RuleSet` | Strip unvalidated array sub-keys from `validated()` output, also when the app keeps them with `Validator::includeUnvalidatedArrayKeys()`. Lenient counterpart to `failOnUnknownFields()`. |
| `->failOnUnknownFields()` | `RuleSet` | Reject input keys not present in the rule set. |
| `->field($name, $rule)` | `RuleSet` | Add a field via the fluent builder. |
| `->flattenRules()` | `array` | Flat dotted/wildcard form of the rules; `each`/`children` are unwrapped. |
| `RuleSet::from([...])` | `RuleSet` | Create from a rules array. |
| `->get($field, $default = null)` | `mixed` | Read a single field's rule (uncompiled), or `$default` if absent. |
| `->getIterator()` / `[...$ruleSet]` | `Traversable` | Spread support; yields the `toArray()` shape. |
| `->hasObjectRules()` | `bool` | `true` when at least one field uses `each()` or `children()`. |
| `->isEmpty()` | `bool` | `true` when no fields are registered. |
| `RuleSet::make()` | `RuleSet` | Empty rule set; chain `->field(...)`. |
| `->merge($ruleSet\|$array)` | `RuleSet` | Merge another RuleSet or array (later wins on collision). |
| `->modify($field, fn ($rule))` | `RuleSet` | Read-modify-write a single field; clones first; throws on missing key. |
| `->modifyChildren($field, $rules)` | `RuleSet` | Sugar: later-wins merge into a `FieldRule`'s `children([...])` map. |
| `->modifyEach($field, $rules)` | `RuleSet` | Sugar: later-wins merge into an `ArrayRule`'s `each([...])` map. |
| `->only(...$fields)` | `RuleSet` | Keep only the named fields (variadic strings or single array). |
| `->prepare($data)` | `PreparedRules` | Expand, extract metadata, compile. For hand-rolled `Validator::make`. |
| `->put($field, $rule)` | `RuleSet` | Add or replace a single field's rule. |
| `->stopOnFirstFailure()` | `RuleSet` | Stop validating after the first field fails. |
| `->toArray()` | `array` | Compiled flat output; `each()` expanded to wildcards. |
| `->validate($data, $messages = [], $attributes = [])` | `array` | Validate with full optimization (see [Performance](09-performance.md)). `$data` accepts `array` or `Illuminate\Http\Request`. |
| `->when($cond, $cb)` / `->unless(...)` | `RuleSet` | Conditionally add fields (Laravel's `Conditionable` trait). |
| `->withBag($name)` | `RuleSet` | Set the error bag name on the thrown `ValidationException`. |

</details>
