# Changelog

All notable changes to `laravel-fluent-validation` will be documented in this file.

## 1.23.0 - 2026-04-23

Two new features land: per-item pre-evaluation extends from presence-conditionals to **value-conditionals**, and two subclass-friendly helpers eliminate the `get-wrap-mutate-set` boilerplate around `each()` / `children()`. Plus a bug fix for a latent validator-cache collision that the new pre-evaluation path surfaced, and a bug fix for an empty-array fallback in `compileFluentRules()`.

### New: per-item pre-evaluation for value-conditional rules

Covers `required_if`, `required_unless`, `prohibited_if`, `prohibited_unless`. For wildcard items, the new `ValueConditionalReducer` rewrites active rules to bare `required` / `prohibited` (unlocking fast-check) and drops inactive rules before Laravel's validator runs. Rules with custom `{field}.{rule_name}` messages or `validation.custom.*` translator overrides survive intact so translator lookups still fire.

```php
// Before: required_if routes through Laravel's slow validator for every item
RuleSet::from([
    'users.*.postcode' => FluentRule::field()
        ->requiredIf('role', 'admin')
        ->rule('string'),
]);

// After: for each item, reducer rewrites to `required|string` (admin)
// or `string` (non-admin) — remainder fast-checks as usual

```
Parity with Laravel's `validateRequiredIf` / `validateRequiredUnless` / `validateProhibitedIf` / `validateProhibitedUnless` is pinned by 32 side-by-side tests covering the four semantic nuances of `parseDependentRuleParameters`:

- `required_if`'s `Arr::has` short-circuit (dep missing → inactive) vs the other three rules' null-conversion path
- `convertValuesToBoolean` via `shouldConvertToBoolean` against the item-local rule set
- `convertValuesToNull` string-to-null coercion
- Loose-vs-strict `in_array` (numeric-string `"1"` matches int `1`, but `is_bool($other) || is_null($other)` switches to strict)

Closure / bool-form `requiredIf(Closure|bool)` (which wraps to `Illuminate\Validation\Rules\RequiredIf`) flows through unmodified — object rules aren't the reducer's surface.

#### Benchmark

500 contacts × `required_if:role,admin` (half admin with postcode, half non-admin without):

| Approach                        | Time    | Speedup |
|---------------------------------|---------|---------|
| Native Laravel                  | 99.5ms  | 1x      |
| RuleSet (pre-eval + fast-check) | 17.1ms  | **5.8x** |

### New: extend-parent helpers for `each()` and `children()`

The subclass-extends-parent FormRequest pattern ("parent shapes, child adds one field") previously forced consumers to write:

```php
$rules = Arr::wrap($parentRule->getEachRules());  // misleading safety
$rules['id'] = FluentRule::numeric()->nullable();
return $parentRule->each($rules);                  // full-replace

```
This loses the parent's base constraints on the ArrayRule itself (nullable, max, etc.) unless the child reconstructs them, and the `Arr::wrap` doesn't actually protect against a list-shaped parent — `$rules['id'] = …` on a `each(FluentRule::string())` state silently produces a malformed `[0 => $stringRule, 'id' => $idRule]`.

Four new helpers — two on `ArrayRule`, two on `FieldRule`:

```php
// ArrayRule
$rule->addEachRule(string $key, ValidationRule $rule): static;
$rule->mergeEachRules(array $rules): static;

// FieldRule
$rule->addChildRule(string $key, ValidationRule $rule): static;
$rule->mergeChildRules(array $rules): static;

```
Contract:

- **Collision throws `LogicException`.** Silent override would hide the "parent already defines this" mistake. Use `mergeEachRules` / `mergeChildRules` for intentional replacement (later-wins merge).
- **Empty keys throw `InvalidArgumentException`.** They'd expand to malformed wildcard paths (`items.*.`) or dotted paths (`parent.`).
- **List-shape state throws `CannotExtendListShapedEach`.** The list form (`each(FluentRule::string())`) is terminal — the item IS the scalar, there's no sub-key to add under. The exception message points at the keyed form.
- **Base constraints survive every call.** `FluentRule::array()->nullable()->max(20)->each([…])->addEachRule('id', …)` still carries `nullable` + `max:20` in the compiled output. Test-pinned.

#### Internal storage refactor

`ArrayRule`'s `eachRules` property is now always `?array<string, ValidationRule>` (never a union with bare `ValidationRule`). A separate `?ValidationRule $eachListRule` slot carries the list form; setting either via `each()` clears the other. Public `getEachRules()` return type (`ValidationRule|array<string, ValidationRule>|null`) is unchanged for full BC — the reconstruction happens at read time. Makes internal code paths that walk keyed rules free of union-branching.

### Fix: per-item validator cache collision with varying slow rules

**Pre-existing latent bug, activated by the value-conditional pre-eval path above.**

`ItemValidator` caches `Laravel\Validator` instances across items with the same effective rule shape. The cache key was just `implode(',', array_keys($rules))` — fine as long as two items with the same field set always had the same rule content. The reducers (both presence and value) can break that assumption: for a chain like `required_if:role,admin|exists:users,id`, admin items reduce to `required|exists:users,id` while non-admin items reduce to just `exists:users,id`. Same `postcode` field, different effective pipe string → cache collision → second item reuses first's immutable `Validator` and applies the wrong rule chain.

Fixed by routing cache-key generation through a new internal `RuleCacheKey::for()` that includes string content verbatim for string rules and a stable fingerprint (`spl_object_id` for objects, `gettype` for scalars, walked for arrays) for non-string rules. Regression test pins the exact `required_if + slow custom rule` scenario.

### Fix: `compileFluentRules()` honored an explicitly empty rules array

`$this->validate([])` / `validateOnly(..., [])` on a Livewire component using `HasFluentValidation` now correctly means "no validation" instead of silently falling back to the component's `rules()` default. The previous `$rules ? … : …` treated `[]` as falsy and routed to the fallback; changed to `$rules !== null ? …`.

### Internal cleanup

`array_merge(...)` inside `foreach` loops (O(n²) reallocation) replaced with collect-then-merge-once in three sites:

- `BatchDatabaseChecker::queryValues()` — scales with `CHUNK_SIZE`, the most load-bearing of the three.
- `ArrayRule::buildEachNestedRules()` + `buildChildNestedRules()`
- `FieldRule::buildNestedRules()`

Plus two new `RuleSet` helpers — `isEmpty()` and `hasObjectRules()` — absorbing the inline "any FluentRule objects in here?" scans that were duplicated across `getRules()` and `compileFluentRules()`. `HasFluentValidation::resolveFluentRuleSource()` now returns a `RuleSet` directly when `rules()` yields one, sparing a `toArray()` / `from()` round-trip.

### Cross-version support

| Surface                                     | Laravel 11 | Laravel 12 | Laravel 13 |
|---------------------------------------------|:----------:|:----------:|:----------:|
| Value-conditional reducer                   | ✅         | ✅         | ✅         |
| `addEachRule` / `mergeEachRules` + FieldRule equivalents | ✅  | ✅         | ✅         |
| `CannotExtendListShapedEach` exception      | ✅         | ✅         | ✅         |
| `RuleSet::isEmpty()` / `hasObjectRules()`   | ✅         | ✅         | ✅         |

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.22.0...1.23.0

## 1.22.0 - 2026-04-23

Batched database validation now refuses to run unsafe queries. `BatchDatabaseChecker` pre-populates values from raw input *before* any per-item rule executes, which previously let a hostile 100k-element payload fire 100 × `whereIn(1000)` queries and could crash strict databases (PostgreSQL raises `invalid input syntax for type integer` on malformed values). Three layered guards now short-circuit these paths before a single query fires.

### Three guards, evaluated in canonical order `filter → dedup → cap check → query`

1. **Per-item type pre-filter.** `integer` / `numeric` / `uuid` / `ulid` / `string` rules on each item drop values that would never pass validation anyway. Hostile input like `{"items": [{"id": "abc"}]}` for an `integer|exists` rule no longer reaches the `whereIn` — the dropped values are caught by the per-item validator with the usual `integer` error, so end-user error semantics are unchanged.
2. **Parent `max:N` short-circuit.** On the `HasFluentRules` FormRequest path, each concrete wildcard attribute's *immediate* parent array is inspected for a declared `max:N` before any DB query. Over-limit input surfaces as a `ValidationException` on the parent attribute with **zero** DB queries executed.
3. **Hard cap per `(table, column, rule-type)` group.** `BatchDatabaseChecker::$maxValuesPerGroup` (default `10_000`) is a defence-in-depth ceiling. Exceeding it throws the new `BatchLimitExceededException`, which the documented entry points (`HasFluentRules`, `RuleSet::validate()`, `RuleSet::check()`) remap to the standard `ValidationException`.

### New public surface

```php
// Configuration (override once during boot — mutation at request time is NOT Octane-safe)
SanderMuller\FluentValidation\BatchDatabaseChecker::$maxValuesPerGroup = 50_000;

// Exception for power users who need routing decisions pre-remap
namespace SanderMuller\FluentValidation\Exceptions;

final class BatchLimitExceededException extends \RuntimeException
{
    public const REASON_PARENT_MAX = 'parent-max';
    public const REASON_HARD_CAP   = 'hard-cap';

    public function __construct(
        public readonly string  $table,
        public readonly string  $column,
        public readonly string  $ruleType,   // 'exists' | 'unique'
        public readonly string  $reason,     // REASON_* constant
        public readonly int     $valueCount,
        public readonly int     $limit,
        public readonly ?string $attribute = null, // parent path for parent-max, null for hard-cap
    ) { /* ... */ }
}


```
```php
// Pre-remap catch for consumers wanting distinct HTTP status per reason
// (e.g. 413 on hard-cap, 422 on parent-max):
try {
    $request->validate();
} catch (BatchLimitExceededException $e) {
    return $e->reason === BatchLimitExceededException::REASON_HARD_CAP
        ? response('Payload Too Large', 413)
        : response()->json(['error' => 'validation', 'attribute' => $e->attribute], 422);
}


```
New static helper for filtering raw values by per-item type rule:

```php
BatchDatabaseChecker::filterValuesByType(mixed[] $values, array|string $itemRules): array


```
### Fixed: latent `exists` + `unique` conflation

When the same `(table, column)` carried both an `exists` and a `unique` rule in one validator (rare, but legal), `registerLookups` previously stored both groups under one key — the second `addLookup()` silently overwrote the first, corrupting validation results. The new conflict detector refuses to batch either group and lets Laravel's fallback `DatabasePresenceVerifier` handle each rule with correct per-item queries. Small perf hit, correct semantics.

### Threat matrix

| Vector | Before | After |
|---|---|---|
| Attacker POSTs 100k items to `array.max:100` + `each(id exists)` | 100 × `whereIn(1000)` queries fire, then `max` fails | 0 queries; `ValidationException` on parent |
| Attacker sends `{"items": [{"id": "abc"}, ...]}` where `id` is `integer` on PostgreSQL | 500 error — `invalid input syntax for type integer` | `"abc"` dropped pre-query; per-item `integer` error with correct attribute key |
| Developer forgets parent `max:N`, 100k valid items | Batch runs unbounded | `BatchLimitExceededException(reason='hard-cap')` — remapped to `ValidationException` |

### Behavioural change (minor-version bump)

Through the documented entry points — `HasFluentRules`, `RuleSet::validate()`, `RuleSet::check()` — consumers still see `ValidationException`. Nothing observable changes for code that uses those.

Consumers who construct validators directly from `$ruleSet->prepare()` may now observe a raw `BatchLimitExceededException` where previously the validator would either fire unbounded queries or raise `ValidationException` from the eventual `max` check. This is the escape-hatch path; the raw exception is part of the supported public surface for power users.

### Known scope

- **Only `max:N`** on the parent is inspected. `size:N`, `between:a,b`, and outer-ancestor maxes in nested-wildcard chains are not — rely on the hard cap for defence-in-depth against those.
- **Numerically-indexed wildcards only.** String-keyed collections (`{"items": {"foo": {...}}}`) bypass the parent-max check; the hard cap still applies.
- **`failedValidation()` does not fire on the parent-max / hard-cap paths.** The throw happens inside `createDefaultValidator()` before the FormRequest-level hook is reachable. The trait remap still converts to `ValidationException` so global exception handlers see the standard type.

### Configuration guidance for consumers

Legitimate bulk-import endpoints that need to process more than 10_000 distinct values per `(table, column, rule-type)` group should raise the cap during boot:

```php
// app/Providers/AppServiceProvider.php
public function boot(): void
{
    \SanderMuller\FluentValidation\BatchDatabaseChecker::$maxValuesPerGroup = 50_000;
}


```
Do NOT mutate the property at request time under Octane / Swoole — it is shared across requests within the same worker.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.21.0...1.22.0

## 1.21.0 - 2026-04-22

`ArrayRule::contains()` and `doesntContain()` now route through Laravel's `Rules\Contains` / `Rules\DoesntContain` object form (when available) and inherit the upstream CSV-quoting + enum resolution. Previously the methods emitted a naive `implode(',', $values)` pipe-string that silently broke on values containing commas or double-quotes, and the signature rejected `BackedEnum` / `UnitEnum` / `Arrayable` / single-array inputs that `Rule::contains()` accepts natively.

### Widened signature

Strict superset — every existing callsite keeps working:

```php
// Before: only string|int scalars, comma-broken on escapes
FluentRule::array()->contains('php', 'laravel')

// After: same call, plus:
FluentRule::array()->contains(['php', 'laravel'])              // single array
FluentRule::array()->contains(collect(['php', 'laravel']))     // any Arrayable
FluentRule::array()->contains(Status::Active)                  // BackedEnum → value
FluentRule::array()->contains(MyMode::Foo)                     // UnitEnum → name
FluentRule::array()->contains('he said "hi"', 'has,comma')     // CSV-escaped correctly



```
### Escaping fixes silent data corruption

On Laravel 12+, values are routed through `Rules\Contains::__toString()` which wraps each value in `"..."` and doubles-up embedded quotes. On Laravel 11, the new `serializeContainsValues()` helper applies the same escape rules to the pipe-string fallback. Either way, `'a,b'` is no longer split into two separate required-contained values.

Before:

```php
FluentRule::array()->contains('a,b')
// emitted: 'contains:a,b'
// Laravel parses with str_getcsv → ['a', 'b']
// validator now requires BOTH 'a' AND 'b' in the array, not 'a,b'



```
After:

```php
FluentRule::array()->contains('a,b')
// emitted: 'contains:"a,b"' (L11) or new Contains(['a,b']) (L12+)
// Laravel parses → ['a,b']
// validator correctly requires 'a,b' as a literal value



```
### `->message()` + `messageFor()` bind to the correct key

`HasFieldModifiers::addRule()` now maps `Rules\Contains` → `'contains'` and `Rules\DoesntContain` → `'doesnt_contain'` instead of the class-basename fallback (`'contains'` / `'doesntContain'`). `messageFor('doesnt_contain', ...)` now resolves as consumers expect.

### Runtime guards

Two new fail-fast guards that surface misuse clearly instead of corrupting the rule:

- **Multi-array varargs**: `->contains(['a'], ['b'])` throws `InvalidArgumentException('contains()/doesntContain() does not accept multiple array or Arrayable arguments. Pass either a single iterable (->contains($values)) or variadic scalars (->contains($a, $b, $c)).')`. Laravel's own `Rule::contains(['a'], ['b'])` silently ignores the second arg; passing nested arrays to `Contains::__toString` would crash on `str_replace`. Clear error beats both failure modes.
- **`doesntContain()` on Laravel 11**: throws `RuntimeException('doesntContain() requires Laravel 12+.')` at the method call site. `validateDoesntContain` / `Rules\DoesntContain` both shipped in L12; prior behavior surfaced as a deep validator-stack error. Matches the existing `FluentRule::anyOf()` precedent.

### Cross-version support

| Laravel | `contains()` | `doesntContain()` |
|---|---|---|
| 11.x | Pipe-string fallback with CSV escape | `RuntimeException` (method not available upstream) |
| 12.x / 13.x | `Rules\Contains` object form | `Rules\DoesntContain` object form |

The `addRule()` match table uses string class-name comparison for `Contains` / `DoesntContain` to avoid `instanceof` triggering an autoload fatal on L11 where those classes don't exist.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.20.0...1.21.0

## 1.20.0 - 2026-04-22

Inline `message:` named argument on every non-variadic rule method and every factory with a stable error-lookup key. Colocate the message with the rule it binds to, without `->message()` chain position or `->messageFor('rule', ...)` string coupling. Plus a Boost skill that migrates `public function messages(): array` overrides into the new form.

### Inline `message:` named arg

Recommended form:

```php
FluentRule::string('Full Name')
    ->required(message: 'We need your name!')
    ->min(2, message: 'At least :min characters.')
    ->max(255)




```
Available on factories too:

```php
FluentRule::email(message: 'Must be a valid email.')
FluentRule::uuid(message: 'Must be a UUID.')
FluentRule::integer(message: 'Must be a whole number.')
FluentRule::array(message: 'Must be a list.')->required()




```
### Three forms, three purposes

| Form | When to use |
|---|---|
| `->method(…, message: '…')` | **Preferred.** Colocated with the rule, rename-safe, works on factories and rule methods. |
| `->method(…)->message('…')` | Shorthand when you want the message on the most recent rule. Binds to `$lastConstraint`. Works on variadic methods too. |
| `->messageFor('rule', '…')` | Targets a rule by name at any point in the chain. Required for non-last sub-rules on composite methods, Macroable methods, and custom `->rule(object)` calls that need class-basename-keyed messages. |

`->messageFor()` is **not** deprecated — it's the documented escape hatch for cases `message:` can't cover.

### `->message()` now works on factories

Pre-1.20, `FluentRule::email()->message('Invalid!')` threw a `LogicException` because the factory's implicit constraint (`'email'`) never flowed through `addRule()` and `$lastConstraint` was null. Rule class constructors now seed `$lastConstraint` to the factory's defining rule name, so `->message()` binds correctly without needing `messageFor('email', ...)`:

```php
FluentRule::email()->message('Invalid!')       // now binds to 'email'
FluentRule::string()->message('Must be text.') // now binds to 'string'
FluentRule::array()->message('Must be a list.')// now binds to 'array'




```
Seeded factories: `string`, `numeric`, `boolean`, `accepted`, `declined`, `file`, `image`, `array`, `email`.

The `LogicException` still fires for truly-empty chains (`FluentRule::field()->message(...)`) — behaviour narrowed, not removed.

### Composite methods bind to the last sub-rule

`NumericRule::digits()`, `digitsBetween()`, `exactly()` internally add `integer` first, then the sized rule. `DateRule::between()` adds `after` then `before`. `ImageRule::width()` / `minWidth()` / `ratio()` all funnel through `dimensions()`. On these methods, `message:` binds to the **last** sub-rule. Target earlier sub-rules via `messageFor`:

```php
FluentRule::numeric()
    ->digits(5, message: 'Must be 5 digits.')      // binds to 'digits'
    ->messageFor('integer', 'Must be whole.')       // targets the 'integer' sub-rule

FluentRule::date()
    ->between('2020-01-01', '2026-12-31', message: 'Out of range.')  // binds to 'before'
    ->messageFor('after', 'Must be after start.')                     // targets 'after'




```
Docblocks on these methods spell out the binding rule.

### Not accepted on `message:`

- **Variadic-trailing methods** — `requiredWith`, `requiredWithAll`, `requiredWithout`, `requiredWithoutAll`, `presentIf`, `presentUnless`, `presentWith`, `presentWithAll`, `missingIf`, `missingUnless`, `missingWith`, `missingWithAll`, `requiredIf`, `requiredUnless`, `excludeIf`, `excludeUnless`, `prohibitedIf`, `prohibitedUnless`, `prohibits`, `acceptedIf`, `declinedIf`, `doesntEndWith`, `doesntStartWith`, `endsWith`, `startsWith`, `email`, `extensions`, `mimes`, `mimetypes`, `requiredArrayKeys`, `contains`, `doesntContain`. PHP forbids params after a variadic. Use `->message()` (shorter) or `messageFor()`.
- **Mode modifiers that don't call `addRule()`** — `EmailRule::rfcCompliant`, `strict`, `validateMxRecord`, `preventSpoofing`, `withNativeValidation`; `PasswordRule::min`, `max`, `letters`, `mixedCase`, `numbers`, `symbols`, `uncompromised`. These configure the embedded rule object; the relevant message target is the factory call (`FluentRule::email(message: '...')`) or the underlying sub-key (`messageFor('password.letters', '...')`).
- **`FluentRule::date()` / `::dateTime()` factories** — error-lookup key varies at build between `'date'` and `'date_format:...'`; no deterministic seed possible. Attach messages to a specific method: `FluentRule::date()->before('2026-12-31', message: 'Too late.')` or use `messageFor()`.
- **`FluentRule::password()` factory** — Password failures use sub-keys (`password.mixed`, `password.letters`, `password.numbers`, etc.) in Laravel 11's message lookup (which lacks the shortRule fallback added in L12). Users target sub-keys via `messageFor('password.letters', '…')` or a `messages(): array` entry.
- **`FluentRule::field()` / `::anyOf()`** — no implicit constraint to message.

### `migrate-messages-array` Boost skill

New skill at `resources/boost/skills/migrate-messages-array/` that rewrites FormRequest `messages(): array` overrides into inline `message:` form on the matching fluent chain. Classifies each `field.rule` key into one of three rewrite tiers:

1. **Portable** — inline `message:` on the owning chain method or factory.
2. **Portable-via-`messageFor`** — variadic methods, composite sub-rules, `->rule(object)` escape. Removes the `messages()` entry by using `messageFor` on the chain.
3. **Unportable** — stays in `messages()` with a comment explaining why (factory-emitted implicit rules, dynamic keys, cross-method helpers, Macroable chain methods).

12-path skip-log taxonomy covering helper-method extraction, local-variable indirection, ternary / match / spread, Macroable methods, wildcard nesting through `each()`/`children()`, `->when()` closure hops, and translated-value preservation. Dry-run mode outputs a per-file diff + skip-log table before applying.

Activate via `boost:install` on consumer apps that pin `sandermuller/laravel-fluent-validation ^1.20`.

### Backwards compatibility

Every new parameter is an **optional trailing** `?string $message = null`. No existing call site shifts semantics. No existing method renamed, removed, or reordered. `->message()` and `->messageFor()` both stay first-class; neither is deprecated.

Existing `messages(): array` overrides in consumer FormRequests continue to work — Laravel's native message precedence over `message:` / `->message()` / `->messageFor()` is unchanged.

### Affected surface

- `HasFieldModifiers`: `required`, `sometimes`, `filled`, `present`, `prohibited`, `missing`, `requiredIfAccepted`, `requiredIfDeclined`, `prohibitedIfAccepted`, `prohibitedIfDeclined`, `rule`.
- `HasEmbeddedRules`: `unique`, `exists`, `enum`, `in`, `notIn`.
- `StringRule`: 33 of 38 rule-adding methods (5 variadic skipped).
- `NumericRule`: 25 of 25.
- `ArrayRule`: 6 of 9 (3 variadic skipped).
- `DateRule`: 15 methods (direct + wrappers like `beforeToday`, `past`).
- `FileRule`: 4 of 7 (3 variadic skipped).
- `ImageRule`: 9 dimension wrappers (all funnel through `dimensions()`).
- `EmailRule`: `max`, `confirmed`, `same`, `different`.
- `PasswordRule`: `confirmed`.
- `BooleanRule`: `accepted`, `declined`.
- `FieldRule`: `same`, `different`, `confirmed`.
- `FluentRule` factories: `string`, `numeric`, `integer`, `boolean`, `array`, `file`, `image`, `accepted`, `declined`, `email`, `url`, `uuid`, `ulid`, `ip`, `ipv4`, `ipv6`, `macAddress`, `json`, `timezone`, `hexColor`, `activeUrl`, `regex`, `list`.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.19.0...1.20.0

## 1.19.0 - 2026-04-22

Shorthand factories for the most common single-rule strings, plus a symmetric `DeclinedRule` and four sign-only helpers for `NumericRule`. Every addition is strictly additive — no existing rule changed its compiled string.

### New top-level shorthand factories

Each is a thin delegate over `FluentRule::string()`/`array()`/`field()` — reach for the shortcut when the format/type is the only constraint besides presence modifiers:

```php
FluentRule::ipv4()        // string()->ipv4()
FluentRule::ipv6()        // string()->ipv6()
FluentRule::macAddress()  // string()->macAddress()
FluentRule::json()        // string()->json()
FluentRule::timezone()    // string()->timezone()
FluentRule::hexColor()    // string()->hexColor()
FluentRule::activeUrl()   // string()->activeUrl()
FluentRule::regex('/^\d+$/')         // string()->regex(...)
FluentRule::list()        // array()->list()
FluentRule::enum(Status::class)      // field()->enum(...)





```
Each accepts an optional `?string $label` (and `FluentRule::regex()` takes the pattern as the first positional arg, label second) so existing label-bearing chains collapse cleanly:

```php
FluentRule::string('Website')->activeUrl()   // before
FluentRule::activeUrl('Website')             // after





```
The `enum()` shortcut deliberately returns an untyped `FieldRule`: Laravel's enum validation rule handles both string-backed and int-backed enums, so forcing a `string` type prefix would surprise int-backed users. Chain `FluentRule::string()->enum(...)` or `FluentRule::integer()->enum(...)` when you *do* want a type constraint alongside.

### `FluentRule::declined()` — symmetric sibling of `accepted()`

`DeclinedRule` is now a first-class standalone rule, mirroring `AcceptedRule` one-for-one. Useful for opt-out checkboxes on HTML forms where the input is `'no'`/`'off'`/`'0'` — values `boolean` would reject.

```php
FluentRule::declined()                          // no | off | 0 | '0' | false | 'false'
FluentRule::declined()->declinedIf('under_18', 'yes')





```
`->declinedIf(...)` replaces the base `declined` with `declined_if` on compile (same logic `AcceptedRule::acceptedIf` uses), so you never get the impossible `declined|declined_if` pair.

The footgun note on `FluentRule::boolean()->accepted()` applies equally here: `FluentRule::boolean()->declined()` compiles to `boolean|declined` — `boolean` rejects `'no'`/`'off'` which `declined` would otherwise permit. Use `FluentRule::declined()` when the input shape is HTML-form-ish.

### Numeric sign helpers — hardcoded-zero comparisons

The existing `greaterThan(string $field)` / `greaterThanOrEqualTo(...)` / `lessThan(...)` / `lessThanOrEqualTo(...)` methods are designed for comparisons against another field. The common "must be positive" / "must be non-negative" case has no field to compare against — it's a literal-zero comparison that Laravel's `gt`/`gte`/`lt`/`lte` rules accept natively.

Four new methods on `NumericRule` target exactly that case:

```php
FluentRule::numeric()->positive()        // gt:0
FluentRule::numeric()->negative()        // lt:0
FluentRule::numeric()->nonNegative()     // gte:0
FluentRule::numeric()->nonPositive()     // lte:0





```
No change to the field-comparison methods — use those when you have a field name; use these when you have literal zero.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.18.0...1.19.0

## 1.18.0 - 2026-04-21

Clearer failure mode when calling type-specific methods on the untyped `FluentRule::field()` builder, plus an opt-in Pest/PHPUnit arch helper for downstream apps that want belt-and-suspenders coverage.

### Informative runtime exception on `FluentRule::field()->{typeRule}(...)`

`FluentRule::field()` is the untyped builder — it carries no base type constraint. It supports modifiers (`required`, `nullable`, `present`, conditional presence), `children()`, `same`/`different`/`confirmed`, the embedded-rule factories (`exists`, `unique`, `enum`, `in`, `notIn`), and the `->rule(...)` escape hatch. What it intentionally does *not* expose is type-specific rules — `min`, `max`, `regex`, `email`, `digits`, `mimes`, `before`/`after`, `contains`, `ipv4`, `timezone`, `allowSvg`, etc. Those live on the typed builders.

Previously, `FluentRule::field()->min(5)` passed PHPStan (because `Macroable` advertises an unbounded `__call` surface) but fatalled at runtime with Laravel's generic `BadMethodCallException: Method min does not exist.` — usually inside a controller or queued job, far from where the rule was authored.

As of 1.18.0, `FieldRule` overrides `__call` and `__callStatic` to throw a typed `SanderMuller\FluentValidation\Exceptions\UnknownFluentRuleMethod` that names the correct typed builder:

```
UnknownFluentRuleMethod: FluentRule::field() has no method min().
Use `FluentRule::string()`, `FluentRule::numeric()`, `FluentRule::array()`, `FluentRule::file()`, `FluentRule::image()`, or `FluentRule::password()` and chain `->min(...)`.






```
The hint table (`SanderMuller\FluentValidation\Exceptions\TypedBuilderHint`) is **derived by reflection** from every public method on every typed builder (`StringRule`, `NumericRule`, `DateRule`, `ArrayRule`, `FileRule`, `ImageRule`, `BooleanRule`, `AcceptedRule`, `PasswordRule`, `EmailRule`) minus `FieldRule`'s own public surface. New methods added to any typed builder in future releases are automatically covered — no hand-maintained list to drift.

A few hints are hand-curated because they either redirect to a documented footgun-free alternative or flag Laravel rule-string names that the fluent API renames:

- `accepted` → `FluentRule::accepted()` (not `FluentRule::boolean()->accepted()`, which rejects `'yes'`/`'on'`).
- `size` → `->exactly(...)` on a typed builder.
- `gt`/`gte`/`lt`/`lte` → `->greaterThan`/`greaterThanOrEqualTo`/`lessThan`/`lessThanOrEqualTo` on `FluentRule::numeric()`.
- `alphaNum` → `FluentRule::string()->alphaNumeric(...)`.
- `contains` → `FluentRule::array()->contains(...)` (not `string()`).

The new exception extends `BadMethodCallException`, so any `try { } catch (BadMethodCallException)` continues to work — only the message text changes. Registered macros on `FieldRule` still dispatch (the override preserves `Macroable`-compatible semantics before throwing).

### New opt-in arch helper — `BansFieldRuleTypeMethods`

For downstream apps that want to fail at test time rather than rely on the runtime exception, the package now ships `SanderMuller\FluentValidation\Testing\Arch\BansFieldRuleTypeMethods`. It walks configured paths with `nikic/php-parser`, runs a `NameResolver` pass so imports and aliases resolve to the fully qualified class name, and returns every file containing a `FluentRule::field()` chain whose next method is in the typed-builder hint table.

```php
use SanderMuller\FluentValidation\Testing\Arch\BansFieldRuleTypeMethods;

arch('FluentRule::field() does not chain type-specific methods')
    ->expect(BansFieldRuleTypeMethods::scope('app/'))
    ->toBeEmpty();






```
Because name resolution is FQN-based, `use SanderMuller\FluentValidation\FluentRule as Rule; Rule::field()->min(5)` is caught, while an unrelated `Acme\FluentRule::field()->min(5)` in a different namespace is not. The banned method set is the same reflection-derived list the runtime exception uses, so coverage is identical across both layers.

`nikic/php-parser` is listed under `suggest` in `composer.json`, pinned to `^5.0` — the package itself remains dependency-light. The helper raises a clear `RuntimeException` with install instructions when the parser is absent, and a separate `\Error`-catching path gives a versioned upgrade message when an older `^4.x` install is detected (v4 lacks `ParserFactory::createForHostVersion()`).

```bash
composer require --dev "nikic/php-parser:^5.0"






```
**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.17.1...1.18.0

## 1.17.1 - 2026-04-20

Architecture hardening patch. Four independent moves to reduce complexity debt and catch upstream breakage early:

1. `FastCheckCompiler` split into per-family compilers under `src/FastCheck/`.
2. Per-item validation loop extracted from `RuleSet` into `src/Internal/ItemValidator.php` + collaborators.
3. `FluentRulesTester` promoted to `@api` — the package's stable test surface.
4. Nightly CI leg against `laravel/framework` `dev-master` for early warning on breaking changes.

No behavior change, no breaking public API changes. Every existing consumer continues working untouched.

### `FluentRulesTester` is now `@api` — stable under semver

The tester has shipped for several releases and stabilized. As of 1.17.1, every public method on `SanderMuller\FluentValidation\Testing\FluentRulesTester` carries an `@api` PHPDoc tag; signatures are now locked under semver. The tester remains the package's sole recommended test surface — everything else under `Testing/` stays `@internal`.

```php
use SanderMuller\FluentValidation\Testing\FluentRulesTester;

// Raw FluentRule chain
FluentRulesTester::for(FluentRule::string()->required()->min(3))
    ->with(['value' => 'hi'])
    ->fails();

// RuleSet instance
FluentRulesTester::for(
    RuleSet::make()->field('email', FluentRule::email()->required())
)->with(['email' => 'a@b.test'])->passes();

// FluentFormRequest subclass — runs the full pipeline including authorize()
FluentRulesTester::for(UpdateVideoRequest::class)
    ->withRoute(['video' => $video])
    ->actingAs($user)
    ->with(['title' => 'Updated'])
    ->passes();

// FluentValidator subclass
FluentRulesTester::for(JsonImportValidator::class, $user, 'sku-')
    ->with($payload)
    ->passes();







```
See `README#testing-fluent-rules` for the full surface: `passes()`, `fails()`, `failsWith()`, `failsOnly()`, `failsWithAny()`, `failsWithMessage()`, `doesNotFailOn()`, `assertUnauthorized()`, `errors()`, `validated()`, plus Livewire support (`set()`, `call()`, `andCall()`, `mount()`) and Pest expectations (`toPassWith`, `toFailOn`, `toBeFluentRuleOf`).

### Internal refactor — `FastCheckCompiler` split

`src/FastCheckCompiler.php` kept its FQCN and public surface (3 static methods: `compile()`, `compileWithItemContext()`, `compileWithPresenceConditionals()`) but is now a thin dispatcher. Per-family compilers live under `src/FastCheck/` (`CoreValueCompiler`, `ItemContextCompiler`, `PresenceConditionalCompiler`, `ProhibitedCompiler`) plus shared utilities (`LaravelEmptiness`, `ItemAwareBranchBuilder`). Each family compiler is `final` + `@internal`. Public API unchanged — no consumer action required.

Dispatch order inside `compile()`: `CoreValueCompiler` first (hot path), `ProhibitedCompiler` second. Benchmarks across 3 runs match the pre-split baseline within ±3% on all 6 scenarios (product import, nested order lines, event scheduling, article submission, conditional import, login form). Both orderings benchmarked — no perf delta outside noise.

**Minor expansion of fast-check coverage.** `prohibited|sometimes` and `prohibited|bail` (and orderings thereof) now compile to a fast-check closure — previously these took the Laravel slow path because the old monolithic `parse()` treated `sometimes` as non-fast-checkable. Verdict is identical against native Laravel (covered by `ProhibitedConditionalParityTest`); purely a speedup for rule strings that mix those modifiers.

### Internal refactor — `ItemValidator` extracted from `RuleSet`

The per-item validation loop (`validateItems`) plus its nine supporting helpers (`analyzeConditionals`, `reduceRulesForItem`, `stripConditionalTuples`, `findCommonDispatchField`, `ruleCacheKey`, `buildFastChecks`, `buildBatchVerifier`, `passesAllFastChecks`, `collectErrors`) relocated from `RuleSet` into `src/Internal/`:

- `ItemRuleCompiler` — rule-shape concerns.
- `ItemErrorCollector` — fast-check run + error harvest.
- `ItemValidator` — the loop itself.

All three `final` + `@internal`. `RuleSet::validateItems()` shrank to a 2-line delegate. Class cognitive complexity on `RuleSet` dropped 52% (274 → 131). Benchmarks within ±3% of baseline.

### Nightly `dev-master` CI leg

New `.github/workflows/laravel-dev-master.yml` runs the test suite against `laravel/framework:dev-master` + `orchestra/testbench:dev-master` on PHP 8.4 every day at 06:00 UTC. On failure, a single tracking issue is opened (reused on subsequent failures via comment). Exists to catch Laravel breaking changes — rule-string parser changes, `Validator::$customMessages` removal, `Rule::in()`/`exists()`/`unique()` shape changes — before they ship as tagged releases.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.17.0...1.17.1

## 1.17.0 - 2026-04-20

One feature: every rule class now implements `SanderMuller\FluentValidation\Contracts\FluentRuleContract` — a single stable return type for `rules()` arrays. Mijntp's ask; usable across any downstream app with type-aware tooling.

### `FluentRuleContract` — single return type for `rules()` arrays

Before 1.17, typing a `rules()` method's return value meant either enumerating every concrete rule class (`@return array<string, FieldRule|StringRule|NumericRule|EmailRule|…>`) — which grows unwieldy and churns on every new rule type — or falling back to Laravel's `ValidationRule` (works, but doesn't distinguish fluent-package rules from arbitrary rule objects).

1.17 adds a dedicated contract that every shipped rule class implements:

```php
use SanderMuller\FluentValidation\Contracts\FluentRuleContract;

/** @return array<string, FluentRuleContract> */
public function rules(): array
{
    return [
        'name'  => FluentRule::string()->required()->min(2),
        'email' => FluentRule::email()->required()->unique('users'),
        'age'   => FluentRule::numeric()->nullable()->integer()->min(0),
    ];
}








```
One type covers the whole package. `FluentRuleContract extends Illuminate\Contracts\Validation\ValidationRule`, so any code currently typed against Laravel's native contract keeps working unchanged.

#### Scope: medium contract, not marker-only

Per mijntp's design steer, the contract carries the full universally-shared surface — every `HasFieldModifiers` modifier (`required()`, `nullable()`, `bail()`, `prohibited()`), every conditional (`requiredIf()`, `requiredWith()`, `excludeUnless()`, the full list), every metadata method (`label()`, `message()`, `getLabel()`, `getCustomMessages()`), plus `SelfValidates` plumbing (`compiledRules()`, `canCompile()`, `buildNestedRules()`, `toArray()`) and Laravel's `Conditionable` chain (`when()` / `unless()`).

Type-specific methods (`StringRule::email()`, `NumericRule::integer()`, `ImageRule::dimensions()`, etc.) intentionally stay on the concrete class — narrow to the concrete type when you need to call them.

All chain-returning methods return `static`, so concrete subclasses keep their own type when you narrow.

#### 11 rule classes implementing the contract

`AcceptedRule`, `ArrayRule`, `BooleanRule`, `DateRule`, `EmailRule`, `FieldRule`, `FileRule`, `ImageRule` (inherited via `FileRule`), `NumericRule`, `PasswordRule`, `StringRule`.

Tests at `tests/FluentRuleContractTest.php` include a runtime reflection audit that catches drift — any new rule class added to `Rules/*` without `FluentRuleContract` in its implements list fails the guard.

#### `withBag()` docs follow-up

Unrelated docs cleanup: the README's "Using with `validateWithBag`" section (last touched before 1.16.0) documented the old `prepare() + Validator::make(...) + validateWithBag(...)` incantation. Now updated to show the 1.16.0 `RuleSet::from($rules)->withBag($name)->validate($input)` chain directly.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.16.0...1.17.0

## 1.16.0 - 2026-04-20

Two small features and one correctness fix:

1. `FluentRule::field()->prohibited()` now fast-checks.
2. `RuleSet::withBag(string $name)` for Fortify-style named error bags.
3. Mid-release correctness pivot — original `prohibited_with*` family I was going to build doesn't exist in Laravel; what did ship is a carefully narrowed bare-`prohibited` fast-check with full parity coverage.

### `FluentRule::field()->prohibited()` is fast-checkable

Until 1.16, `prohibited()` always routed through Laravel's validator. Now a rule that consists of just `prohibited` (optionally with `nullable` / `sometimes`) compiles to a PHP closure that short-circuits at the `FastCheckCompiler` level, identical to how `required` has always worked.

```php
// Before 1.16: slow-path, full Laravel validator dispatch per item.
// 1.16+: fast-checked PHP closure, no validator invocation when value is empty.
FluentRule::field()->prohibited()









```
Closure uses the same shared `isLaravelEmpty` helper that drives the presence-conditional reducer: passes on null / `''` / `[]` / whitespace-only string / empty `Countable` / `File` with empty path; fails on anything non-empty.

**Scope caveat — important.** The fast-check only activates for `prohibited` alone (possibly with `nullable` / `sometimes`). Combined with any other rule (e.g. `prohibited|string|max:10`), the rule slow-paths through Laravel. Reason: the closure receives the item's value as a single argument and can't distinguish "value is explicitly null" from "value is absent from the item". Laravel treats these differently (non-implicit rules like `string` run on explicit null but skip on absent), and the closure can't reproduce the distinction without violating fast-check parity. Bare `prohibited` is still the common shape; combinations stay on the existing (correct) slow path.

Parity tests at `tests/ProhibitedConditionalParityTest.php` pin the fast-check verdict against native Laravel across the full shape grid, plus all the contradictory-combination cases (`prohibited|required`, `prohibited|accepted`, `prohibited|declined`), item-aware combinations (`prohibited|same:other`, `prohibited|different:other`, `prohibited|after:start`), and the File-with-empty-path edge case.

### `RuleSet::withBag(string $name)` — named error bags

Mirrors Laravel's `Validator::validateWithBag($name)`. Motivation from downstream Fortify usage: when multiple forms share a page (update-password, reset-password, profile-update), each needs its own error bag so their messages don't collide in shared Blade partials. Before 1.16 `RuleSet::validate()` threw straight into the default bag, forcing consumers back to the manual `Validator::make()` incantation and defeating `RuleSet` as the canonical entry point.

```php
// Before:
$p = RuleSet::from($rules)->prepare($input);
Validator::make($input, $p->rules, $p->messages, $p->attributes)
    ->validateWithBag('updatePassword');

// 1.16:
RuleSet::from($rules)->withBag('updatePassword')->validate($input);









```
Chains with `stopOnFirstFailure()`, `failOnUnknownFields()`, and every other existing toggle. Only affects the thrown `ValidationException`'s `errorBag` property — `check()` is unaffected since it never throws. The wildcard-group path is also covered by a single `try/catch` around the internal pipeline.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.15.1...1.16.0

## 1.15.1 - 2026-04-20

Internal cleanup / latent-bug patch. PHPStan baseline paid down aggressively; `RuleSet` shed its presence-conditional code path to a new `@internal` sibling class. Zero user-visible behavior change on the happy path — same rules, same errors, same benchmark numbers. A few type-guard tightenings swap latent runtime-throw paths for silent skip on malformed input (see "Latent bugs fixed" below).

### PHPStan baseline reduction sprint

Every minor release since 1.11 added to `phpstan-baseline.neon` rather than subtracting from it. Over five releases the baseline grew from tight to 114 entries / 343 lines, hiding real signals behind accumulated compromises. 1.16 reverses it:

| Metric | 1.15.0 | 1.15.1 |
|---|---:|---:|
| Baseline entries | 114 | **14** |
| Baseline lines | 343 | **85** |
| `typeCoverage.paramTypeCoverage` rows | 5 | **0** |
| `pest.redundantExpectation` rows | 18 | **0** |
| `argument.type` rows | 12 | **0** |
| `cast.string` / `binaryOp.invalid` / `nullCoalesce.offset` / `missingType.iterableValue` / `varTag.*` / `assign.propertyType` / `method.internal*` | 9 combined | **0** |
| Inline `@phpstan-ignore` comments | 0 | 3 (documented) |

### Latent bugs fixed

Three real latent paths were hiding behind the baseline, each promoted to the patch line rather than buried in cleanup:

1. **`FastCheckCompiler::sizePair`** — when called with a type flag (`numeric|gt:ref`, `string|gt:ref`, `array|gt:ref`) and the target `$value` didn't match the flag, the helper would call `count($value)` or `mb_strlen((string) $value)` on the wrong shape. In practice $value was caller-guaranteed by an earlier rule in the pipe chain, but the guarantee was implicit — the helper is now defensive (`is_numeric($value)` / `is_string($value)` / `is_array($value)` before size computation). Returns null when the shapes disagree; the caller treats null as "comparison not applicable", which is the semantically correct fallback.
   
2. **`BatchDatabaseChecker::uniqueStringValues`** — previously `strval(...)` on every element of an arbitrary `array<mixed>`. `strval` throws `TypeError` on arrays and on objects without `__toString`. In the DB-batching hot path this couldn't easily reach if your DB driver only returned scalars, but a custom presence-verifier or an exotic column type would have triggered it. Replaced with a guarded closure that coerces unknown shapes to empty string.
   
3. **`PrecomputedPresenceVerifier::flip`** — `(string) $v` on non-scalar values silently produced `"Array"` or threw on toString-less objects. Added a scalar/Stringable guard; unknown shapes are now skipped rather than producing stringified garbage in the lookup map.
   

None of these is a crash anyone has reported against current Laravel + common DB drivers — they're defensive-coding hardenings that PHPStan correctly flagged and that are now robust against malformed input.

### `PresenceConditionalReducer` extracted from `RuleSet`

The ~300-line presence-conditional pre-evaluation code that landed in 1.15 has been lifted to a new `SanderMuller\FluentValidation\PresenceConditionalReducer` sibling class (marked `@internal`, `final`, static-only). `RuleSet::validateItems` now delegates via two static calls (`hasAny`, `apply`) — zero behavior change, but `RuleSet`'s class-level cognitive complexity dropped below the 80 threshold.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.15.0...1.15.1

## 1.15.0 - 2026-04-19

Pre-evaluates presence-conditional rules (`required_with`, `required_without`, `required_with_all`, `required_without_all`) per item inside wildcard groups, unlocking fast-check for a shape Laravel previously forced onto the slow path.

### Presence conditionals via pre-evaluation

Wildcard validation has always gone fast when every rule on a field was fast-checkable. Presence conditionals were a gap: `FastCheckCompiler::compileWithPresenceConditionals` fast-checked simple dependent field names, but rejected *dotted* dependent paths like `required_without:profile.birthdate` at its identifier regex. Anyone whose payload shape nested the dependent under a parent (`profile.birthdate`, `address.postcode`, etc.) paid the slow-path tax per item.

1.15 adds a reducer step in `RuleSet::reduceRulesForItem` that evaluates the presence conditional against the item, then rewrites:

- **Active** (the rule requires the target): collapse to plain `required` — fast-checkable by the existing compiler, with the remainder of the rule chain intact.
- **Inactive** (the rule does not require the target): drop the rule entirely.
- **Active with a custom user message on the original rule name**: keep the original rule intact so the override still fires.

```php
// Before 1.15: fell through to native Laravel because the dependent field
// has a dot in it.
'addresses' => FluentRule::array()->each([
    'postcode' => FluentRule::field()
        ->requiredWithout('profile.birthdate')
        ->rule('string'),
]),

// In 1.15: the reducer resolves `profile.birthdate` per item, rewrites the
// rule, and the postcode field goes down the fast-check path.











```
**Benchmark** (`tests/SlowPathBenchTest.php`, 500 contacts × wildcard rule with `required_without:profile.birthdate`):

- Native Laravel: **99.2ms**
- RuleSet pre-eval + fast-check: **13.5ms** (**7.3x**)

Data shape mixes active and inactive items to exercise both reducer paths. No speedup notch change on any existing hot-path benchmark scenario vs 1.14.0.

### Custom-message preservation

The rewrite is load-bearing for anyone who translates validation messages per-rule-name. If you have `'postcode.required_without' => 'Vul a.u.b. uw postcode in'` in a FormRequest `messages()` method, or `validation.custom.addresses.*.postcode.required_without` in a translator file, the reducer detects the override and keeps the original rule string intact. The rewrite-to-`required` path only engages when no user override exists, so the generic `required` message firing there is the correct outcome (no `required_without` message was ever configured).

Detection covers:

- Any `$messages` map key equal to `{field}.{rule}` or ending with `.{field}.{rule}` — matches bare-field, wildcard-prefixed, and parent-prefixed forms FormRequests typically emit.
- `validation.custom.{field}.{rule}` via direct translator lookup.
- Flattened wildcard keys under `validation.custom` (e.g. `validation.custom.addresses.*.postcode.required_without`) via Laravel-equivalent `Str::is` matching.

### Parameter parsing matches Laravel exactly

The first-pass implementation used `explode(',', $rawParam)`. A Codex adversarial review caught that Laravel's `ValidationRuleParser::parseParameters` uses `str_getcsv` with CSV semantics — meaning stray leading/trailing commas, CSV-quoted fields, and empty-param degenerate forms would silently diverge. Fixed by adopting `str_getcsv` exactly, relaxing the parameter-slot type to `?string` (to mirror Laravel's null-slot → full-item resolution), and deferring entirely-empty raw params back to Laravel. Parity tests cover each edge case.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.14.0...1.15.0

## 1.14.0 - 2026-04-19

Adds a standalone `FluentRule::accepted()` factory for the permissive opt-in family, sidestepping a subtle footgun when pairing with the strict `boolean` rule.

### `FluentRule::accepted()` — permissive opt-in without `boolean`

Laravel's `accepted` and `boolean` rules cover overlapping but *non-equivalent* shapes:

- `boolean` accepts `true`, `false`, `1`, `0`, `'1'`, `'0'` only. Strict.
- `accepted` accepts `true`, `1`, `'1'`, `'yes'`, `'on'`, `'true'`. Permissive — tuned for HTML form checkboxes.

Chaining them with `FluentRule::boolean()->accepted()` compiles to `boolean|accepted`, which quietly rejects the `'yes'` and `'on'` values that checkbox form posts actually deliver — `boolean` vetoes them before `accepted` gets a say.

The new factory lets you express the permissive rule without the conflicting base:

```php
// Before — strict base fights permissive rule
'agree' => FluentRule::boolean()->accepted(),   // rejects 'yes' / 'on'

// 1.14 — permissive rule, no conflicting base
'agree' => FluentRule::accepted(),              // accepts 'yes' / 'on' / '1' / 1 / true / 'true'












```
Conditional variant also available — it replaces the unconditional base (so `FluentRule::accepted()->required()->acceptedIf('role', 'admin')` preserves `required` but drops the unconditional `accepted`):

```php
'agree' => FluentRule::accepted()->acceptedIf('role', 'admin'),












```
The existing `FluentRule::boolean()->accepted()` chain is unchanged (still compiles to `boolean|accepted`) — the new factory is purely additive. README rule-reference, the shipped `fluent-validation` boost skill, and the migration-pattern table all document the footgun explicitly so downstream migrations pick the right factory first time.

#### Case-sensitivity note

Laravel's `accepted` rule is strict-comparison against the accept list. `'YES'`, `'On'`, `'True'` all fail. Test coverage pins this.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.13.2...1.14.0

## 1.13.2 - 2026-04-19

Three patches surfaced within hours of 1.13.1 going live as downstream consumers started porting. Hotfix over 1.13.1.

### `actingAs()` covers Livewire component targets

Before 1.13.2, `->actingAs($user)` was silently a no-op on Livewire class-string targets — `runLivewire()` never read the bound user, so `auth()->user()` inside `mount()`, actions, and policy gates returned null. Surfaces as `Call to a member function isSuspended() on null`-style crashes in auth-aware `mount()` methods, or silent validation-skip when `authorize()` gates throw.

Fix: lifted the user-binding into a shared `applyActingAs()` helper, called from both `runFormRequest()` and `runLivewire()`. The two target paths now have symmetrical auth behavior.

```php
FluentRulesTester::for(AppealPage::class)
    ->actingAs($user)
    ->set('type', 'refund')
    ->call('submit')
    ->passes();













```
The workaround consumers used on 1.13.1 (calling `$this->actingAs($user)` on the TestCase outside the tester chain) still works — the in-chain form is now just the documented path.

### `app.key` set in Testbench env for CI Livewire runs

Every Livewire test in 1.13.1's CI failed with `No application encryption key has been specified.` Livewire's `Testable::call()` renders a Blade view under the hood, and Laravel's view encryption requires `app.key`. Local dev envs usually have APP_KEY set (via host app `.env`); Testbench's default CI env does not.

Fix: `tests/TestCase::defineEnvironment()` now sets a deterministic test-only `app.key`. Idempotent for local runs that already have a key configured.

This was strictly a package-CI bug — downstream consumers running the tester inside their own app never hit it (their `APP_KEY` is set). The release was tagged before CI went green because the local gauntlet passed; `/pre-release` now has a new step 7 that gates tag on CI-green to prevent this class of env-shape issue.

### README — two distinct Livewire test shapes

1.13.0 framed the class-string target as canonical for all Livewire validation tests. Not quite: it's specifically for component-flow dispatch tests (drive state, dispatch action, assert validation fires). Rules-only shape-assert tests should pass `$component->rules()` or a `RuleSet` and use `->with($data)`, not `->call(...)`.

README "Livewire components" section now opens with an explicit two-shape decision table so downstream migrations pick the right target first time.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.13.1...1.13.2

## 1.13.1 - 2026-04-19

### 1.13.1

Hotfix: 1.13.0's `composer.json` shipped with a broken `repositories` entry

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.13.0...1.13.1

## 1.13.0 - 2026-04-19

The tester-surface release. Four additions driven by a peer-feedback round across mijntp, hihaho, and collectiq as 1.12.x rolled out. Every feature landed with direct downstream validation before tag.

### Livewire component target

`FluentRulesTester::for(SomeLivewireComponent::class)` auto-detects Livewire `Component` subclasses and routes through `Livewire::test()` so the full `submit()` flow runs — guard clauses, `addError()` branches, computed state, rate-limit gates — not just the rule set in isolation.

```php
use SanderMuller\FluentValidation\Testing\FluentRulesTester;

FluentRulesTester::for(AppealPage::class)
    ->set('type', 'refund')
    ->set('reason', 'Order arrived damaged in transit.')
    ->call('submit')
    ->passes();















```
`set($key, $value)` and `set([$key => $value])` both work (Livewire-parity). `call(...)` queues an action; multiple `call()` / `andCall()` invocations dispatch **in append order against one `Livewire::test()` instance**, so state mutations from action 1 persist into action 2:

```php
FluentRulesTester::for(ImportInteractionsModal::class)
    ->set('video', $targetVideo)
    ->call('selectVideo', $sourceVideo->uuid)
    ->andCall('import')
    ->failsWith('selectedInteractionIds', 'required');















```
`andCall()` is a readability alias for `call()` — both append to the queue.

**Error-bag capture** covers both `$this->validate()`-driven failures AND manual `$this->addError(...)` calls. Pre-validate guards that return before `validate()` runs AND post-validate `addError` (quota checks, external-connectivity branches) both surface via the standard `failsWith()` / `failsWithAny()` / `failsOnly()` assertions.

**State is consumed per dispatch** — after one chain resolves, the accumulated `with()` / `set()` / `call()` state clears so reused testers don't leak prior cycles into new ones. Codex-flagged during adversarial review; fixed with regression coverage before this tag.

`livewire/livewire` is a soft dev dep: the Livewire branch `class_exists`-guards on `\Livewire\Component`, so PHPUnit-only suites without Livewire installed see the standard "unsupported target" `LogicException` instead of a hard fatal at autoload time.

### `failsWithAny($prefix)`

Inclusive prefix match — the error bag has an error matching `$prefix` exact OR any dotted descendant (`$prefix.*`). Useful for "did this subtree fail at all?" assertions:

```php
FluentRulesTester::for(OfflineSyncRequest::class)
    ->with($payload)
    ->failsWithAny('actions.0.payload');     // matches actions.0.payload OR actions.0.payload.stars OR …















```
Substring-match explicitly rejected — `failsWithAny('payload')` does NOT match `someOther.payload.x`. For substring or regex matching, use `errors()` directly.

### `failsOnly($field, $rule = null)` / `doesNotFailOn(...$fields)`

Sharper alternatives to `fails()`. `failsOnly` requires exactly one matching error key — surgical regression detection that fails loudly when an unrelated field *also* broke. `doesNotFailOn` is the dual: assert specific fields *did not* fail without enumerating the expected failures.

```php
->failsOnly('email', 'required');           // raises if any other field also failed
->fails()->doesNotFailOn('email', 'name');  // these passed even though others failed















```
Wildcard-expanded error keys are fully qualified (`items.0.name`, `items.1.name`). `failsOnly('items.0.name')` requires exactly one matching key. For "any item failed" use `failsWithAny('items')`.

### `RuleSet::modify($field, fn ($rule))`

Read-modify-write helper for single-field rule transformations. Clones the stored rule before passing to the callback so mutations through chain methods like `->rule(new X)` don't bleed back to prior captures of the original. Throws `LogicException` on missing key — use `put()` to add new fields.

```php
RuleSet::from($rules)
    ->modify('email', fn (FluentRule $rule) => $rule->rule(new AllowedEducationEmail()));















```
Replaces the defensive-clone-at-call-site pattern hihaho flagged in UpdateQuestionRequest post-1.12.x migration.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.12.3...1.13.0

## 1.12.3 - 2026-04-19

Four ergonomics fixes on `RuleSet` and the trait surface, all driven by the hihaho post-Fortify migration audit. None breaking; each is purely additive or documentation-only.

### `RuleSet implements IteratorAggregate`

Spread support on `RuleSet` — `[...$ruleSet, 'extra' => $rule]` works without an explicit `->toArray()` call, matching the Collection / Arrayable sibling shape.

```php
return [
    ...CreateNewUser::validationRules()->only(['email', 'password']),
    'extra' => FluentRule::string()->required(),
];
















```
Hihaho's audit found 12+ `[...parent::rules(), ...]` spread sites that 1.12.2 forced into `->put()` chains + terminal `->toArray()`. This restores the natural pattern.

### `RuleSet::all()` alias of `toArray()`

Two devs in one downstream audit hit `->all()` independently from Collection muscle memory. Aliasing is friction-free vs the alternative of throwing `BadMethodCallException` with a helpful pointer:

```php
$ruleSet->all();        // Collection-style, returns array<string, mixed>
$ruleSet->toArray();    // existing — same behavior
















```
### `HasFluentRules` + `HasFluentValidation` auto-unwrap `RuleSet` from `rules()`

Both traits now accept either a plain array or a `RuleSet` from `rules()`:

```php
public function rules(): RuleSet
{
    return CreateNewUser::validationRules()
        ->only(['email', 'password'])
        ->put('email_confirmation', FluentRule::email()->required()->same('email'));
}
















```
No more terminal `->toArray()`. This eliminates the `->toArray()` papercut at every FormRequest / Livewire component composing with `RuleSet`-returning helpers, and removes a real footgun: the hihaho audit caught one `->all()` (Collection leftover) on a method that *would have* returned a Collection mid-validation pipeline and 500'd every live registration if tests hadn't caught it. Auto-unwrap means the only thing the trait sees is what reaches Laravel's validator.

The chokepoint is local — `HasFluentRules::createDefaultValidator()` and `HasFluentValidation::resolveFluentRuleSource()` each gain one branch (`$rules instanceof RuleSet ? $rules->toArray() : $rules`). No Laravel-pipeline surgery; nothing existing in the wild relies on `rules()` returning RuleSet through the validator (it would have TypeError'd at `RuleSet::from(array)` immediately).

### `FluentRule::rule()` docblock — mutates receiver

One-line clarification that `->rule(...)` mutates the receiver and returns it. Important when chaining off a rule plucked via `RuleSet::get()` — the appended rule persists on the stored instance, no defensive copy. Clone first if you need isolation:

```php
(clone $ruleSet->get('email'))->rule(new AllowedEducationEmail());
















```
### No breaking changes

- `RuleSet` interface additions (`IteratorAggregate`, `all()`) are purely additive.
- Trait auto-unwrap is purely additive (RuleSet from `rules()` would have TypeError'd in 1.12.2; nothing in the wild can have depended on it).
- Docblock-only on `FluentRule::rule()`.
- Full test suite: **2,033 tests / 2,812 assertions**.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.12.2...1.12.3

## 1.12.2 - 2026-04-18

Three additions to `FluentRulesTester` and one ergonomics fix on `RuleSet`, all driven by real-world friction the hihaho fleet hit on the first day of 1.12.1 adoption.

### `FluentRulesTester::withRoute()`

Bind route parameters that the FormRequest reads via `$this->route(name)` inside `authorize()` or `rules()`. Without this, FormRequests doing ownership checks (`Gate::allows(Policy::ACTION, $this->route('video'))`) or conditional rule lookups (`exists('videos', 'id', fn ($r) => $r->where('container_id', $this->video()->id))`) couldn't be tested at all — they would dereference null and fatal.

```php
FluentRulesTester::for(UpdateVideoRequest::class)
    ->withRoute(['video' => $video])
    ->with(['title' => 'New title'])
    ->passes();

















```
Inside the FormRequest:

- `$this->route('video')` returns the bound `$video`
- `$this->route('video', $default)` returns `$video` (default ignored when key present)
- `$this->route('missing', $default)` returns `$default`

Re-callable; later calls fully replace earlier parameters (matches `with()`).

### `FluentRulesTester::actingAs()`

Mirrors Laravel's `actingAs($user, $guard = null)` test helper. Sets the authenticated user that `$this->user()` returns inside `authorize()` and `rules()`, scoped to a fluent chain rather than the test class.

```php
FluentRulesTester::for(UpdateVideoRequest::class)
    ->actingAs($user)
    ->with(['title' => 'New title'])
    ->passes();

















```
Composes with `withRoute()` for the common case of route + user gates:

```php
FluentRulesTester::for(UpdateVideoRequest::class)
    ->withRoute(['video' => $video])
    ->actingAs($user)
    ->with(['title' => 'New title'])
    ->passes();

















```
### `RuleSet::only()` / `except()` accept array form

Both methods now accept either variadic strings or a single array, matching `Collection::only`, `Collection::except`, `Arr::only`, and `Arr::except`:

```php
$ruleSet->only('name', 'email');     // variadic — already worked in 1.12.1
$ruleSet->only(['name', 'email']);   // array — now also works

















```
The 1.12.1 variadic-only signature was a footgun against the muscle memory built on the rest of the Laravel ecosystem; the first hihaho consumer of 1.12.1 hit `TypeError: Argument #1 must be of type string, array given` immediately on `->only([...])`. Purely additive widening.

### `failsWith()` docblock note

`FluentRule::integer()` compiles to `numeric|integer`, and a non-numeric input fails as `Numeric` (Laravel evaluates `numeric` first), not as `Integer`. The case-insensitive Studly lookup happens against Laravel's actual rule-bag keys, not the FluentRule method name. Documented inline so the surprise lands at the docblock instead of in a test failure.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.12.1...1.12.2

## 1.12.1 - 2026-04-18

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.12.0...1.12.1

## 1.12.0 - 2026-04-18

A first-class testing surface for fluent rules. `FluentRulesTester` replaces the per-app `validateRules()` reinventions and the `postJson(...)->assertJsonValidationErrors(...)` boilerplate that downstream consumers (mijntp, hihaho, collectiq) all hit when trying to unit-test their FluentRule chains, RuleSets, FormRequests, and FluentValidator subclasses without standing up the HTTP kernel or Livewire harness.

Plus four Collection-style additions to `RuleSet` (`only`, `except`, `put`, `get`) and an opt-in `Pest` expectations file.

### FluentRulesTester

```php
use SanderMuller\FluentValidation\Testing\FluentRulesTester;

// 1. Array of rules
FluentRulesTester::for([
    'email' => FluentRule::email()->required(),
])->with(['email' => 'a@b.test'])->passes();

// 2. RuleSet instance
FluentRulesTester::for(
    RuleSet::make()->field('name', FluentRule::string()->required()->min(2))
)->with(['name' => 'Ada'])->passes();

// 3. Single FluentRule (wrapped under "value" key)
FluentRulesTester::for(FluentRule::string()->required()->min(3))
    ->with(['value' => 'hi'])
    ->fails();

// 4. FormRequest class-string — runs the full FormRequest pipeline,
//    including authorize(). Call actingAs() before the tester to set
//    the user that authorize() sees.
FluentRulesTester::for(StorePostRequest::class)
    ->with(['title' => 'Hello', 'body' => 'World'])
    ->failsWith('body', 'min');

// 5. FluentValidator class-string — variadic args after `for(...)`
//    forward to the FluentValidator subclass constructor after `$data`,
//    mirroring `new MyValidator($data, $user, $prefix)`.
FluentRulesTester::for(JsonImportValidator::class, $user, 'sku-')
    ->with($payload)
    ->passes();



















```
`with(array $data)` is required before any assertion or escape hatch — calling them sooner raises `LogicException`. `with()` is re-callable, so a single tester can validate multiple data sets without rebuilding.

#### Assertions

| Method | Asserts |
|---|---|
| `passes()` | underlying validation passed |
| `fails()` | underlying validation failed |
| `failsWith($field)` | `MessageBag::has($field)` |
| `failsWith($field, $rule)` | `Validator::failed()` shows `$field` failed `Str::studly($rule)` (so `'required'` and `'Required'` both work) |
| `failsWithMessage($field, $key, $replacements = [])` | `errors()->first($field) === __($key, $replacements)` — replaces the `assertJsonValidationErrors([... => [__('validation.x', [...])]])` pattern |
| `assertUnauthorized()` | recorded `AuthorizationException` (not rethrown) |
| `errors()` | underlying `MessageBag` |
| `validated()` | validated array (throws `ValidationException` on failure) |

#### FormRequest resolution

The FormRequest path mirrors what Laravel's own form-request resolver does: instantiate via `createFrom()`, set container + redirector + user resolver, call `validateResolved()` in try/catch. `ValidationException` and `AuthorizationException` are recorded into a `Validated` DTO instead of rethrown — so tests assert on outcomes rather than wrapping in their own try/catch.

The user resolver is wired to the auth guard, so `$this->user()` inside `authorize()` honours `actingAs($user)` calls made before invoking the tester.

### Optional Pest expectations

Three opt-in expectations live in `src/Testing/PestExpectations.php`. Consumers `require_once` from their `tests/Pest.php` to register them:

```php
// tests/Pest.php
require_once __DIR__ . '/../vendor/sandermuller/laravel-fluent-validation/src/Testing/PestExpectations.php';



















```
```php
expect($rules)->toPassWith(['email' => 'a@b.test']);
expect($rules)->toFailOn(['email' => ''], 'email', 'required');
expect(FluentRule::string()->required())->toBeFluentRuleOf(StringRule::class);



















```
The file `class_exists`-guards on `Pest\Expectation` and short-circuits when Pest is unavailable, so it's safe to load under PHPUnit-only suites too.

### RuleSet additions

Four Collection-style methods on `RuleSet`, matching the existing `field()` / `merge()` mutate-and-return-self pattern:

```php
$ruleSet->only('name', 'email');           // keep only the named fields
$ruleSet->except('age');                   // drop the named fields
$ruleSet->put('name', $rule);              // add or replace a single field's rule (alias of field())
$ruleSet->get('name', $default = null);    // read a stored rule (uncompiled), or $default if absent



















```
`get()` returns the raw stored value (FluentRule object, string, array — whatever was stored), uncompiled and unexpanded.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.11.0...1.12.0

## 1.11.0 - 2026-04-17

Presence-conditional rules join the item-aware fast-check family. `required_with`, `required_without`, `required_with_all`, and `required_without_all` now bypass Laravel's validator for wildcard items that satisfy the condition, with full composition against the `same` / `different` / `confirmed` / date / `gt` / `gte` / `lt` / `lte` field-ref rules landed in 1.10.0.

### What's fast-checked now

| Rule | Trigger |
|------|---------|
| `required_with:a,b,...` | ANY listed field present → target required |
| `required_without:a,b,...` | ANY listed field absent → target required |
| `required_with_all:a,b,...` | ALL listed fields present → target required |
| `required_without_all:a,b,...` | ALL listed fields absent → target required |

Multi-param supported for every variant. "Present" matches Laravel's `validateRequired` exactly — not null, not whitespace-only string (`trim() === ''`), not empty array, not empty `Countable`.

### Composition with field-ref rules

Presence conditionals now compose with the item-aware rules from 1.10.0 into a single fast closure:

```php
RuleSet::from([
    'orders' => FluentRule::array()->required()->each([
        'trigger' => FluentRule::string()->nullable(),
        'confirm' => FluentRule::string()->rule('required_with:trigger|same:trigger'),

        'min_qty' => FluentRule::numeric()->required(),
        'qty' => FluentRule::numeric()->rule('required_without:fallback|gt:min_qty'),

        'start' => FluentRule::date()->nullable(),
        'end'   => FluentRule::date()->rule('required_without_all:start|after:start'),
    ]),
])->validate($data);




















```
Before 1.11.0, any of those combinations would silently fall through to Laravel's validator because the presence compiler only accepted value-only remainders. 1.11.0's compiler delegates stripped remainders through `compileWithItemContext` so combinations keep all of their speedup.

### Benchmark impact

Isolated harness (1000 items × 7 fields × 3 presence-conditional rules):

| Version | Optimized | Speedup vs Laravel |
|---------|----------:|-------------------:|
| 1.10.0 (slow path) | ~100.6ms | 1x (full Laravel) |
| 1.11.0 (fast-check) | **~7ms** | **~14x** |

`benchmark.php --ci` vs 1.10.0 across two clean runs: Product −7% / −10%, Nested −15% / −16%, Event/Article/Conditional within noise. No regressions.

### API

One new public method on `FastCheckCompiler`:

```php
public static function compileWithPresenceConditionals(string $ruleString): ?\Closure




















```
Returns `?\Closure(mixed $value, array<string, mixed> $item): bool`. The closure evaluates the presence condition(s) against the item at call time, then either (a) fails fast if the target is required but empty, or (b) runs the stripped-remainder closure. `RuleSet::buildFastChecks` picks this up automatically as a third fallback after `compile()` and `compileWithItemContext()` — existing call sites benefit without code changes.

### Parity

The same adversarial loop that shipped 1.10.0 caught two drift patterns in the first implementation — both fixed before release:

1. **Presence definition was too strict.** The initial check used `! in_array($raw, [null, '', []], true)`, which treats `'   '` and empty `Countable` as present. Laravel's `validateRequired` treats them as empty. Fixed by centralizing on a new `isLaravelEmpty()` helper matching Laravel exactly: `null`, `trim() === ''` for strings, empty array, empty `Countable`.
   
2. **No composition with item-aware remainders.** The first cut compiled the stripped remainder via value-only `compile()`, so `required_with:trigger|same:other` fell to slow path. The second pass added `buildItemAwareBranch()` which prefers `compileWithItemContext` (handles same / different / date-ref / gt/gte/lt/lte) and wraps value-only rules to the item-aware signature.
   

Full parity coverage:

| Grid | Assertions |
|------|-----------:|
| Flat value rules | 720 |
| Item-aware date field-refs | 117 |
| Item-aware same/different | 88 |
| Item-aware confirmed | 7 |
| Item-aware gt/gte/lt/lte | 272 |
| Item-aware presence conditionals | 44 |
| Item-aware presence composition | 8 |

Total: **1,256 parity assertions** against `Validator::make(...)->passes()`.

### Documented limitation

The closure receives `null` both for "item key missing" and "item key present with null value" because `RuleSet` passes `$item[$field] ?? null`. Laravel's `presentOrRuleIsImplicit` distinguishes these via `Arr::has`; the closure can't. For non-implicit remainders (e.g. `same:other`), this means a genuinely absent target may fail the fast path where Laravel would skip. The `RuleSet::buildFastChecks` wrapper absorbs this: when the fast path rejects, Laravel's validator re-evaluates the item and produces the correct verdict. End-to-end validation behavior is identical; only the fast-check path is a touch stricter than strictly necessary.

### No breaking changes

- Existing rule sets keep working without modification.
- `FastCheckCompiler::compile()` and `compileWithItemContext()` signatures unchanged.
- New `compileWithPresenceConditionals()` is additive.
- Full test suite: **1,954 tests / 2,664 assertions**.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.10.0...1.11.0

## 1.10.0 - 2026-04-17

Item-aware fast-check for cross-field rules. Wildcard items with date-sibling, equality-sibling, or size-sibling comparisons now skip Laravel's validator when values pass — same mechanism as `RuleSet::validate`'s existing fast-check, extended to rules that reference another field in the same item.

### What's fast-checked now

Previously these rules always fell through to Laravel because they need to read a sibling field at validation time:

| Rule family | Examples |
|-------------|----------|
| Date field-ref | `after:start_date`, `before:start_date`, `after_or_equal:X`, `before_or_equal:X`, `date_equals:X` |
| Equality | `same:FIELD`, `different:FIELD` (single-param only) |
| Confirmation | `confirmed`, `confirmed:custom_field` |
| Size comparison (with type flag) | `numeric\|gt:min_price`, `string\|gt:other`, `array\|gte:baseline`, `integer\|lte:stock`, etc. |

All of them now resolve the sibling field via a new closure variant at validation time, matching Laravel's semantics including:

- `nullable` vs `required` short-circuits
- `presentOrRuleIsImplicit` empty-string skip
- Laravel's loose-coercion behavior for unresolvable date refs (null → 0 in comparisons)
- Laravel's `isSameType` constraint for `gt`/`gte`/`lt`/`lte` (rejects type-mismatched refs)
- Strict `===` / `!==` for `same` / `different`
- `confirmed` rewrite to `same:${attribute}_confirmation`

Rules that still fall through to Laravel:

- `gt` / `gte` / `lt` / `lte` without a type flag (`string`, `array`, `numeric`, or `integer`)
- `date_format:X` + date field-ref (Laravel's format-aware parsing + lenient missing-ref handling can't be matched by a simple closure)
- Multi-param `different:a,b,c`
- Custom Rule objects, closures, `distinct`, `exists`/`unique` with closure callbacks

### Benchmark impact

Event scheduling (`benchmark.php` — 100 events × 3 date-with-sibling-ref rules):

| Version | Optimized | Speedup |
|---------|----------:|--------:|
| 1.9.1 | 10.4ms | ~2x |
| 1.10.0 | **0.7ms** | **~29x** |

All other `benchmark.php` scenarios: within ±5% of 1.9.1 (noise). DB-batching scenarios (`--group=benchmark`): unchanged.

### API

One new public method on `FastCheckCompiler`:

```php
public static function compileWithItemContext(
    string $ruleString,
    ?string $attributeName = null,
): ?\Closure





















```
Returns `?\Closure(mixed $value, array<string, mixed> $item): bool`. The closure resolves field references like `after:FIELD`, `same:FIELD`, `gt:FIELD` against the passed item array. Passing `$attributeName` is required for `confirmed` rule rewriting (the confirmation field name depends on the attribute being validated).

`RuleSet::buildFastChecks` uses this method as a fallback when the standard `compile()` call returns null, so existing call sites pick up the speedup automatically — no user code changes required.

### Parity

Three parity grids assert the fast-check closure's verdict matches `Validator::make(...)->passes()` for every supported rule across edge-case values:

| Grid | Rules × items | Assertions |
|------|---------------|-----------:|
| Flat value rules | 40 × 18 | 720 |
| Item-aware date field-refs | 13 × 9 | 117 |
| Item-aware same/different | 8 × 11 | 88 |
| Item-aware confirmed | 7 cases | 7 |
| Item-aware gt/gte/lt/lte | 16 × 17 | 272 |

Total: **1204 parity assertions**. An adversarial code review by OpenAI Codex caught two drift patterns during development, both fixed:

- The `null`/empty-string short-circuit was too broad for equality rules. Fixed by capturing `$nullable` and `$hasImplicit` in the closure and matching Laravel's skip semantics precisely.
- `date_format` + date field-ref bypassed the attribute's format. Fixed by bailing `compileWithItemContext` to the slow path when both are present — Laravel's `checkDateTimeOrder` has format-aware parsing and lenient missing-ref behavior that `strtotime()` can't match.

### `RuleSet::validate` integration tests

New end-to-end tests assert the fast-check path actually rejects bad data (not just that the closure is correct in isolation):

- Date field-ref `after:sibling` / `before:sibling` pass/fail paths
- Combined `after:a|before:b` (dual-gate)
- `same:password` / `different:username` match/mismatch
- `confirmed` (default and custom suffix) match/mismatch/missing
- `numeric|lte:stock`, `string|gt:short`, combined `gt:min|lt:max`

### Other

- **Pre-release skill** (`.ai/skills/pre-release/`) gained a docs-freshness audit step: the skill now checks README + `.ai/` skills and guidelines for staleness before a release.
- **Release automation guideline** (`.ai/guidelines/release-automation.md`) documents that `CHANGELOG.md` and the benchmark-table section of release bodies are updated automatically by CI — not manually in the release PR.
- **Rector `RepeatedOrEqualToInArrayRector` skipped for `src/FastCheckCompiler.php`** to preserve the inlined `=== null || === '' || === []` presence gate that keeps the hot path allocation-free.

### No breaking changes

- Existing rule sets keep working without modification.
- `FastCheckCompiler::compile()` signature and semantics unchanged.
- Public API gained one optional method parameter (`$attributeName`); existing callers unaffected.
- Full test suite: **1895 tests / 2592 assertions**.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.9.2...1.10.0

## 1.9.2 - 2026-04-17

### Fast-check date field references (12–16x faster for wildcard date rules)

Before 1.9.2, rules like `after:start_date` or `before:start_date` inside a wildcard `each()` block always fell through to Laravel's validator. The fast-check compiler bailed the moment it saw a non-literal date param, so validation walked through `$validator->passes()` once per item.

1.9.2 adds a new closure variant that resolves sibling field references at call time, keeping these validations in the fast path.

```php
RuleSet::from([
    'events' => FluentRule::array()->required()->each([
        'name'        => FluentRule::string()->required()->min(3)->max(255),
        'start_date'  => FluentRule::date()->required()->after('2025-01-01'),
        'end_date'    => FluentRule::date()->required()->after('start_date'),
        'registration_deadline' => FluentRule::date()->required()->before('start_date'),
    ]),
])->validate($data);






















```
For 100 events with the rule set above, the optimized path used to invoke Laravel's validator 300 times (3 date-field-ref rules × 100 items). It now runs entirely in PHP closures.

#### Benchmark Δ vs 1.9.1 (isolated harness, 100 events × 4 fields)

| Metric | 1.9.1 | 1.9.2 | Δ |
|--------|------:|------:|----:|
| Median execution time | 10.20ms | 0.65ms | **−94%** |

#### Benchmark Δ vs 1.9.0 (full `benchmark.php --ci`, two clean runs)

| Scenario | 1.9.0 | 1.9.2 run 1 | 1.9.2 run 2 |
|----------|------:|------------:|------------:|
| Event scheduling (field-ref dates) | 10.4ms / ~2x | 0.7ms / ~29x | 0.7ms / ~27x |

All other scenarios are within noise vs 1.9.0 (-28% to +1%).

### API

New public method on `FastCheckCompiler`:

```php
public static function compileWithItemContext(string $ruleString): ?\Closure






















```
Returns `?\Closure(mixed $value, array<string, mixed> $item): bool`. The closure receives the current value and the wildcard item, resolving date field references like `after:FIELD`, `before:FIELD`, `after_or_equal:FIELD`, `before_or_equal:FIELD`, and `date_equals:FIELD` against `$item[FIELD]`.

`RuleSet::buildFastChecks` uses this method as a fallback when the standard `compile()` call returns null, so existing call sites pick up the speedup automatically — no code changes required.

#### Supported rules (field-ref form)

- `after:FIELD`
- `after_or_equal:FIELD`
- `before:FIELD`
- `before_or_equal:FIELD`
- `date_equals:FIELD`

Other field-referenced comparison rules (e.g. `gt:FIELD`, `lt:FIELD`) still fall through to Laravel — they can be added the same way if demand warrants it.

### Parity with Laravel

A new item-aware parity grid (`tests/FastCheckParityTest.php`) asserts that the field-ref closure verdict matches `Validator::make(...)->passes()` across 6 rules × 9 item shapes (54 new assertions, 792 total).

The grid surfaced one Laravel quirk worth documenting: when the referenced field can't be resolved to a valid timestamp (null, missing, empty, unparseable), Laravel treats its value as 0 in the comparison — so `after:bad_ref` with a valid current date silently **passes**, while `before:bad_ref` / `before_or_equal:bad_ref` / `date_equals:bad_ref` silently **fail**. The `resolveRefTimestamp()` helper matches this behavior exactly.

### Pre-filter optimization

`compileWithItemContext` is pre-filtered to only re-parse rules that actually contain `after:`, `before:`, or `date_equals:`. Without this filter, every slow rule paid for a redundant second parse — the Conditional import benchmark briefly drifted +19% before the filter was added. With the filter in place, all non-Event-scheduling scenarios are within noise vs 1.9.1.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.9.1...1.9.2

## 1.9.0 - 2026-04-15

### Added

- `RuleSet::check()` returns an immutable `Validated` result object for errors-as-data flows. Methods: `passes()`, `fails()`, `errors()`, `firstError($field)`, `validated()`, `safe()` (returns `Illuminate\Support\ValidatedInput`), and `validator()` (escape hatch).
- Fast-check closures now apply to flat top-level rules, not just wildcard rules. A rule set like `['name' => 'string|max:255']` skips Laravel's validator when values pass.

### Fixed

FastCheckCompiler rewrite for Laravel parity. A new parity suite (`tests/FastCheckParityTest.php`) surfaced 75 drifts against `Validator::make(...)->passes()`, all fixed:

- Null no longer short-circuits to pass; type rules correctly fail on null without `nullable`.
- `nullable` bypasses null only when no implicit rule (required/accepted/declined) needs to run.
- `'' + non-implicit rule` now passes (Laravel's `presentOrRuleIsImplicit` semantics).
- `required` fails on empty arrays.
- `array|min`/`array|max` enforce `count()`-based size checks.
- `alpha`/`alpha_dash`/`alpha_num` accept `int`/`float` via cast; reject `bool`/`null`/`array`.
- `regex`/`not_regex` require `is_string || is_numeric`; reject booleans, nulls, arrays.
- `in`/`not_in` reject non-scalars.
- Dotted rule keys (from `children()`) now fall through to Laravel in the non-wildcard path, ensuring nested lookup and validated-data shaping match Laravel.

### Changed (non-fast-checkable)

- `filled` and `sometimes` now route through Laravel. Distinguishing absent from present-null requires presence tracking the closure doesn't have; marking them non-fast-checkable avoids silent acceptance of `{field: null}`.
- Size rules (`min`/`max`) without a type flag (`string`/`array`/`numeric`/`integer`) are non-fast-checkable. Laravel infers size from runtime value type; the fast path defers.

### Breaking

Users who relied on the previous lenient null behavior will see rules fail where they previously passed. These were bugs — the fast path silently accepted invalid input. Add `nullable` to rules that should accept null:

```diff
- 'bio' => 'string|max:500',
+ 'bio' => 'nullable|string|max:500',






















```
**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.8.2...1.9.0

## 1.8.2 - 2026-04-15

### Fix

- Added `@return array<string, array<mixed>>` PHPDoc to `RuleSet::compileToArrays()`. Resolves PHPStan errors on call sites like `$this->validate(RuleSet::compileToArrays(...))` where the return type was inferred as plain `array`.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.8.1...1.8.2

## 1.8.1 - 2026-04-15

### HasFluentValidationForFilament reworked

`HasFluentValidationForFilament` now uses standard `validate()` and `validateOnly()` method names instead of the `validateFluent()` / `validateOnlyFluent()` methods from 1.8.0. Users write a one-time `insteadof` block and then use validation as they normally would:

```php
class EditUser extends Component implements HasForms
{
    use HasFluentValidationForFilament, InteractsWithForms {
        HasFluentValidationForFilament::validate insteadof InteractsWithForms;
        HasFluentValidationForFilament::validateOnly insteadof InteractsWithForms;
        HasFluentValidationForFilament::getRules insteadof InteractsWithForms;
        HasFluentValidationForFilament::getValidationAttributes insteadof InteractsWithForms;
    }

    public function rules(): array
    {
        return [
            'slug' => FluentRule::string('URL Slug')->required()->alphaDash(),
        ];
    }

    public function save(): void
    {
        $validated = $this->validate(); // works as expected
    }
}
























```
#### Filament form-schema rules preserved

`getRules()` now merges FluentRule-compiled rules from `rules()` with Filament's form-schema validation rules from `getCachedForms()`. Both sources contribute to validation. `getValidationAttributes()` merges labels from both sources too.

Previously, schema-defined rules were silently lost when FluentRules were present. Now a component can define FluentRules in `rules()` for some fields and use Filament's schema-driven validation for others.

#### Filament error dispatch preserved

`validate()` and `validateOnly()` wrap validation errors with Filament's `form-validation-error` event dispatch and `onValidationError()` hook, matching `InteractsWithForms` behavior.

#### Works with Filament v3, v4, and v5

The `insteadof` block targets `InteractsWithForms` (v3/v4). For Filament v5, replace `InteractsWithForms` with `InteractsWithSchemas`.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.8.0...1.8.1

## 1.8.0 - 2026-04-15

### Filament support via `HasFluentValidationForFilament`

New trait for Livewire components that use Filament's `InteractsWithForms` (v3/v4) or `InteractsWithSchemas` (v5). Unlike `HasFluentValidation`, this trait doesn't override `validate()`, `validateOnly()`, `getRules()`, or `getValidationAttributes()`, so there are no trait collisions.

```php
use Filament\Forms\Concerns\InteractsWithForms;
use SanderMuller\FluentValidation\HasFluentValidationForFilament;

class EditUser extends Component implements HasForms
{
    use HasFluentValidationForFilament, InteractsWithForms;

    public function rules(): array
    {
        return [
            'name' => FluentRule::string('Name')->required()->max(255),
        ];
    }

    public function save(): void
    {
        $validated = $this->validateFluent();
        // ...
    }
}

























```
`validateFluent()` compiles FluentRule objects, extracts labels and messages, and delegates to Filament's `validate()`. Filament's form-schema validation, error dispatching, and `$this->form->getState()` all work normally.

### `RuleSet::compileWithMetadata()`

New convenience method that compiles rules and extracts labels/messages in one call. Returns `[rules, messages, attributes]` matching `validate()`'s parameter order.

```php
[$rules, $messages, $attributes] = RuleSet::compileWithMetadata($this->rules());
$this->validate($rules, $messages, $attributes);

























```
Useful for any context where you need compiled rules with metadata outside of a FormRequest or the Livewire traits.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.7.1...1.8.0

## 1.7.1 - 2026-04-14

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.7.0...1.7.1

## 1.7.0 - 2026-04-14

### Full Livewire support

`HasFluentValidation` now provides complete Livewire integration. The trait overrides `getRules()`, `getMessages()`, and `getValidationAttributes()` in addition to `validate()` and `validateOnly()`.

#### `each()` and `children()` work in Livewire

Previously, using `each()` in a Livewire component would silently break real-time validation because Livewire reads rule keys before compilation. This is now handled automatically. The trait flattens `each()` into wildcard keys and `children()` into fixed paths before Livewire sees them.

```php
class EditOrder extends Component
{
    use HasFluentValidation;

    public function rules(): array
    {
        return [
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string('Item Name')->required(),
                'qty'  => FluentRule::numeric()->required()->integer()->min(1),
            ]),
        ];
    }
}



























```
Both `each()` and flat wildcard keys (`'items.*.name' => FluentRule::string()`) continue to work.

#### Labels and messages work automatically

Labels from `->label()` and messages from `->message()` are now extracted and passed to Livewire's validation. No separate `messages()` or `validationAttributes()` method needed.

```php
// "The Full Name field is required" — not "The name field is required"
'name' => FluentRule::string('Full Name')->required()->message('Please enter your name'),



























```
#### `$rules` property support

Components that define rules via a `$rules` property instead of a `rules()` method now work correctly.

### BackedEnum support in conditional rules

All conditional rule methods now accept `BackedEnum` values directly. Previously, passing an enum case would throw a TypeError.

```php
// Before: ->excludeUnless('status', Status::DRAFT->value)
// After:  ->excludeUnless('status', Status::DRAFT)



























```
This applies to `excludeIf`, `excludeUnless`, `requiredIf`, `requiredUnless`, `prohibitedIf`, `prohibitedUnless`, `presentIf`, `presentUnless`, `missingIf`, and `missingUnless`. Enum cases are serialized to their backing value automatically.

### Other improvements

- Added `RuleSet::flattenRules()` public method for wildcard-preserving rule expansion
- README: added trait requirement callout with decision table for FormRequest / Livewire / inline usage
- README: added `each()` vs `children()` comparison table
- README: documented `FluentRule::macro()` for factory-level custom rule types
- README: noted Rector companion as ongoing code quality tool, not just migration

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.6.0...1.7.0

## 1.6.0 - 2026-04-12

### Added

- **Fast-check date rules** — `date`, `date_format`, `after`, `before`, `after_or_equal`, `before_or_equal`, and `date_equals` rules with literal dates are now fast-checkable. A single `strtotime()` call per value replaces full Laravel validator creation. Field references (e.g., `after:start_date`) correctly fall through to standard validation.
- **Fast-check `array` and `filled` rules** — `array` and `filled` are now handled by the fast-check compiler, eliminating validator overhead for these common rules.
- **Nested wildcard fast-checks** — Wildcard patterns like `options.*.label` are now fast-checked by expanding within the per-item closure. Previously these fell through to per-item validators (~25ms), now resolved in <1ms.
- **`FluentRules` marker attribute** — Mark non-`rules()` methods with `#[FluentRules]` so migration tooling (Rector) detects them. The attribute has no runtime effect.

### Improved

- **OptimizedValidator hot path** — Attributes are pre-grouped by wildcard pattern for cache-local iteration. Uses `Arr::dot()` for O(1) flat data lookups instead of per-attribute `getValue()` calls.
- **BatchDatabaseChecker dedup** — Extracted `uniqueStringValues()` helper using `SORT_STRING` (3.7x faster than `SORT_REGULAR`).
- **PrecomputedPresenceVerifier** — String-cast flip maps (`isset()`) replace `in_array()` for O(1) lookups. Fixes type mismatch between database integer values and form string values.
- **RuleSet parameter threading** — `$flatRules` parameter threaded through `prepare()`, `expand()`, and `separateRules()` to avoid redundant `flatten()` calls.

### New companion package

- **Rector migration rules** — A new companion package [`sandermuller/laravel-fluent-validation-rector`](https://github.com/sandermuller/laravel-fluent-validation-rector) provides 6 Rector rules that automate migration from native Laravel validation to FluentRule. In real-world testing against a production codebase, the rules converted 448 files across 3469 tests with zero regressions.
  ```bash
  composer require --dev sandermuller/laravel-fluent-validation-rector
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  ```

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.5.0...1.6.0

## 1.5.0 - 2026-04-12

### Added

- **Fast-check date rules** — `date`, `date_format`, `after`, `before`, `after_or_equal`, `before_or_equal`, and `date_equals` rules with literal dates are now fast-checkable. A single `strtotime()` call per value replaces full Laravel validator creation. Field references (e.g., `after:start_date`) correctly fall through to standard validation.
- **Fast-check `array` and `filled` rules** — `array` and `filled` are now handled by the fast-check compiler, eliminating validator overhead for these common rules.
- **Nested wildcard fast-checks** — Wildcard patterns like `options.*.label` are now fast-checked by expanding within the per-item closure. Previously these fell through to per-item validators (~25ms), now resolved in <1ms.
- **`FluentRules` marker attribute** — Mark non-`rules()` methods with `#[FluentRules]` so migration tooling (Rector) detects them. The attribute has no runtime effect.

### Improved

- **OptimizedValidator hot path** — Attributes are pre-grouped by wildcard pattern for cache-local iteration. Uses `Arr::dot()` for O(1) flat data lookups instead of per-attribute `getValue()` calls.
- **BatchDatabaseChecker dedup** — Extracted `uniqueStringValues()` helper using `SORT_STRING` (3.7x faster than `SORT_REGULAR`).
- **PrecomputedPresenceVerifier** — String-cast flip maps (`isset()`) replace `in_array()` for O(1) lookups. Fixes type mismatch between database integer values and form string values.
- **RuleSet parameter threading** — `$flatRules` parameter threaded through `prepare()`, `expand()`, and `separateRules()` to avoid redundant `flatten()` calls.

### New companion package

- **Rector migration rules** — A new companion package [`sandermuller/laravel-fluent-validation-rector`](https://github.com/sandermuller/laravel-fluent-validation-rector) provides 6 Rector rules that automate migration from native Laravel validation to FluentRule. In real-world testing against a production codebase, the rules converted 448 files across 3469 tests with zero regressions.
  ```bash
  composer require --dev sandermuller/laravel-fluent-validation-rector
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  ```

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.4.1...1.5.0

## 1.4.1 - 2026-04-10

### Fixed

- **PHP 8.2 compatibility** — Removed typed constant (`private const int`) syntax from `BatchDatabaseChecker` which requires PHP 8.3+. The package supports PHP 8.2+.
- **PHPStan CI failures** — Excluded `src/Rector` from PHPStan analysis paths and removed stale baseline entries referencing uncommitted Rector files.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.4.0...1.4.1

## 1.4.0 - 2026-04-10

### Added

- **Batched database validation for wildcard arrays** — `exists` and `unique` rules on wildcard fields (`items.*.email`) now run a single `whereIn` query instead of one query per item. For 500 items, that's 1 query instead of 500. Works in both `RuleSet::validate()` and `HasFluentRules` form requests.
  ```php
  'items' => FluentRule::array()->required()->each([
      'product_id' => FluentRule::integer()->required()->exists('products', 'id'),
  ]),
  // 500 items × exists rule = 1 query instead of 500
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  ```
  Rules with scalar `where()` clauses (e.g., `->exists('subjects', 'id', fn ($r) => $r->where('video_id', $id))`) are batched too. Rules with closure callbacks fall through to per-item validation as before. Verified against hihaho's full test suite (3533 tests, 0 failures).

### How it works

A `PrecomputedPresenceVerifier` replaces Laravel's default verifier on per-item validators, returning pre-computed results from the batch query. Original `Exists`/`Unique` rule objects stay in place, so custom messages (`['items.*.email.exists' => '...']`), custom attributes, `ignore()`, and `validated()` output all work unchanged.

**Safety guards:**

- Rules with closure callbacks (`queryCallbacks()`) are not batched
- Rules without an explicit column are not batched
- Custom (non-default) presence verifiers cause batching to be skipped
- Non-wildcard single-field rules are never batched
- Falls back to the original verifier for any rule that wasn't pre-computed

### Internal

- New classes: `BatchDatabaseChecker`, `PrecomputedPresenceVerifier`
- 40 batch database validation tests covering: exists/unique batching, ignore(), withoutTrashed(), scalar where clauses, fallback verifier, non-wildcard rejection, empty/null value handling, deduplication, custom messages, FormRequest + RuleSet paths
- Exists/unique benchmark scenario added to benchmark suite
- PHPStan complexity thresholds adjusted (class: 80, function: 20)

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.3.0...1.4.0

## 1.3.0 - 2026-04-10

### Added

- **`RuleSet::failOnUnknownFields()`** — Reject input keys not present in the rule set. Mirrors Laravel 13.4's `FormRequest::failOnUnknownFields` for standalone `RuleSet` validation. Unknown fields receive a `prohibited` validation error with full support for custom messages and attributes:
  
  ```php
  RuleSet::from([
      'name'  => FluentRule::string()->required(),
      'email' => FluentRule::email()->required(),
  ])->failOnUnknownFields()->validate($request->all());
  // Extra keys like 'hack' => '...' will fail with "The hack field is prohibited."
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  ```
- **`RuleSet::stopOnFirstFailure()`** — Stop validating remaining fields after the first failure. Works across top-level fields, wildcard groups, and per-item validation:
  
  ```php
  RuleSet::from([
      'name'  => FluentRule::string()->required(),
      'items' => FluentRule::array()->required()->each([
          'qty' => FluentRule::numeric()->required()->min(1),
      ]),
  ])->stopOnFirstFailure()->validate($data);
  // Stops after the first failing field or item
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  ```

### Improved

- **`messageFor()` documentation** — Promoted from the rule reference to the primary recommendation in the per-rule messages section. `->messageFor('required', 'msg')` can be called anywhere in the chain without the ordering constraint of `->message()`.
- **README** — Labels note now links to all four approaches that support extraction (`HasFluentRules`, `RuleSet::validate()`, `HasFluentValidation`, `FluentValidator`). Comparison table cleaned up. Per-rule messages section restructured. Tightened prose throughout.

### Internal

- 15 new tests for `failOnUnknownFields` and `stopOnFirstFailure` covering: wildcard matching, nested children, scalar each, deeply nested wildcards, custom messages/attributes, early-exit on wildcard arrays, and opt-in behavior.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.2.0...1.3.0

## 1.2.0 - 2026-04-10

#### Added

- **`FluentRule::macro()`** — Register custom factory methods on the main FluentRule class. Define domain-specific entry points like `FluentRule::phone()` or `FluentRule::iban()` in a service provider:
  
  ```php
  FluentRule::macro('phone', fn (?string $label = null) => FluentRule::string($label)->rule(new PhoneRule()));
  // Usage: FluentRule::phone('Phone Number')->required()
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  ```
- **`RuleSet` is now `Macroable`** — Add composable rule groups to RuleSet:
  
  ```php
  RuleSet::macro('withAddress', fn () => $this->merge([
      'street' => FluentRule::string()->required(),
      'city'   => FluentRule::string()->required(),
      'zip'    => FluentRule::string()->required()->max(10),
  ]));
  // Usage: RuleSet::make()->withAddress()->field('name', FluentRule::string())
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  ```

#### Improved

- **`HasFluentValidation` trait** — Added explicit `mixed` types for PHP 8.5 compatibility, private narrowing helpers (`toNullableArray`, `toStringMap`) for PHPStan level max, and made `compileFluentRules()` protected so it can be called from subclasses.
- **`messageFor()` documentation** — Promoted from the rule reference to the primary recommendation in the per-rule messages section. `->messageFor('required', 'msg')` can be called anywhere in the chain without the ordering constraint of `->message()`.
- **README** — Labels note now links to all four approaches that support extraction (`HasFluentRules`, `RuleSet::validate()`, `HasFluentValidation`, `FluentValidator`). Comparison table cleaned up. Tightened prose throughout.
- Recommend `RuleSet::validate()` over `Validator::make()` in README — `RuleSet::validate()` applies the full optimization pipeline (wildcard expansion, fast-checks, label extraction) that `Validator::make()` misses.

#### Internal

- Applied Rector's `LARAVEL_CODE_QUALITY` set (`app()` → `resolve()`, Translator contract binding).
- Rector Pest code quality: fluent assertion chains, `toBeFalse()` over `toBe(false)`.
- `fn` → `static fn` for closures that don't use `$this` (Rector).
- Added `HasFluentValidation` test suite (14 tests covering compilation, labels, messages, override behavior, wildcard expansion, `unwrapDataForValidation`, and data resolution fallback).
- Benchmark PR comments now include Pest code path benchmarks alongside the main benchmark table.
- Improved AI skill triggers to prevent editing generated files directly.
- Added benchmark running documentation to backend-quality skill.
- CI workflow hardening: stash before pull, env var for branch names.

### What's Changed

* chore(deps): bump peter-evans/create-or-update-comment from 4 to 5 by @dependabot[bot] in https://github.com/SanderMuller/laravel-fluent-validation/pull/7
* chore(deps): bump actions/upload-artifact from 4 to 7 by @dependabot[bot] in https://github.com/SanderMuller/laravel-fluent-validation/pull/1
* chore(deps): bump stefanzweifel/git-auto-commit-action from 5 to 7 by @dependabot[bot] in https://github.com/SanderMuller/laravel-fluent-validation/pull/6
* chore(deps): bump actions/checkout from 4 to 6 by @dependabot[bot] in https://github.com/SanderMuller/laravel-fluent-validation/pull/5
* chore(deps): bump actions/download-artifact from 4 to 8 by @dependabot[bot] in https://github.com/SanderMuller/laravel-fluent-validation/pull/2
* chore(deps): bump peter-evans/find-comment from 3 to 4 by @dependabot[bot] in https://github.com/SanderMuller/laravel-fluent-validation/pull/4
* chore(deps): bump actions/cache from 4 to 5 by @dependabot[bot] in https://github.com/SanderMuller/laravel-fluent-validation/pull/3

### New Contributors

* @dependabot[bot] made their first contribution in https://github.com/SanderMuller/laravel-fluent-validation/pull/7

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.1.0...1.2.0

## 1.1.0 - 2026-04-08

### Added

- **Complete Laravel 13 rule coverage** — every validation rule in Laravel now has a native fluent method. No more `->rule()` escape hatches for built-in rules.
  
- **New field modifiers** (available on all rule types):
  
  - `presentIf($field, ...$values)`, `presentUnless($field, ...$values)`, `presentWith(...$fields)`, `presentWithAll(...$fields)`
  - `requiredIfAccepted($field)`, `requiredIfDeclined($field)`
  - `prohibitedIfAccepted($field)`, `prohibitedIfDeclined($field)`
  
- **New array methods:** `contains(...$values)` and `doesntContain(...$values)`.
  
- **New string method:** `encoding($encoding)` for validating string encoding (UTF-8, ASCII, etc.).
  
- **Convenience factory shortcuts:** `FluentRule::url()`, `FluentRule::uuid()`, `FluentRule::ulid()`, `FluentRule::ip()` — shorthand for `FluentRule::string()->url()`, etc.
  
- **Debugging tools:**
  
  - `->toArray()` on any rule — returns the compiled rules as an array
  - `->dump()` / `->dd()` on any rule — dumps the compiled rules
  - `RuleSet::from([...])->dump()` — returns `{rules, messages, attributes}` for inspection
  - `RuleSet::from([...])->dd()` — dumps and dies
  

### Fixed

- **`present_*` rules in self-validation** — `presentIf`, `presentUnless`, `presentWith`, and `presentWithAll` now correctly trigger validation for absent fields. Previously, self-validation would silently skip absent fields when these rules were used.
  
- **`toArray()` on untyped field rules** — `FluentRule::field()->toArray()` now returns `[]` instead of `['']`.
  

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.0.1...1.1.0

## 1.0.1 - 2026-04-07

### Fixed

- **`stopOnFirstFailure` respected on FormRequest** — `$stopOnFirstFailure = true` on a FormRequest class was silently ignored when using `HasFluentRules`. The trait's `createDefaultValidator()` now calls `stopOnFirstFailure()` matching Laravel's base behavior.
  
- **Precognitive request support** — `HasFluentRules` now handles `isPrecognitive()` requests, filtering rules to only the submitted fields via `filterPrecognitiveRules()`. Previously, precognitive requests validated all fields regardless.
  

### Documentation

- Restructured README for newcomers: split TOC into "Getting started" / "Deep dive", collapsible rule reference, GitHub alert syntax (`[!NOTE]`, `[!WARNING]`, `[!CAUTION]`, etc.), custom anchors for deep-linking into collapsed sections.
- Added create/update branching pattern with `->when($this->isMethod('POST'), ...)`.
- Added precognitive validation mention to FormRequest section.
- Improved intro before/after comparison with real-world patterns (conditionals, wildcards, unique with ignore).

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.0.0...1.0.1

## 1.0.0 - 2026-04-06

### 1.0.0 — Stable Release

The API is stable. This release signals a commitment to semantic versioning — no breaking changes without a major version bump.

#### What's in 1.0.0

Fluent validation rule builders for Laravel with IDE autocompletion, type safety, and up to 97x faster wildcard validation.

**12 rule types:** `string`, `integer`, `numeric`, `email`, `password`, `date`, `dateTime`, `boolean`, `array`, `file`, `image`, `field`, plus `anyOf` (Laravel 13+).

**3 integration paths:**

- `HasFluentRules` trait for Form Requests — automatic compilation, wildcard optimization, per-attribute fast-checks
- `HasFluentValidation` trait for Livewire — compiles before Livewire's validator, works with `wire:model.blur`
- `FluentValidator` base class for custom Validators — full pipeline with cross-field wildcard support

**Performance:** The `HasFluentRules` trait replaces Laravel's O(n²) wildcard expansion with O(n) and applies per-attribute fast-checks that skip Laravel entirely for valid items. 25 rules are fast-checked in pure PHP. Partial fast-check handles mixed rule sets transparently.

| Scenario | Native Laravel | With HasFluentRules |
|----------|----------------|---------------------|
| 500 items, simple rules | ~200ms | **~2ms** (97x) |
| 500 items, mixed rules | ~200ms | **~20ms** (10x) |
| 100 items, 47 conditional fields | ~3,200ms | **~83ms** (39x) |

**Key features:**

- `each()` and `children()` co-locate parent and child rules
- `->label()` and `->message()` inline error customization
- `->messageFor('rule', 'msg')` position-independent messages
- Email and Password app defaults integration (`Email::default()`, `Password::default()`)
- `RuleSet` for inline validation, conditional fields, and merging
- `RuleSet::compileToArrays()` for PHPStan-clean Livewire/Filament usage
- `whenInput()` for validation-time conditions
- Macros for reusable rule chains
- Octane-safe (factory resolver restored via try/finally)
- [Laravel Boost](https://github.com/laravel/boost) skills for AI-assisted migration and development

#### Since 0.5.2

- Fixed PHPStan CI failure in `compileToArrays()` return type
- Reduced PHPStan baseline from 17 to 14 entries
- Removed `minimum-stability: dev` from composer.json
- Improved README structure for newcomers (collapsible rule reference, split TOC, Livewire section)

#### Requirements

- PHP 8.2+
- Laravel 11, 12, or 13

#### Tested across

- 6 independent production codebases
- 514 tests, 1016 assertions
- PHP 8.2, 8.3, 8.4 on Ubuntu and Windows
- Laravel 11, 12, 13 with `prefer-lowest` and `prefer-stable`

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/0.5.3...1.0.0

## 0.5.3 - 2026-04-06

### Added

- **AI-assisted development tooling** — Added [`sandermuller/package-boost`](https://github.com/SanderMuller/package-boost) for AI skills and guidelines management. Contributors get Claude Code, GitHub Copilot, and Codex context out of the box.
- **`.ai/` directory** — Source of truth for AI skills (code-review, backend-quality, bug-fixing, evaluate, write-spec, implement-spec, pr-review-feedback, autoresearch) and guidelines (verification, exploration budget, parallel agents).
- **`.mcp.json`** — Laravel Boost MCP server config for doc search and tinker via Testbench.
- **`CONTRIBUTING.md`** — Setup instructions for AI tooling with `vendor/bin/testbench`.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/0.5.2...0.5.3

## 0.5.2 - 2026-04-06

### Added

- **`RuleSet::compileToArrays()`** — Compiles FluentRule objects to native Laravel format with a guaranteed `array<string, array<mixed>>` return type. Designed for Livewire's `$this->validate()` in Filament components where `HasFluentValidation` can't be used due to trait collision with `InteractsWithSchemas`. Eliminates PHPStan baseline entries caused by `RuleSet::compile()` returning mixed types.
  
  ```php
  // Before (PHPStan complains about type mismatch)
  $this->validate(RuleSet::compile($this->rules()));
  
  // After (honest return type, zero baseline entries)
  $this->validate(RuleSet::compileToArrays($this->rules()));
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  ```

### Documentation

- Added `optimize-validation` skill tip to the migration section — Boost users can scan and convert their entire codebase automatically.
- Updated Filament troubleshooting in README and Livewire skill to recommend `compileToArrays()`.
- Added `compileToArrays()` to RuleSet API tables in README and performance reference.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/0.5.1...0.5.2

## 0.5.1 - 2026-04-06

### Added

- **`RuleSet::compileToArrays()`** — Compiles FluentRule objects to native Laravel format with a guaranteed `array<string, array<mixed>>` return type. Designed for Livewire's `$this->validate()` in Filament components where `HasFluentValidation` can't be used due to trait collision with `InteractsWithSchemas`. Eliminates PHPStan baseline entries caused by `RuleSet::compile()` returning mixed types.
  
  ```php
  // Before (PHPStan complains about type mismatch)
  $this->validate(RuleSet::compile($this->rules()));
  
  // After (honest return type, zero baseline entries)
  $this->validate(RuleSet::compileToArrays($this->rules()));
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  ```

### Documentation

- Updated Filament troubleshooting in README and Livewire skill to recommend `compileToArrays()` over `compile()`.
- Added `compileToArrays()` to RuleSet API tables in README and performance reference.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/0.5.0...0.5.1

## 0.5.0 - 2026-04-06

### Breaking

- **`FluentRule::email()` now uses `Email::default()`** when app defaults are configured via `Email::defaults()` in AppServiceProvider. Apps with strict email defaults (MX validation, spoofing prevention) will see stricter email validation. Opt out with `FluentRule::email(defaults: false)`.

### Added

- **`defaults: false` parameter** on both `FluentRule::email()` and `FluentRule::password()` for opting out of app-configured defaults:
  
  ```php
  FluentRule::email(defaults: false)    // basic 'email' validation
  FluentRule::password(defaults: false) // Password::min(8), ignores app config
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  ```
- **Boost guidelines file** — always-on agent context that ensures every agent and sub-process knows to use FluentRule native methods instead of string escape hatches. Addresses the finding that agent sub-processes don't inherit skill context.
  
- **Complete method cheatsheet** in optimize-validation skill — inline reference of all 40+ native methods to prevent agents from defaulting to `->rule()` escape hatches.
  

### Migration from 0.4.x

If `FluentRule::email()` breaks your tests after upgrading (e.g., `test@example.com` rejected due to MX checks), either:

1. Use `FluentRule::email(defaults: false)` for fields that need basic validation
2. Update test data to use domains with valid MX records

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/0.4.5...0.5.0

## 0.4.5 - 2026-04-06

### Fixed

- **Reverted `Email::default()` auto-application** — `FluentRule::email()` in 0.4.4 automatically applied app-configured email defaults (`Email::defaults()` from AppServiceProvider), which broke apps with strict email validation (MX record checks, spoofing prevention). `FluentRule::email()` now returns to basic `'email'` validation. Use `->rule(Email::default())` explicitly for app-configured email defaults.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/0.4.4...0.4.5

## 0.4.4 - 2026-04-06

### Added

- **`FluentRule::integer()`** — shorthand for `numeric()->integer()`. The most common pattern for ID fields: `FluentRule::integer()->required()->exists('users', 'id')`.
  
- **`FluentRule::email()` uses `Email::default()`** — automatically applies app-configured email defaults (from `Email::defaults()` in AppServiceProvider) when no modes are explicitly set.
  
- **`->messageFor('rule', 'msg')`** — position-independent alternative to `->message()`. Attach a custom error message by rule name instead of relying on chain position.
  
- **`->notIn()` accepts scalars** — `->notIn('admin')` instead of `->notIn(['admin'])`.
  
- **`same()`, `different()`, `confirmed()` on FieldRule** — field comparison methods were available on StringRule and NumericRule but missing from FieldRule.
  

### Improved

- **Migration patterns reference** — new reference file with before/after patterns for the most common conversion mistakes. Covers: type decisions, conditional closures, Password/Email defaults, file sizes, integer enums, exists/unique callbacks, testing patterns, and correct escape hatches. Restructured into 5 logical groups.
  
- **Skill discoverability** — updated all reference files based on feedback from 6 independent codebases (~305 files). Every discoverability miss found is now documented.
  
- **PHPStan baseline reduced to 17 entries.**
  

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/0.4.3...0.4.4

## 0.4.3 - 2026-04-06

### Fixed

- **Failed rule identifiers forwarded from self-validation** — FluentRule objects now expose individual rule identifiers (`Required`, `Min`, `Max`, etc.) in `$validator->failed()`. This fixes Livewire's `assertHasErrors(['field' => 'rule'])` when using FluentRule without the `HasFluentValidation` trait.

### Added

- **Dedicated Livewire Boost skill** — `fluent-validation-livewire` activates when working on Livewire components. Covers `HasFluentValidation` trait usage, flat wildcard key requirement, Filament trait collision workaround, and common mistakes.

### Documentation

- **Livewire support section in README** — full example with `HasFluentValidation` trait, flat wildcard key note, and `$rules` property → `rules()` method migration note
- **Filament collision workaround** — documented in README troubleshooting and Livewire skill. Use `RuleSet::compile()` when `HasFluentValidation` conflicts with `InteractsWithSchemas`.
- **`validateWithBag` pattern** — documented `RuleSet::prepare()` + `Validator::make()` for custom error bags
- **Octane safety note** — all optimizations are Octane-safe (factory resolver restored via try/finally)
- **Boost install/update commands** — clarified `boost:install` vs `boost:update`

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/0.4.2...0.4.3

## 0.4.2 - 2026-04-05

### Added

- **`confirmed()` on PasswordRule** — `FluentRule::password()->required()->confirmed()` now works without the `->rule('confirmed')` escape hatch.
  
- **`min()` on PasswordRule** — `FluentRule::password()->min(12)->max(128)` sets the minimum length via chain method. Previously only available via the constructor: `FluentRule::password(min: 12)`.
  

### Fixed

- **Boost skill no longer prompts** — The fluent-validation skill now applies rules silently when loaded, matching the convention of other Boost skills.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/0.4.1...0.4.2

## 0.4.1 - 2026-04-05

### Fixed

- **Compiled rule ordering** — Presence modifiers (`required`, `nullable`, `bail`, etc.) now come before the type constraint. `FluentRule::string()->required()` compiles to `required|string` instead of `string|required`, ensuring correct error messages ("is required" instead of "must be a string" for missing fields).

### Performance

- **Conditional pre-evaluation** — `OptimizedValidator` now pre-evaluates `exclude_unless` and `exclude_if` conditions before Laravel's validation loop, removing excluded attributes from the rule set entirely. For the hihaho-style benchmark (20 items × 45 conditional patterns): **72ms → 11ms (9.8x vs native)**.
  
- **Type-dispatch in RuleSet** — `RuleSet::validate()` pre-computes reduced rule sets per unique condition value (e.g., one rule set per interaction type). Validators are cached by rule set signature and reused across items of the same type. For the hihaho import benchmark (100 items × 47 patterns): **3200ms → 77ms (42x)**.
  
- **Fast-check after conditional evaluation** — After exclude conditions are evaluated, remaining rules are re-checked for fast-check eligibility. Rules that were previously blocked by conditional tuples can now be fast-checked.
  

### Added

- **`FluentRule::password()` uses `Password::default()`** — Now respects app-configured password defaults from `Password::defaults()` in AppServiceProvider. Pass an explicit min to override: `FluentRule::password(min: 12)`.

### Improved

- **PHPStan baseline reduced to 19 entries** — Fixed type narrowing, cast guards, return types, and property types across OptimizedValidator, RuleSet, and HasFluentRules.
  
- **Test coverage** — 496 tests, 978 assertions. Added coverage for `Password::default()`, Arrayable `in()`/`notIn()`, `distinct()` validation, conditional pre-evaluation, and type-dispatch.
  

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/0.4.0...0.4.1

## 0.4.0 - 2026-04-05

### Added

- **`HasFluentValidation` trait for Livewire components** — Overrides `validate()` and `validateOnly()` to compile FluentRule objects, extract labels/messages, and expand wildcards before Livewire's validator sees them. Uses Livewire's `getDataForValidation()` and `unwrapDataForValidation()` for correct model-bound property handling. Note: use flat wildcard keys (`items.*`) instead of `each()` for Livewire array fields.
  
- **`in()` and `notIn()` accept `Arrayable`** — Collections can now be passed directly without calling `->all()`.
  

### Fixed

- **Exists/Unique with closure-based `->where()` preserved** — Only `In` and `NotIn` objects are stringified during compilation. `Exists`, `Unique`, and `Dimensions` stay as objects to prevent closure-based `where()` constraints from being silently dropped by `__toString()`.
  
- **File sizes use decimal conversion** — `toKilobytes()` now uses 1000 (decimal) matching Laravel's `File` rule, not 1024 (binary). `'5mb'` produces 5000 KB, not 5120 KB.
  
- **Octane-safe OptimizedValidator** — Factory resolver restored via try/finally and array union replaces array_merge for performance.
  

### Improved

- **PHPStan baseline reduced to 8 entries** — Fixed `preg_match` boolean comparisons, added `is_scalar` guards for digit casts, typed test closures.

### Validated

Tested across two independent codebases:

- hihaho: 50+ files (FormRequests, custom Validators, Livewire), 481 tests
- mijntp: 31 files, confirmed API is intuitive and conversion is smooth

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/0.3.6...0.4.0

## 0.3.6 - 2026-04-05

### Fixed

- **Integer fast-check rejected valid integer strings** — `(int) $v === $v` with `strict_types` incorrectly rejects `"3"`. Replaced with `filter_var($v, FILTER_VALIDATE_INT)` to match Laravel's `validateInteger`.
  
- **Date and filled rules removed from fast-check** — `strtotime()` diverges from Laravel's `date_parse()`-based validation (accepts `"0"`, `"1"` which Laravel rejects). `filled` requires key-presence context the fast-check doesn't have. Both now fall through to Laravel.
  
- **Factory resolver always restored** — `HasFluentRules` wraps the `OptimizedValidator` factory resolver swap in try/finally, ensuring restoration even if `make()` throws.
  
- **WildcardExpander depth limit** — Added a depth limit of 50 levels to prevent stack overflow on deeply nested or circular data structures.
  

### Added

- **`distinct()` on ArrayRule** — `FluentRule::array()->each(...)->distinct()` now works without the `->rule('distinct')` escape hatch.

### Improved

- **README troubleshooting section** — 5 common issues with solutions: missing `validated()` keys, labels not working, cross-field wildcards, `mergeRecursive` breaking rules, and missing methods.
  
- **Clone pattern documented** — Full before/after example for extending parent FormRequest rules via `(clone $rule)->rule(...)`.
  
- **`HasFluentRules` framed as required** — The trait is required for correct behavior with `each()`, `children()`, labels, messages, and cross-field wildcards. No longer framed as optional.
  
- **Benchmark updated** — Standalone benchmark now tests the `HasFluentRules` + `OptimizedValidator` path instead of manually reimplementing RuleSet internals.
  

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/0.3.5...0.3.6

## 0.3.5 - 2026-04-04

### Fixed

- **Date fast-check was broken** — `Carbon::parse()` throws on invalid dates and `getTimestamp()` never returns false, so the date check always passed. Replaced with `strtotime()` which correctly returns false for invalid dates. Added `DateFuncCallToCarbonRector` to the Rector skip list to prevent CI from reverting this.
  
- **benchmark.php crash** — updated for `buildFastChecks()` new return type (tuple instead of nullable array).
  

### Improved

- **README performance section simplified** — `HasFluentRules` now provides the full optimization automatically (O(n) expansion + per-attribute fast-checks + partial fast-check). No longer framed as two tiers. Benchmark table simplified to two columns.
  
- **Boost skills updated** — performance reference and main skill reflect that `HasFluentRules` is the single recommended path for all FormRequests.
  

## 0.3.4 - 2026-04-04

### Performance

- **Partial fast-check path** — Previously, if any field in a wildcard group couldn't be fast-checked, the entire group fell back to Laravel. Now fast-checkable fields are validated with PHP closures, and only non-eligible fields go through Laravel. Items where all fast-checks pass use a separate slow-only validator for the remaining rules, avoiding redundant re-validation.
  
- **Stringified Stringable rule objects** — `In`, `NotIn`, `Exists`, `Unique`, and `Dimensions` objects are now stringified during compilation, producing pipe-joined strings that enable the fast-check path. `FluentRule::string()->in([...])` previously blocked fast-checks because the `In` object made the output an array.
  
- **Expanded fast-check coverage to 25 rules** — New rules in the fast-check path: `email`, `url`, `ip`, `uuid`, `ulid`, `alpha`, `alpha_dash`, `alpha_num`, `accepted`, `declined`, `filled`, `not_in`, `regex`, `not_regex`, `digits`, `digits_between`. Replaced `Carbon::parse()` with `strtotime()` for lighter date checks.
  

### Other

- Renamed `HihahoImportBenchTest` to `ImportBenchTest`
- Fixed misleading WildcardExpander benchmark multiplier
- Formatted benchmark output as aligned tables
- Updated release benchmark workflow to match new output format
- 5 new tests for partial fast-check path correctness

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/0.3.3...0.3.4

## 0.3.3 - 2026-04-04

### Added

- **`HasFluentRules` now includes `OptimizedValidator` support** — the trait conditionally creates an `OptimizedValidator` when fast-checkable wildcard rules are detected. Non-wildcard FormRequests still get a plain `Validator` with zero overhead. No code changes needed in existing FormRequests.
  
- **`FastCheckCompiler`** — new shared class for compiling rule strings into fast PHP closures. Used by both `RuleSet` (per-item validation) and `OptimizedValidator` (per-attribute fast-checks). Eliminates code duplication.
  

### Improved

- **PHPStan baseline reduced from 73 to 3 entries** — added proper generics, typed all test closures for 100% param/return coverage, extracted helpers to reduce cognitive complexity. Remaining 3 entries are inherent complexity in the fast-check closure builder and per-item validation loop.
  
- **`FluentFormRequest` simplified** — now just `class FluentFormRequest extends FormRequest { use HasFluentRules; }`. All logic lives in the trait.
  
- **`RuleSet::validate()` refactored** — split from one 130-line method into 5 focused helpers: `separateRules()`, `validateWildcardGroups()`, `validateItems()`, `passesAllFastChecks()`, `throwValidationErrors()`.
  
- **`SelfValidates::validate()` refactored** — extracted `buildRulesForAttribute()`, `buildMessages()`, `buildAttributes()`, `forwardErrors()`. Eliminates 9 complexity baseline entries.
  
- **`buildCompiledRules()` ordering improved** — presence modifiers (`ExcludeIf`, `RequiredIf`, etc.) run first, then string constraints, then other object rules (closures, custom rules). Previously all objects ran before strings, which could produce misleading errors when `bail` was present.
  
- **`accepted`/`declined` fast-check fix** — these rules now correctly bail to Laravel instead of being silently ignored in the fast path.
  

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/0.3.2...0.3.3

## 0.3.2 - 2026-04-04

### Fixed

- **ArrayRule `compiledRules()` now includes `array` type** — `FluentRule::array()->nullable()` previously compiled to `'nullable'` instead of `'array|nullable'`, causing `validated()` to omit nested child keys when using `children()` or `each()` nesting.
  
- **Clone support for FormRequest inheritance** — Cloning a FluentRule after `compiledRules()` was called no longer inherits a stale cache. Enables the pattern:
  
  ```php
  $rules[self::TYPE] = (clone $rules[self::TYPE])->rule($extraClosure);
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  ```
- **PHPStan errors in OptimizedValidator** — Matched parent `Validator::validateAttribute()` signature.
  

### Improved

- **PHPStan baseline reduced from 73 to 5 entries** — Added proper generics (`Fluent<string, mixed>`), typed error iteration loops, return type annotations, and extracted `SelfValidates::validate()` into focused helper methods. Remaining 5 entries are inherent complexity in `OptimizedValidator` and type coverage gaps from Laravel's untyped `Validator` parent.
  
- **`HasFluentRules` now includes `OptimizedValidator` support** — The trait conditionally creates an `OptimizedValidator` when fast-checkable wildcard rules are detected. Non-wildcard FormRequests still get a plain `Validator` with zero overhead. `FluentFormRequest` is now a convenience base class equivalent to `FormRequest` + `HasFluentRules`.
  

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/0.3.1...0.3.2

## 0.3.1 - 2026-04-04

### Fixed

- **ArrayRule `compiledRules()` now includes `array` type** —
  `FluentRule::array()->nullable()` previously compiled to `'nullable'` instead of
  `'array|nullable'`, causing `validated()` to omit nested child keys. This fixes the
  `children()` and `each()` nesting issue where `validated()` output was missing expected
  keys.
  
- **Clone support for FormRequest inheritance** — Cloning a FluentRule after
  `compiledRules()` was called no longer inherits a stale cache. `(clone $parentRule)->rule($extraClosure)` now works correctly, enabling the pattern for child
  FormRequests that extend parent rules.
  
- **PHPStan errors in OptimizedValidator** — Matched parent
  `Validator::validateAttribute()` signature.
  

### Improved

- **PHPStan baseline reduced from 73 to 14 entries** — Added proper generics
  (`Fluent<string, mixed>`), typed error iteration loops, and return type annotations across
  shared traits.
  
- **Callback support for `exists()` and `unique()`** — Both accept an optional `?Closure $callback` parameter for `->where()`, `->whereNull()`, and `->ignore()` chaining:
  
  ```php
  FluentRule::string()->unique('users', 'email', fn ($r) => $r->ignore($this->user()->id))
  FluentRule::string()->exists('subjects', 'id', fn ($r) => $r->where('active', true))    
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  ```
- FluentFormRequest base class — Combines HasFluentRules compilation with per-attribute
  fast-check optimization via OptimizedValidator. Eligible wildcard rules are fast-checked
  with pure PHP; ineligible rules fall through to Laravel.
  
- Release benchmark workflow — Runs benchmarks on each release and appends results to the
  release description.
  

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/0.3.0...0.3.1

## 0.3.0 - 2026-04-04

### Added

- **`FluentFormRequest`** — new base class that combines `HasFluentRules` compilation with
  per-attribute fast-check optimization. Extend it instead of `FormRequest` to
  automatically skip Laravel's validation for valid wildcard items via pure PHP checks.
  Eligible rules are fast-checked; ineligible rules (object rules, date comparisons,
  cross-field references) fall through to Laravel transparently.
  
- **`OptimizedValidator`** — new `Validator` subclass that overrides `validateAttribute()`
  with a per-attribute fast-check cache. Valid attributes skip all remaining rule
  evaluations. Created automatically by `FluentFormRequest`.
  
- **Callback support for `exists()` and `unique()`** — both now accept an optional
  `?Closure $callback` parameter (3rd argument), matching the existing `enum()` pattern.
  Enables `->where()`, `->whereNull()`, and `->ignore()` chaining:
  
  ```php
  FluentRule::string()->unique('users', 'email', fn($r) => $r->ignore($this->user()->id))
  FluentRule::string()->exists('subjects', 'id', fn($r) => $r->where('video_id',          
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  ```

$videoId))

- Release benchmark workflow — automatically runs benchmarks on each release and appends
  results to the release description for performance tracking across versions.

Fixed

- compiledRules() now delegates to buildValidationRules() and only joins to a
  pipe-separated string when all rules are strings, improving consistency with the
  validation pipeline.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/0.2.4...0.3.0

## 0.2.4 - 2026-04-04

### Fixed

- Object rules (`ExcludeIf`, `RequiredIf`, `ProhibitedIf`, etc.) now come before string constraints in compiled output, matching Laravel's expected ordering
- Closure-based conditional rules are no longer eagerly stringified during compilation

## 0.2.3 - 2026-04-03

### Fixed

- Reverted `FluentValidator` and `HasFluentRules` to the safe `prepare()` path for cross-field wildcard compatibility
- Removed `OptimizedValidator` (per-item optimization is now only available via `RuleSet::validate()`)

## 0.2.0 - 2026-04-03

### Added

- `HasFluentRules` trait for FormRequest integration (automatic compile, expand, and metadata extraction)
- `FluentValidator` base class for custom Validator subclasses
- Per-item validation optimization in `RuleSet::validate()` (up to 77x faster for large wildcard arrays)

## 0.1.3 - 2026-04-03

### Added

- `RuleSet::prepare()` single-call pipeline returning `PreparedRules` DTO (expand, extract metadata, compile)
- `optimize-validation` Boost skill for scanning existing validation and suggesting improvements

## 0.1.2 - 2026-04-03

### Fixed

- Bool serialization in conditional rule values (`false` no longer becomes empty string)

## 0.1.1 - 2026-04-03

### Fixed

- Laravel 11 CI compatibility
- Security improvement for conditional rule handling

### Changed

- Updated README with usage examples

## 0.1.0 - 2026-04-03

### Added

- `FluentRule` factory with type-safe builders: `string`, `numeric`, `date`, `dateTime`, `boolean`, `array`, `email`, `file`, `image`, `password`, `field`, `anyOf`
- `RuleSet` builder with `from()`, `field()`, `merge()`, `when()`/`unless()`, `expandWildcards()`, `validate()`
- `WildcardExpander` with O(n) direct tree traversal (replaces Laravel's O(n^2) approach)
- Inline labels via constructor parameter (e.g. `FluentRule::string('Full Name')`)
- `message()` and `fieldMessage()` for per-rule custom error messages
- `each()` for wildcard child rules and `children()` for fixed-key child rules on `ArrayRule`
- `whenInput()` for data-dependent conditional rules
- Conditional modifiers: `requiredIf`, `requiredUnless`, `excludeIf`, `excludeUnless`, `prohibitedIf`, `prohibitedUnless`
- `in()` and `notIn()` accept BackedEnum class names for enum-based validation
- Field modifiers: `required`, `nullable`, `sometimes`, `present`, `filled`, `bail`, `exclude`
- `Macroable` support on all rule types
- `Conditionable` support on all rule types and `RuleSet`
- `fluent-validation` Boost skill with full API reference
- Laravel 11, 12, and 13 support
- 5000+ lines of test coverage
