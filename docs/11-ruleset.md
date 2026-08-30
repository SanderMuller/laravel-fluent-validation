# RuleSet

`RuleSet` is the composable, immutable rule container that powers everything outside a form request: inline validation, shared rule libraries, conditional fields, errors-as-data flows. Reach for it whenever the rules are not bound to a single HTTP request. Form requests already wrap a `RuleSet` for you under the hood.

On this page: [Building](#building-a-rule-set) · [Composing](#composing-rule-sets) · [Inspecting and exporting](#inspecting-and-exporting-a-rule-set) · [Validating data](#validating-data) · [Raw Validator](#integrating-with-a-raw-validator) · [Custom Validators](#using-with-custom-validators) · [Compile pipeline](#compile-pipeline-advanced) · [Method reference](#method-reference)

## Building a rule set

Three equivalent entry points. Pick whichever reads cleaner at the call site. The array form is compact for static rule lists; the `make()` builder form is friendlier when fields are added conditionally; `define()` hands you a `FluentSchema` so you can drop the `FluentRule::` prefix.

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

Most non-trivial validation is assembled, not declared in one shot: shared address rules merged in, parent rules sliced down for a child request, a single field tweaked without rewriting the rest. RuleSet exposes three groups of composition tools.

**Slice and combine**: `merge`, `only`, `except`, `put`, `get`. `merge()` accepts a `RuleSet` or a plain array; later wins on key collision.

```php
return UserRules::base()
    ->only(['email', 'password'])
    ->put('email_confirmation', FluentRule::email()->required()->same('email'));
```

**Read-modify-write**: `modify`, `modifyEach`, `modifyChildren`. All three clone the existing rule before handing it to your callback so parent rule sets aren't mutated. `modify()` is the primitive; `modifyEach()` and `modifyChildren()` are sugar for the common case of extending a keyed `each([...])` (wildcard arrays) or `children([...])` (fixed-key objects) map. The "[Extending parent rules in child form requests](07-extending-rules.md)" section walks through the parent/child inheritance flow with `modify`/`modifyEach`. The same shape works for `modifyChildren` on a fixed-key object:

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

**Conditionals**: `when()` and `unless()` from Laravel's `Conditionable` trait. Use these to branch field inclusion based on a flag without breaking the chain (shown in the building example above).

## Inspecting and exporting a rule set

Reads of the in-memory `RuleSet`: predicates for branching code, debugging dumps, and the user-facing `toArray()` export that hands rules off to a Validator. The lower-level static `compile*` family lives under [Compile pipeline](#compile-pipeline-advanced). That's the transform surface for tooling and codegen.

- `toArray()` / `all()`: compiled flat output, ready for `Validator::make()`. `all()` is a Collection-style alias.
- `[...$ruleSet]`: spread via `IteratorAggregate`; yields the `toArray()` shape, so `[...$parent, 'extra' => $rule]` works.
- `isEmpty()`: `true` when no fields have been registered. Useful for "skip validation if empty" branches.
- `hasObjectRules()`: `true` when at least one field uses `each()` or `children()`. Useful for tooling that needs to distinguish flat from nested rule sets.
- `flattenRules()`: flattened dotted/wildcard form of the rules. Useful for codegen and debug logging:

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
- `dd()`: dumps and terminates. Sugar for the same shape during development.

## Validating

`validate()` and `check()`, the unknown-field flags, `stopOnFirstFailure()` and `withBag()` have their own page: [Validating with a RuleSet](12-validating.md).

## Integrating with a raw `Validator`

For inspection (`->failed()`, `->errors()`, `->valid()`), `check()` already exposes the underlying `Validator`:

```php
$validator = RuleSet::from($rules)
    ->check($request->all(), $customMessages)
    ->validator();
```

The validator has already run. For pre-run hooks like `->after()` or `->sometimes()`, prefer the equivalent `RuleSet` mechanics (custom `Rule` classes, `Rule::when()`, `modify()`). If you genuinely need an unvalidated `Validator`, `prepare()` returns the compiled rules, messages, and attributes for hand-rolled `Validator::make(...)` use.

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

`FluentValidator` resolves the translator and presence verifier from the container, calls `prepare()` on the rules, and sets implicit attributes. Cross-field wildcard references (`requiredUnless('*.type', ...)`) work automatically.

**Migrating rules in a non-standard method?** If your custom Validator holds its rules in a method that isn't named `rules()` (for example `rulesWithoutPrefix()` for a JSON-import pipeline), mark the method with `#[SanderMuller\FluentValidation\FluentRules]` so the migration Rector rules detect it. The attribute has no runtime effect; see the [`#[FluentRules]` opt-in docs](https://github.com/sandermuller/laravel-fluent-validation-rector#opting-in-fluentrules-attribute) for the full semantics and guard interactions.

## Compile pipeline

<details>
<summary>Escape hatches for tooling, codegen and framework interop</summary>

Application code does not reach for these; `validate()`, `check()`, `prepare()` and `toArray()` cover it. They are public so external Rector rules, PHPStan extensions and the Livewire bridge can hook the same pipeline RuleSet uses internally.

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
| `->check($data, $messages = [], $attributes = [])` | `Validated` | Validate without throwing. `$data` accepts `array` or `Illuminate\Http\Request`. See [Errors-as-data with `check()`](#errors-as-data-with-check). |
| `RuleSet::compile($rules)` | `array` | Compile fluent rules to native Laravel format. |
| `RuleSet::compileToArrays($rules)` | `array` | Compile to array-of-rules shape for Livewire's `$this->validate()`. |
| `RuleSet::compileWithMetadata($rules)` | `array` | Compile + return extracted messages and attributes in one pass. |
| `->dd()` | `never` | Dump the rule set and terminate. |
| `RuleSet::define(fn ($rules))` | `RuleSet` | Create from a closure given a `FluentSchema` builder; drops the `FluentRule::` prefix. |
| `->dump()` | `array` | Return `{rules, messages, attributes}` for debugging. |
| `->except(...$fields)` | `RuleSet` | Drop the named fields (variadic strings or single array). |
| `->expandWildcards($data)` | `array` | Pre-expand wildcards against `$data` without validating. |
| `RuleSet::extractMetadata($rules)` | `array` | Extract `[messages, attributes]` from labelled fluent rules. |
| `->dropUnknownFields()` | `RuleSet` | Silently strip unvalidated array sub-keys from `validated()` output. Lenient counterpart to `failOnUnknownFields()`. |
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
