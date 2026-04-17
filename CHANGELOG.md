# Changelog

All notable changes to `laravel-fluent-validation` will be documented in this file.

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
