# Changelog

All notable changes to `laravel-fluent-validation` will be documented in this file.

## 1.28.0 - 2026-06-13

<!-- verified-sha: 17cf97636660f8a6abc65b8ec1d73a53226cc29e -->
Drops Laravel 11 from the supported matrix and moves the package's AI-authoring dev tooling onto the boost `1.x` line. No runtime code or public API changed — the only consumer-facing effect is the narrowed framework constraint.

### What changed

#### Laravel 11 support dropped

Every Laravel 11 release (`v11.0.0` through `v11.54.0`) is now flagged by Packagist security advisories, and there is no advisory-free patch in the `11.x` line. Composer's advisory policy refuses to install any of them, so the package could no longer be resolved or tested against Laravel 11.

The supported framework constraint narrows accordingly:

```
illuminate/*: ^11.0||^12.0||^13.0  ->  ^12.0||^13.0

```
The CI matrix drops its Laravel 11 legs (and the `orchestra/testbench ^9.0` requirement that only existed to test them); Laravel 12 and 13 remain, across PHP 8.2 / 8.3 / 8.4 on Ubuntu and Windows.

#### AI-authoring tooling moved to boost 1.x

The package's `require-dev` boost stack moved to the `1.0` line — `sandermuller/package-boost-laravel ^1.0` (pulling `boost-core 1.x` + `package-boost-php 1.x`) and `sandermuller/boost-skills ^2.5`. The `.config/boost.php` config was updated to the array-argument builder API that boost-core `0.20+` requires. These are developer-only dependencies; they are not installed by consumers and do not affect the package at runtime.

### Compatibility

- PHP 8.2 / 8.3 / 8.4
- Laravel 12 / 13 (prefer-lowest + prefer-stable)
- ubuntu-latest + windows-latest

No runtime code touched. No public-API change. No new runtime dependencies.

### Upgrading

Drop-in for anyone already on Laravel 12 or 13: `composer update sandermuller/laravel-fluent-validation`.

Projects still on Laravel 11 must upgrade to Laravel 12+ to take this release. Laravel 11 is past security support and its releases are advisory-blocked; staying on it is not a secure option regardless of this package.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.27.3...1.28.0

## 1.27.3 - 2026-06-02

<!-- verified-sha: db314676416304edcbd9e569d05108f884db5c26 -->
Patch fix: the always-on FluentRule guideline never reached consumers' AI tooling because the wrong file extension made boost-core skip it.

### What changed

#### Core guideline renamed `core.blade.php` → `core.md`

The package ships an always-on guideline (`FluentRule Validation`) that boost-core renders into a consumer's `CLAUDE.md` / `AGENTS.md` when they allowlist this package. It carried the standing guidance — FormRequests must use `HasFluentRules`, the `FluentRule::` type list, "don't wrap conditional modifiers in `Rule::`".

The file was plain markdown — zero Blade directives, zero render-time tokens — but its `.blade.php` extension made boost-core route it through a renderer that no normal consumer registers. Every consumer's `boost sync` skipped it:

```
⚠ guideline `core.blade.php` skipped — no renderer registered for its extension.


```
So the standing guidance never landed in `CLAUDE.md` / `AGENTS.md`; contributors only got it on-demand via the `fluent-validation*` skills, not as always-on context.

Fix: renamed the file to `core.md`. boost-core renders `.md` guidelines natively, so the body now reaches every allowlisting consumer with no `withSkillRenderers()` registration required. No content change.

Surfaced during a downstream consumer audit of synced AI assets.

### Upgrading

Drop-in. `composer update sandermuller/laravel-fluent-validation`, then re-run `vendor/bin/boost sync`; the guideline now renders without the skip warning.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.27.2...1.27.3

## 1.27.2 - 2026-06-02

<!-- verified-sha: d17504911517456ec7f42b00f637bd93bba20cdc -->
Patch fix: conditional-required and presence modifiers combined with `nullable()` were silently dropped when a `FluentRule` self-validates.

### What changed

#### `nullable()` no longer drops conditional-required / presence modifiers in self-validation

When a `FluentRule` is used as a standalone `ValidationRule` — inline `$request->validate([...])` or `Validator::make()` without the `HasFluentRules` trait — it self-validates through `SelfValidates`. `isNullable()` skipped validation for a null or absent value whenever `nullable` was present and the literal `required` string was absent from the rule's constraints.

Conditional-required and presence modifiers never emit that literal `required` string — they compile to `required_*` constraint strings, or to `RequiredIf` / `RequiredUnless` objects when built from a closure or bool. So the guard silently dropped the requirement on a null or missing value:

```php
// $enabled = true; the field is omitted or null
FluentRule::email()->requiredIf($enabled)->nullable()
// before: passed — requirement dropped          ❌
// after:  fails, matching native Laravel         ✅



```
This diverged from both native Laravel (`['nullable', Rule::requiredIf(true)]`) and the compiled `HasFluentRules` path, which were already correct — so the gap only surfaced when a `FluentRule` validated in isolation.

Fix: `isNullable()` now short-circuits only when the chain carries no presence-forcing modifier. The presence-forcing set is:

- **required** — `required`, the string conditionals (`requiredIf` / `requiredUnless` / `requiredWith` / `requiredWithout` and their `_all` / `_if_accepted` / `_if_declined` variants), and the `RequiredIf` / `RequiredUnless` objects.
- **filled**
- **present** — `present` / `presentIf` / `presentUnless` / `presentWith` / `presentWithAll`
- **missing** — `missing` / `missingIf` / `missingUnless` / `missingWith` / `missingWithAll`

Detection matches exact rule names, so `required_array_keys` (an array-content rule, not a presence requirement) is correctly excluded. `prohibited*` and `exclude*` remain short-circuitable — they are satisfied by a null/empty value, which already matched native Laravel.

A second, related gap is closed in the same path: a `nullable` array no longer short-circuits when it carries nested `each()` / `children()` rules. A required child under a null parent is now enforced like native Laravel (and the compiled path) instead of skipped. Wildcard `each()` still passes on a null parent — it expands to nothing — while fixed `children()` enforce their sub-rules.

Pinned by `tests/RequiredConditionalNullableTest.php`: a parity matrix that asserts every conditional-required and presence family — with `nullable` on and off, across absent / null / empty-string / valid values, plus nested parent-null shapes — behaves identically on the self-validation path, the compiled `HasFluentRules` path, and native Laravel.

No public-API change. No new dependencies. Same compatibility matrix as 1.27.1.

Reported via #15.

### Upgrading

Drop-in. `composer update sandermuller/laravel-fluent-validation`.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.27.1...1.27.2

## 1.27.1 - 2026-05-11

### 1.27.1

Patch fix for a silent gap in the parent-`max:N` short-circuit on batched-database validation.

#### What changed

##### `BatchLimitRemap` now populates `Validator::failed()` with the tripped rule

When `each()` carries a batched `exists`/`unique` rule **and** the parent array declares `max:N`, the package short-circuits before any DB query fires (`HasFluentRules::assertParentArraysWithinMax`). The short-circuit throws `BatchLimitExceededException`, and `BatchLimitRemap::toValidationException()` remaps that to `Illuminate\Validation\ValidationException` so callers see the package's standard exception type.

The remap built a synthetic `Validator::make([], [])`, pushed the error message into the bag, but never populated `failedRules`. Net effect:

```php
$caught->validator->errors()->keys();   // ['actions']                      ✅
$caught->validator->errors()->first();  // human-readable message          ✅
$caught->validator->failed();           // []                              ❌




```
`Validator::failed()` returning `[]` meant `FluentRulesTester::failsWith('actions', 'max')` — and any other assertion path that inspects the rule-bag rather than the message bag — couldn't see which rule actually tripped.

The load-bearing detail: the parent-max guard fires **before** the validator is built, so this path never goes through Laravel's normal `Max` rule. Reproducers that drive `Validator::make` directly against the same `FluentRule` chain hit Laravel's own `Max`, which populates `failedRules` correctly — masking the gap for narrow tests.

Fix: the remap now reflects `failedRules` onto the synthetic validator:

- `REASON_PARENT_MAX` → `[$attribute => ['Max' => [(string) $limit]]]`
- `REASON_HARD_CAP` → `[$attribute => ['BatchLimit' => []]]`

Pinned by:

- `tests/BatchValidationGuardsTest.php` — asserts the post-remap `failed()` shape directly
- `tests/Testing/FluentRulesTesterClassTargetsTest.php` + `tests/Fixtures/BailMaxEachFluentFormRequest.php` — guards the `FluentRulesTester::failsWith(..., 'max')` consumer surface

No public-API change. No new dependencies. Same compatibility matrix as 1.27.0.

#### Compatibility

Same matrix as 1.27.0:

- PHP 8.2 / 8.3 / 8.4
- Laravel 11 / 12 / 13 (prefer-lowest + prefer-stable)
- ubuntu-latest + windows-latest

#### Upgrading

Drop-in. `composer update sandermuller/laravel-fluent-validation`.

## 1.27.0 - 2026-05-11

### What changed

#### `dropUnknownFields()` — lenient counterpart to `failOnUnknownFields()`

`RuleSet::failOnUnknownFields()` rejects unknown input keys with a validation error. `dropUnknownFields()` does the opposite: it strips them silently.

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
Top-level keys outside the rule set are already excluded from `validated()`; this flag extends the same behavior to nested array shapes declared via `children()`, `each()`, or dotted rule keys. Maps to Laravel's `Validator::$excludeUnvalidatedArrayKeys`, but gives per-`RuleSet` control instead of relying on whatever the host factory's flag happens to be set to — useful when an application has called `Factory::includeUnvalidatedArrayKeys()` globally and a specific call site needs the strict default back.

If both `dropUnknownFields()` and `failOnUnknownFields()` are set on the same rule set, `failOnUnknownFields()` wins — unknown keys trigger a validation error before the drop ever applies. Order is deterministic and pinned by `tests/RuleSetTest.php`.

Wildcard rule sets (anything built with `each()`) take a different path internally: the per-item validators don't see siblings, so they can't strip cross-item unknown keys. When `dropUnknownFields()` is combined with `each()`, the rule set falls back to a single fully-expanded validator so the flag applies uniformly. The fallback is correct but bypasses the batched-database verifier and per-item fast-check optimizations — fine for typical FormRequest payloads, worth knowing if you're stripping unknowns on five-figure-row imports.

#### `validate(Request)` / `check(Request)`

`RuleSet::validate()` and `RuleSet::check()` now accept `array|Illuminate\Http\Request`:

```php
// Before
$validated = RuleSet::from([...])->validate($request->all());

// 1.27
$validated = RuleSet::from([...])->validate($request);





```
When a `Request` is passed, the package calls `$request->all()` once inside a private normalizer at the library boundary, then runs the rest of the pipeline against the array. Functionally identical to the explicit `$request->all()` form — but the unsafe-input read happens inside the package, not in your controller.

The motivation is downstream static-analysis rules that forbid `$request->all()` / `$request->input()` outside `FormRequest` classes — they trip on the explicit form even when the very next call validates the data. With the overload, ad-hoc controller validations stay clean for those rules without forcing a `FormRequest` extraction. For real form requests (auth-gated endpoints, reusable rule sets, anything non-trivial), keep using `HasFluentRules` — the overload is for one-shot ad-hoc validations only.

`check()` accepts the same overload for the errors-as-data path.

#### Drive-by: `stopOnFirstFailure` now propagates through `validateStandard()`

Pre-existing gap. `RuleSet::validateStandard()` — the fully-expanded fallback path — built its validator without applying `->stopOnFirstFailure($this->stopOnFirstFailure)`. The non-wildcard branch and the wildcard top-validator both did; only the fallback didn't.

The bug surfaces any time `validateStandard()` is reached: rules with `distinct`, and — new in 1.27 — rules with `dropUnknownFields()` combined with `each()`. Fix is a single chain call; pinned by `tests/RuleSetTest.php`.

#### `illuminate/http` now declared in `require`

The package references `Illuminate\Http\UploadedFile` from `PresenceConditionalReducer` and (new in 1.27) `Illuminate\Http\Request` from `RuleSet`. Previously the `composer.json` `require` block only listed `illuminate/contracts`, `illuminate/support`, and `illuminate/validation` — none of which transitively guarantee `illuminate/http`. In practice every install ended up with it via `laravel/framework`, but a downstream consumer using only the standalone illuminate components would have hit an undeclared-dependency runtime fail.

`illuminate/http` is now in `require` with the same `^11.0||^12.0||^13.0` constraint as the other illuminate packages. No version change for anyone whose lockfile already pulled it in transitively.

### Upgrading

If your application has called `$factory->includeUnvalidatedArrayKeys()` globally and you previously worked around it with explicit `$validator->excludeUnvalidatedArrayKeys = true;` after `Validator::make`, you can now express that directly on the rule set with `->dropUnknownFields()`.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.26.0...1.27.0

## 1.26.0 - 2026-05-10

### What changed

#### Compile-cache for `FastCheckCompiler::compile()`

Repeated FormRequest validations recompile the same pipe-delimited rule strings into the same closures. 1.26 memoizes the result by rule string — first call builds the closure, subsequent calls hit a process-local map.

**Login form benchmark: ~7x → ~12x speedup vs native Laravel.** Wildcard-heavy scenarios already routed through `RuleCacheKey` upstream and don't move on the bench, but the flat-rule FormRequest path (login forms, settings forms, simple CRUD) doubles its relative speedup.

The cache is bounded and Octane-aware:

- **Soft cap of 1024 entries** — apps that build rule strings from runtime values (per-tenant `in:` lists, generated regexes) can't grow the cache without bound on long-lived workers. Above the cap the cache resets; correctness is preserved, only the warm hit-rate momentarily drops.
- **Date-comparison rules are NOT cached.** `CoreValueCompiler::parseDateLiteral` calls `strtotime()` at compile time and bakes the timestamp into the closure. Caching `after:today` / `before:now` / `after_or_equal:tomorrow` / `before_or_equal:+1 week` / `date_equals:today` would freeze the relative timestamp for the lifetime of the Octane worker and silently drift validation after midnight. These rules recompile per-call by design — cheap and correct.

Pinned by `tests/FastCheckCompilerCacheTest.php` (7 cases) covering both the stable-rule reuse contract and the no-reuse contract for the five date-comparison opcodes.

#### Pre-extracted conditional metadata in `OptimizedValidator::passes()`

Phase 2 used to call `evaluateConditionals()` on every attribute in the rules map — scanning each rule list for `exclude_unless` / `exclude_if` tuples even when the attribute had none. For FormRequests with sparse conditionals over many attributes that scan was the dominant per-call cost.

1.26 replaces it with a single up-front pass (`indexConditionalAttrs()`) that builds a flat `[attribute => list<{action, field, values}>]` map, then iterates only attributes that actually have conditionals. The post-eval fast-check branch now runs against pre-parsed tuples instead of re-scanning the rule list.

**FormRequest with conditional rules (100 items × 10 fields, 6 conditional): median 13–16ms → ~10ms (~30% faster).** The benchmark harness scenarios use `RuleSet::validate()` (which routes through `ItemValidator`, not `OptimizedValidator`) so they don't reflect the win — but FormRequest paths in real apps see it directly.

`passes()` itself was decomposed into four helpers (`runFastCheckPhase`, `runConditionalPhase`, `tryFastCheckRemaining`, `finalizeWithoutParent`) to keep cognitive complexity under threshold during the rewrite.

#### Dead-work removal in the fast-check hot path

Three small cleanups in the per-item validation loop. None move the benchmark needle individually (variance dominates), but they match existing intent and avoid pointless work on every call:

- `CoreValueCompiler`: replaced `in_array($value, [null, '', []], true)` with an explicit `===` chain. The adjacent comment already advertised this — the code just hadn't been updated.
- `ItemValidator`: dropped `array_values()` before passing the fast-check map to `passesAllFastChecks`. The collector iterates values via `foreach`, so the rekey was free busywork on every item.
- `ItemErrorCollector`: widened the param type to `iterable` to match.

### What's Changed

* chore(deps-dev): update sandermuller/package-boost requirement from ^0.9.0 to ^0.12.0 by @dependabot[bot] in https://github.com/SanderMuller/laravel-fluent-validation/pull/14

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.25.0...1.26.0

## 1.25.0 - 2026-04-29

### Added

- `FluentRule::integer(strict: true)` — compiles to `numeric|integer:strict`. Strict-mode rejection only honored on Laravel 12.23+; runtime no-op on older versions.
- `FluentRule::date(message:)` and `FluentRule::dateTime(message:)`. Pinned message migrates from `date` → `date_format` automatically when `->format(...)` is called (explicit `messageFor('date_format', ...)` wins).

### Fixed

- Fast-check no longer skipped Laravel for numeric strings under `integer:strict`. `CoreValueCompiler` and `ValueTypePredicates::predicateFor()` now branch on the `integer.strict` flag and emit `is_int($v)` instead of `filter_var(..., FILTER_VALIDATE_INT)`.

### Docs

- README rewrite: lead with FormRequest + `HasFluentRules`, expanded comparison table to all 25 `Rule::` static methods, rule reference rewritten one-concept-per-line, labels CAUTION callout, troubleshooting tightened, Rector/PHPStan companion sections trimmed.
- `migration-patterns.md` adds `integer:strict` row.

### Contributors

- @raphaelstolt: dist export-ignores (#13).

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.24.0...1.25.0

## 1.24.0 - 2026-04-24

### Added

- `RuleSet::modifyEach(string $field, array $rules)` — sugar over `ArrayRule::mergeEachRules()`.
- `RuleSet::modifyChildren(string $field, array $rules)` — sugar over `FieldRule::mergeChildRules()` (`FieldRule`-only).
- `ArrayRule::getEachKeyedRules(): ?array<string, ValidationRule>`.
- `ArrayRule::getEachListRule(): ?ValidationRule`.

### Deprecated

- `ArrayRule::getEachRules()` — use the two narrow getters. Return type narrows to `?array<string, ValidationRule>` in 1.25.0.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.23.0...1.24.0

## 1.23.0 - 2026-04-23

### Added

- Per-item pre-evaluation for `required_if`, `required_unless`, `prohibited_if`, `prohibited_unless` via new `ValueConditionalReducer`. Wildcard items rewrite to bare `required`/`prohibited` (fast-checkable) or drop. Custom `{field}.{rule}` messages and `validation.custom.*` overrides preserved.
- `ArrayRule::addEachRule($key, $rule)` and `mergeEachRules($rules)`.
- `FieldRule::addChildRule($key, $rule)` and `mergeChildRules($rules)`.
- `CannotExtendListShapedEach` exception when calling these on list-form `each(VR)`.
- `RuleSet::isEmpty()` and `RuleSet::hasObjectRules()`.

### Changed

- `add*Rule` collisions throw `LogicException`; empty keys throw `InvalidArgumentException`. Use `merge*Rules` for later-wins replacement.
- `ArrayRule` storage refactored — `eachRules` always `?array<string, ValidationRule>`; separate `?ValidationRule $eachListRule` slot. `getEachRules()` return shape unchanged for BC.
- `array_merge` inside loops replaced with collect-then-merge in `BatchDatabaseChecker::queryValues()`, `ArrayRule::buildEachNestedRules`/`buildChildNestedRules`, `FieldRule::buildNestedRules`.

### Fixed

- Per-item validator cache collision when a reducer produced different effective rule strings for items with the same field set. Cache key now routes through `RuleCacheKey::for()` (string content verbatim, stable fingerprint for objects/scalars/arrays).
- `compileFluentRules()` honors an explicitly empty `$rules = []` instead of falling back to `rules()`.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.22.0...1.23.0

## 1.22.0 - 2026-04-23

### Added

- Three batched-DB validation guards (filter → dedup → cap → query):
  
  1. Per-item type pre-filter — `integer`/`numeric`/`uuid`/`ulid`/`string` rules drop values pre-query.
  2. Parent `max:N` short-circuit — over-limit input fails on parent attribute, zero queries.
  3. Hard cap per `(table, column, rule-type)` group via `BatchDatabaseChecker::$maxValuesPerGroup` (default `10_000`).
  
- `BatchLimitExceededException` (`REASON_PARENT_MAX`, `REASON_HARD_CAP`).
  
- Static helper `BatchDatabaseChecker::filterValuesByType(array $values, array|string $itemRules): array`.
  

### Fixed

- Latent `exists` + `unique` conflation when the same `(table, column)` carried both — `registerLookups` now refuses to batch and falls back to the default `DatabasePresenceVerifier`.

### Behavioural change

- Documented entry points (`HasFluentRules`, `RuleSet::validate()`, `RuleSet::check()`) still throw `ValidationException`. Direct `$ruleSet->prepare()` consumers may observe raw `BatchLimitExceededException`.

### Known scope

- Only `max:N` inspected on the parent (not `size`/`between`/outer-ancestors). Numerically-indexed wildcards only. `failedValidation()` does not fire on parent-max / hard-cap paths.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.21.0...1.22.0

## 1.21.0 - 2026-04-22

### Changed

- `ArrayRule::contains()` / `doesntContain()` route through `Rules\Contains` / `Rules\DoesntContain` on Laravel 12+, with CSV-quoting and enum resolution.
- Signature widened: accepts single array, `Arrayable`, `BackedEnum` (uses `value`), `UnitEnum` (uses `name`), plus existing scalar varargs.
- `->message()` / `messageFor()` now bind to `'contains'` / `'doesnt_contain'` keys.

### Fixed

- Silent data corruption — values containing commas or quotes were split by Laravel's `str_getcsv` parser. L11 now uses CSV-escaped pipe-string fallback; L12+ uses object form.

### Added

- `InvalidArgumentException` on multi-array varargs (`->contains(['a'], ['b'])`).
- `RuntimeException` on `doesntContain()` under Laravel 11.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.20.0...1.21.0

## 1.20.0 - 2026-04-22

### Added

- Inline `message:` named arg on every non-variadic rule method and every factory with a stable error-lookup key.
- Factories that seed `$lastConstraint` so `->message()` works without `messageFor`: `string`, `numeric`, `boolean`, `accepted`, `declined`, `file`, `image`, `array`, `email`.
- Factory `message:` support: `string`, `numeric`, `integer`, `boolean`, `array`, `file`, `image`, `accepted`, `declined`, `email`, `url`, `uuid`, `ulid`, `ip`, `ipv4`, `ipv6`, `macAddress`, `json`, `timezone`, `hexColor`, `activeUrl`, `regex`, `list`.
- `migrate-messages-array` Boost skill — rewrites `messages(): array` overrides into inline `message:` form, with three rewrite tiers (portable / via-`messageFor` / unportable).

### Notes

- Composite methods (`digits`, `digitsBetween`, `exactly`, date `between`, image `width`/`minWidth`/`ratio`) bind `message:` to the last sub-rule; target earlier ones via `messageFor`.
- Not accepted on `message:`: variadic-trailing methods, mode modifiers (`rfcCompliant`/`strict`/Password mode toggles), `FluentRule::date()`/`dateTime()`/`password()` factories, `FluentRule::field()`/`anyOf()`.

### Backwards compatibility

- All new params are optional trailing `?string $message = null`. `->message()` and `->messageFor()` remain first-class; neither deprecated.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.19.0...1.20.0

## 1.19.0 - 2026-04-22

### Added

- Top-level shorthand factories: `FluentRule::ipv4()`, `ipv6()`, `macAddress()`, `json()`, `timezone()`, `hexColor()`, `activeUrl()`, `regex(pattern)`, `list()`, `enum(class)`. Each accepts an optional `?string $label`.
- `FluentRule::declined()` — symmetric sibling of `accepted()`. `->declinedIf(...)` replaces base `declined`.
- `NumericRule` sign helpers: `positive()` (`gt:0`), `negative()` (`lt:0`), `nonNegative()` (`gte:0`), `nonPositive()` (`lte:0`).

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.18.0...1.19.0

## 1.18.0 - 2026-04-21

### Added

- `FieldRule::__call`/`__callStatic` override — type-specific methods on `FluentRule::field()` now throw `UnknownFluentRuleMethod` (extends `BadMethodCallException`) naming the correct typed builder. Hint table reflection-derived from typed builder public methods.
- `BansFieldRuleTypeMethods` arch helper at `Testing/Arch/` for Pest/PHPUnit. Walks paths with `nikic/php-parser` (`^5.0`, `suggest`-only).

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.17.1...1.18.0

## 1.17.1 - 2026-04-20

### Changed

- `FluentRulesTester` promoted to `@api` — public methods locked under semver.
- `FastCheckCompiler` split into per-family compilers under `src/FastCheck/` (`CoreValueCompiler`, `ItemContextCompiler`, `PresenceConditionalCompiler`, `ProhibitedCompiler`, plus utilities). Public API unchanged.
- Per-item validation loop extracted from `RuleSet` into `src/Internal/` (`ItemRuleCompiler`, `ItemErrorCollector`, `ItemValidator`).

### Added

- `prohibited|sometimes` and `prohibited|bail` (and orderings) now fast-check.
- Nightly CI leg against `laravel/framework:dev-master` + `orchestra/testbench:dev-master` on PHP 8.4.

### Fixed

- Defensive guards in `FastCheckCompiler::sizePair`, `BatchDatabaseChecker::uniqueStringValues`, `PrecomputedPresenceVerifier::flip` — silent skip on malformed input rather than `TypeError`.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.17.0...1.17.1

## 1.17.0 - 2026-04-20

### Added

- `SanderMuller\FluentValidation\Contracts\FluentRuleContract` (extends `Illuminate\Contracts\Validation\ValidationRule`) implemented by all 11 rule classes (`AcceptedRule`, `ArrayRule`, `BooleanRule`, `DateRule`, `EmailRule`, `FieldRule`, `FileRule`, `ImageRule`, `NumericRule`, `PasswordRule`, `StringRule`). Carries the universally-shared modifier/conditional/metadata/`SelfValidates`/`Conditionable` surface. Type-specific methods stay on concrete classes.

### Docs

- README "Using with `validateWithBag`" updated to the 1.16.0 `RuleSet::from(...)->withBag(...)->validate(...)` chain.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.16.0...1.17.0

## 1.16.0 - 2026-04-20

### Added

- `FluentRule::field()->prohibited()` is fast-checkable when alone (optionally with `nullable`/`sometimes`). Combinations stay on slow path.
- `RuleSet::withBag(string $name)` — mirrors `Validator::validateWithBag`. Sets the thrown `ValidationException::$errorBag`.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.15.1...1.16.0

## 1.15.1 - 2026-04-20

### Changed

- PHPStan baseline reduced from 114 entries / 343 lines to 14 entries / 85 lines.
- `PresenceConditionalReducer` extracted from `RuleSet` (`@internal`, `final`, static-only).

### Fixed

- `FastCheckCompiler::sizePair` — defensive type guards before `count`/`mb_strlen`/numeric ops.
- `BatchDatabaseChecker::uniqueStringValues` — coerce unknown shapes to empty string instead of `strval` `TypeError`.
- `PrecomputedPresenceVerifier::flip` — scalar/Stringable guard; skip unknown shapes.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.15.0...1.15.1

## 1.15.0 - 2026-04-19

### Added

- Per-item pre-evaluation for `required_with`, `required_without`, `required_with_all`, `required_without_all` inside wildcard groups. Reducer rewrites active to `required`, drops inactive, preserves rules with custom messages on the original rule name.
- Detection of message overrides via `{field}.{rule}` map keys (bare/wildcard-prefixed/parent-prefixed) and `validation.custom.*` translator entries (including `Str::is`-matched wildcard keys).

### Fixed

- Parameter parsing for presence conditionals now uses `str_getcsv` to match Laravel's `ValidationRuleParser::parseParameters` exactly.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.14.0...1.15.0

## 1.14.0 - 2026-04-19

### Added

- `FluentRule::accepted()` factory — permissive opt-in (`true`/`1`/`'1'`/`'yes'`/`'on'`/`'true'`) without conflicting `boolean` base. `->acceptedIf(...)` replaces the unconditional base.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.13.2...1.14.0

## 1.13.2 - 2026-04-19

### Fixed

- `FluentRulesTester::actingAs()` was a no-op on Livewire class-string targets. Lifted user-binding into shared `applyActingAs()` helper.
- Test-only `app.key` set in Testbench env — Livewire test renders need it.

### Docs

- README split Livewire test shapes into "component-flow dispatch" (class-string + `set`/`call`) vs "rules-only shape" (`rules()` array + `with`).

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.13.1...1.13.2

## 1.13.1 - 2026-04-19

### Fixed

- 1.13.0's `composer.json` shipped with a broken `repositories` entry.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.13.0...1.13.1

## 1.13.0 - 2026-04-19

### Added

- Livewire component target — `FluentRulesTester::for(Component::class)` routes through `Livewire::test()`. Supports `set($key, $value)` / `set([...])`, `call($action, ...$args)`, `andCall(...)` (queued append-order). Captures both `validate()` and `addError(...)` errors. Per-dispatch state consumption.
- `FluentRulesTester::failsWithAny($prefix)` — inclusive prefix match (exact OR `$prefix.*`).
- `FluentRulesTester::failsOnly($field, $rule = null)` — exactly-one matching error key.
- `FluentRulesTester::doesNotFailOn(...$fields)`.
- `RuleSet::modify($field, fn ($rule))` — clones stored rule before callback. Throws `LogicException` on missing key.

### Notes

- `livewire/livewire` is a soft dev dep; `class_exists` guard avoids hard fatal in PHPUnit-only suites.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.12.3...1.13.0

## 1.12.3 - 2026-04-19

### Added

- `RuleSet implements IteratorAggregate` — spread (`[...$ruleSet]`) works without `->toArray()`.
- `RuleSet::all()` — alias of `toArray()`.
- `HasFluentRules` and `HasFluentValidation` auto-unwrap `RuleSet` from `rules()`.

### Docs

- `FluentRule::rule()` docblock clarifies it mutates the receiver.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.12.2...1.12.3

## 1.12.2 - 2026-04-18

### Added

- `FluentRulesTester::withRoute(array $params)` — bind route parameters for `$this->route(name)` lookups in `authorize()`/`rules()`.
- `FluentRulesTester::actingAs($user, $guard = null)`.
- `RuleSet::only()` / `except()` accept array form in addition to variadic.

### Docs

- `failsWith()` docblock notes `FluentRule::integer()` compiles to `numeric|integer` and fails as `Numeric` on non-numeric input.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.12.1...1.12.2

## 1.12.1 - 2026-04-18

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.12.0...1.12.1

## 1.12.0 - 2026-04-18

### Added

- `FluentRulesTester` — testing surface for FluentRule chains, RuleSets, FormRequest class-strings, and FluentValidator class-strings. `with(array)` required before assertions.
  
  - Assertions: `passes()`, `fails()`, `failsWith($field [, $rule])`, `failsWithMessage($field, $key, $replacements = [])`, `assertUnauthorized()`, `errors()`, `validated()`.
  - FormRequest path uses `createFrom()` + `validateResolved()`; records `ValidationException` / `AuthorizationException` instead of rethrowing.
  
- Optional Pest expectations at `src/Testing/PestExpectations.php`: `toPassWith`, `toFailOn`, `toBeFluentRuleOf`. `class_exists`-guarded on `Pest\Expectation`.
  
- `RuleSet::only(...$fields)`, `except(...$fields)`, `put($field, $rule)`, `get($field, $default = null)`.
  

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.11.0...1.12.0

## 1.11.0 - 2026-04-17

### Added

- Fast-check for presence-conditional rules `required_with`, `required_without`, `required_with_all`, `required_without_all` (multi-param). Composes with item-aware date / `same` / `different` / `confirmed` / `gt`/`gte`/`lt`/`lte` rules from 1.10.0.
- `FastCheckCompiler::compileWithPresenceConditionals(string $ruleString): ?\Closure`. Picked up automatically by `RuleSet::buildFastChecks` after `compile()` and `compileWithItemContext()`.
- `isLaravelEmpty()` helper centralizing presence semantics (null / `trim() === ''` / empty array / empty `Countable`).

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.10.0...1.11.0

## 1.10.0 - 2026-04-17

### Added

- Item-aware fast-check for cross-field rules: date field-refs (`after`/`before`/`after_or_equal`/`before_or_equal`/`date_equals`), `same`/`different` (single-param), `confirmed`/`confirmed:custom`, sized comparisons with type flag (`numeric|gt:`, `string|gt:`, `array|gte:`, `integer|lte:`, etc.).
- `FastCheckCompiler::compileWithItemContext(string $ruleString, ?string $attributeName = null): ?\Closure`. Used as fallback by `RuleSet::buildFastChecks`.

### Notes

- Slow-path remains for: untyped `gt`/`gte`/`lt`/`lte`, `date_format:X` + date field-ref combos, multi-param `different:a,b,c`, custom Rule objects/closures, `distinct`, `exists`/`unique` with closure callbacks.
- Rector `RepeatedOrEqualToInArrayRector` skipped on `src/FastCheckCompiler.php`.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.9.2...1.10.0

## 1.9.2 - 2026-04-17

### Added

- Fast-check date field references — `after:FIELD`, `after_or_equal:FIELD`, `before:FIELD`, `before_or_equal:FIELD`, `date_equals:FIELD` resolved in closure at call time.
- `FastCheckCompiler::compileWithItemContext(string $ruleString): ?\Closure`.

### Notes

- Pre-filter limits re-parse to rules containing `after:`/`before:`/`date_equals:` to avoid noise on unrelated rules.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.9.1...1.9.2

## 1.9.0 - 2026-04-15

### Added

- `RuleSet::check()` returns immutable `Validated` result. Methods: `passes()`, `fails()`, `errors()`, `firstError($field)`, `validated()`, `safe()`, `validator()`.
- Fast-check closures now apply to flat top-level rules (not just wildcards).

### Fixed

- FastCheckCompiler rewrite for Laravel parity (75 drifts fixed): null no longer short-circuits to pass; `nullable` only bypasses null when no implicit rule fires; `'' + non-implicit` passes (`presentOrRuleIsImplicit`); `required` fails on empty arrays; `array|min`/`max` use `count()`; `alpha`/`alpha_dash`/`alpha_num` accept int/float, reject bool/null/array; `regex`/`not_regex` require `is_string || is_numeric`; `in`/`not_in` reject non-scalars; dotted rule keys fall through to Laravel in non-wildcard path.

### Changed

- `filled` and `sometimes` route through Laravel (presence tracking unavailable in closure).
- `min`/`max` without type flag (`string`/`array`/`numeric`/`integer`) are non-fast-checkable.

### Breaking

- Code that relied on previous lenient null behavior may now fail. Add `nullable` to rules that should accept null.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.8.2...1.9.0

## 1.8.2 - 2026-04-15

### Fixed

- Added `@return array<string, array<mixed>>` PHPDoc to `RuleSet::compileToArrays()`.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.8.1...1.8.2

## 1.8.1 - 2026-04-15

### Changed

- `HasFluentValidationForFilament` now uses standard `validate()`/`validateOnly()` method names. Consumers add an `insteadof` block for `validate`, `validateOnly`, `getRules`, `getValidationAttributes`.
- `getRules()` merges FluentRule-compiled rules with Filament's form-schema rules (previously dropped schema rules). `getValidationAttributes()` merges labels too.
- `validate()`/`validateOnly()` preserve Filament's `form-validation-error` event dispatch and `onValidationError()` hook.

### Notes

- Works with Filament v3, v4 (target `InteractsWithForms`), v5 (target `InteractsWithSchemas`).

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.8.0...1.8.1

## 1.8.0 - 2026-04-15

### Added

- `HasFluentValidationForFilament` trait — for Livewire components using Filament's `InteractsWithForms`/`InteractsWithSchemas`. Exposes `validateFluent()` (compiles FluentRule, extracts labels/messages, delegates to Filament's `validate()`).
- `RuleSet::compileWithMetadata()` — returns `[rules, messages, attributes]` matching `validate()` parameter order.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.7.1...1.8.0

## 1.7.1 - 2026-04-14

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.7.0...1.7.1

## 1.7.0 - 2026-04-14

### Added

- Full Livewire support in `HasFluentValidation` — overrides `getRules()`, `getMessages()`, `getValidationAttributes()` in addition to `validate()`/`validateOnly()`.
- `each()` and `children()` work in Livewire — flattened to wildcard/fixed-path keys before Livewire reads them.
- Labels (`->label()`) and messages (`->message()`) auto-extracted for Livewire validation.
- `$rules` property support (in addition to `rules()` method).
- BackedEnum values accepted directly by `excludeIf`, `excludeUnless`, `requiredIf`, `requiredUnless`, `prohibitedIf`, `prohibitedUnless`, `presentIf`, `presentUnless`, `missingIf`, `missingUnless` — auto-serialized to backing value.
- `RuleSet::flattenRules()` public method for wildcard-preserving expansion.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.6.0...1.7.0

## 1.6.0 - 2026-04-12

### Added

- Fast-check date rules — `date`, `date_format`, `after`, `before`, `after_or_equal`, `before_or_equal`, `date_equals` with literal dates fast-checked via `strtotime()`. Field references fall through.
- Fast-check `array` and `filled`.
- Nested wildcard fast-checks — patterns like `options.*.label` expanded within per-item closure.
- `FluentRules` marker attribute — for migration tooling detection. No runtime effect.

### Improved

- `OptimizedValidator` pre-groups attributes by wildcard pattern; uses `Arr::dot()` for O(1) flat lookups.
- `BatchDatabaseChecker` — `uniqueStringValues()` uses `SORT_STRING`.
- `PrecomputedPresenceVerifier` — string-cast flip maps + `isset()` for O(1) lookups; fixes int/string type mismatch.
- `RuleSet` — `$flatRules` threaded through `prepare`/`expand`/`separateRules`.

### Companion package

- [`sandermuller/laravel-fluent-validation-rector`](https://github.com/sandermuller/laravel-fluent-validation-rector) — 6 Rector rules to migrate native Laravel validation to FluentRule.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.5.0...1.6.0

## 1.5.0 - 2026-04-12

(Same content as 1.6.0 — see above.)

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.4.1...1.5.0

## 1.4.1 - 2026-04-10

### Fixed

- PHP 8.2 compat — removed typed constant (`private const int`) from `BatchDatabaseChecker` (PHP 8.3+ syntax).
- Excluded `src/Rector` from PHPStan; removed stale baseline entries.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.4.0...1.4.1

## 1.4.0 - 2026-04-10

### Added

- Batched database validation for wildcard arrays — `exists`/`unique` on wildcard fields run a single `whereIn` instead of one query per item. Works in `RuleSet::validate()` and `HasFluentRules`. Scalar `where()` clauses batched too; closure callbacks fall through.
- Classes: `BatchDatabaseChecker`, `PrecomputedPresenceVerifier`.

### Notes

- Original `Exists`/`Unique` rule objects retained, so custom messages, attributes, `ignore()`, `validated()` work unchanged.
- Safety guards: closure callbacks not batched; rules without explicit column not batched; custom presence verifiers skip batching; non-wildcard never batched; falls back to original verifier for any non-precomputed rule.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.3.0...1.4.0

## 1.3.0 - 2026-04-10

### Added

- `RuleSet::failOnUnknownFields()` — unknown keys emit a `prohibited` error. Mirrors Laravel 13.4's `FormRequest::failOnUnknownFields`.
- `RuleSet::stopOnFirstFailure()` — works across top-level fields, wildcard groups, and per-item.

### Docs

- `messageFor()` promoted to primary recommendation. README labels note links all four extraction-supporting paths.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.2.0...1.3.0

## 1.2.0 - 2026-04-10

### Added

- `FluentRule::macro()` — register custom factory methods.
- `RuleSet` is now `Macroable`.

### Improved

- `HasFluentValidation` — explicit `mixed` types for PHP 8.5; private narrowing helpers (`toNullableArray`, `toStringMap`); `compileFluentRules()` is `protected`.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.1.0...1.2.0

## 1.1.0 - 2026-04-08

### Added

- Complete Laravel 13 rule coverage — every native validation rule has a fluent method.
- Field modifiers: `presentIf`, `presentUnless`, `presentWith`, `presentWithAll`, `requiredIfAccepted`, `requiredIfDeclined`, `prohibitedIfAccepted`, `prohibitedIfDeclined`.
- Array methods: `contains(...$values)`, `doesntContain(...$values)`.
- String method: `encoding($encoding)`.
- Factory shortcuts: `FluentRule::url()`, `uuid()`, `ulid()`, `ip()`.
- Debugging: `->toArray()`, `->dump()`, `->dd()` on rules; `RuleSet::from(...)->dump()`/`->dd()`.

### Fixed

- `presentIf`/`presentUnless`/`presentWith`/`presentWithAll` now correctly trigger validation for absent fields in self-validation.
- `FluentRule::field()->toArray()` returns `[]` instead of `['']`.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.0.1...1.1.0

## 1.0.1 - 2026-04-07

### Fixed

- `$stopOnFirstFailure = true` on a FormRequest using `HasFluentRules` is now honored.
- Precognitive request support — `HasFluentRules` filters rules to submitted fields via `filterPrecognitiveRules()`.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/1.0.0...1.0.1

## 1.0.0 - 2026-04-06

Stable release. API locked under semver.

### Surface

- 12 rule types: `string`, `integer`, `numeric`, `email`, `password`, `date`, `dateTime`, `boolean`, `array`, `file`, `image`, `field`, plus `anyOf` (Laravel 13+).
- 3 integration paths: `HasFluentRules` (FormRequest), `HasFluentValidation` (Livewire), `FluentValidator` (custom).
- 25 rules fast-checked in pure PHP. Partial fast-check for mixed rule sets.
- `each()` / `children()`, `->label()`, `->message()`, `->messageFor()`, `Email::default()` / `Password::default()` integration, `RuleSet`, `RuleSet::compileToArrays()`, `whenInput()`, macros, Octane-safe.
- Laravel Boost skills.

### Requirements

- PHP 8.2+, Laravel 11/12/13.

### Since 0.5.2

- Fixed PHPStan CI failure in `compileToArrays()` return type.
- Reduced PHPStan baseline 17 → 14.
- Removed `minimum-stability: dev`.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/0.5.3...1.0.0

## 0.5.3 - 2026-04-06

### Added

- `sandermuller/package-boost` for AI skills/guidelines management.
- `.ai/` directory with code-review, backend-quality, bug-fixing, evaluate, write-spec, implement-spec, pr-review-feedback, autoresearch skills.
- `.mcp.json` — Laravel Boost MCP config.
- `CONTRIBUTING.md`.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/0.5.2...0.5.3

## 0.5.2 - 2026-04-06

### Added

- `RuleSet::compileToArrays()` — returns `array<string, array<mixed>>`. For Livewire's `$this->validate()` in Filament components where `HasFluentValidation` collides with `InteractsWithSchemas`.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/0.5.1...0.5.2

## 0.5.1 - 2026-04-06

### Added

- `RuleSet::compileToArrays()` (see 0.5.2).

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/0.5.0...0.5.1

## 0.5.0 - 2026-04-06

### Breaking

- `FluentRule::email()` uses `Email::default()` when configured via `Email::defaults()`. Opt out with `FluentRule::email(defaults: false)`.

### Added

- `defaults: false` parameter on `FluentRule::email()` and `FluentRule::password()`.
- Boost guidelines file — always-on agent context.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/0.4.5...0.5.0

## 0.4.5 - 2026-04-06

### Fixed

- Reverted `Email::default()` auto-application from 0.4.4. `FluentRule::email()` returns to basic `'email'`. Use `->rule(Email::default())` explicitly.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/0.4.4...0.4.5

## 0.4.4 - 2026-04-06

### Added

- `FluentRule::integer()` — shorthand for `numeric()->integer()`.
- `FluentRule::email()` uses `Email::default()` when configured. (Reverted in 0.4.5.)
- `->messageFor('rule', 'msg')` — position-independent message attachment.
- `->notIn()` accepts scalars.
- `same()`, `different()`, `confirmed()` on `FieldRule`.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/0.4.3...0.4.4

## 0.4.3 - 2026-04-06

### Fixed

- Failed-rule identifiers (`Required`, `Min`, `Max`, ...) exposed in `$validator->failed()` from self-validation. Fixes Livewire `assertHasErrors(['field' => 'rule'])` without the `HasFluentValidation` trait.

### Added

- `fluent-validation-livewire` Boost skill.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/0.4.2...0.4.3

## 0.4.2 - 2026-04-05

### Added

- `confirmed()` on `PasswordRule`.
- `min()` on `PasswordRule` (chain method, in addition to constructor `password(min: ...)`).

### Fixed

- Boost skill no longer prompts.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/0.4.1...0.4.2

## 0.4.1 - 2026-04-05

### Fixed

- Compiled rule ordering — presence modifiers (`required`, `nullable`, `bail`) come before type constraint. `FluentRule::string()->required()` compiles to `required|string`.

### Added

- `OptimizedValidator` pre-evaluates `exclude_unless` / `exclude_if` before validation loop.
- `RuleSet::validate()` pre-computes reduced rule sets per unique condition value, caches validators by signature.
- Re-check fast-check eligibility after exclude evaluation.
- `FluentRule::password()` uses `Password::default()` when configured. Override via `password(min: 12)`.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/0.4.0...0.4.1

## 0.4.0 - 2026-04-05

### Added

- `HasFluentValidation` trait for Livewire — overrides `validate()`/`validateOnly()`, compiles FluentRule, extracts labels/messages, expands wildcards. Uses Livewire's `getDataForValidation()` / `unwrapDataForValidation()`. Note: use flat wildcard keys for Livewire array fields, not `each()`.
- `in()` and `notIn()` accept `Arrayable`.

### Fixed

- `Exists`, `Unique`, `Dimensions` stay as objects (only `In`/`NotIn` stringified) to preserve closure-based `where()` constraints.
- `toKilobytes()` uses 1000 (decimal) matching Laravel's `File` rule. `'5mb'` → 5000 KB.
- Octane-safe `OptimizedValidator` — factory resolver restored via try/finally.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/0.3.6...0.4.0

## 0.3.6 - 2026-04-05

### Fixed

- Integer fast-check — replaced `(int) $v === $v` with `filter_var($v, FILTER_VALIDATE_INT)` to match `validateInteger`.
- Removed `date` and `filled` from fast-check (`strtotime` diverges from Laravel's `date_parse`; `filled` needs key-presence context).
- `HasFluentRules` factory resolver swap wrapped in try/finally.
- `WildcardExpander` depth limit of 50 levels.

### Added

- `distinct()` on `ArrayRule`.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/0.3.5...0.3.6

## 0.3.5 - 2026-04-04

### Fixed

- Date fast-check — replaced `Carbon::parse()` (throws on invalid; `getTimestamp()` never returns false) with `strtotime()`. Added `DateFuncCallToCarbonRector` to skip list.
- `benchmark.php` updated for `buildFastChecks()` tuple return.

## 0.3.4 - 2026-04-04

### Added

- Partial fast-check path — fast-checkable fields validated with PHP closures even when other fields in the wildcard group fall back to Laravel.
- Stringified `In`, `NotIn`, `Exists`, `Unique`, `Dimensions` during compilation, enabling fast-check.
- Fast-check coverage expanded to 25 rules: `email`, `url`, `ip`, `uuid`, `ulid`, `alpha`, `alpha_dash`, `alpha_num`, `accepted`, `declined`, `filled`, `not_in`, `regex`, `not_regex`, `digits`, `digits_between`. (Note: some reverted in later releases — see 0.3.6, 1.9.0.)

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/0.3.3...0.3.4

## 0.3.3 - 2026-04-04

### Added

- `HasFluentRules` conditionally creates `OptimizedValidator` when fast-checkable wildcard rules are detected.
- `FastCheckCompiler` — shared rule-string-to-closure compiler used by `RuleSet` and `OptimizedValidator`.

### Changed

- `FluentFormRequest` reduced to `class FluentFormRequest extends FormRequest { use HasFluentRules; }`.
- `RuleSet::validate()` split into `separateRules`, `validateWildcardGroups`, `validateItems`, `passesAllFastChecks`, `throwValidationErrors`.
- `SelfValidates::validate()` split into `buildRulesForAttribute`, `buildMessages`, `buildAttributes`, `forwardErrors`.
- `buildCompiledRules()` ordering — presence modifiers, then strings, then other object rules.

### Fixed

- `accepted`/`declined` correctly bail to Laravel instead of being silently ignored.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/0.3.2...0.3.3

## 0.3.2 - 2026-04-04

### Fixed

- `ArrayRule::compiledRules()` now includes `array` type — `FluentRule::array()->nullable()` compiles to `array|nullable` (was `nullable`).
- Cloning a `FluentRule` after `compiledRules()` no longer inherits stale cache. Enables `(clone $rules[self::TYPE])->rule($extraClosure)`.
- `OptimizedValidator::validateAttribute()` signature matches parent.

### Changed

- `HasFluentRules` conditionally uses `OptimizedValidator` when fast-checkable wildcard rules present.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/0.3.1...0.3.2

## 0.3.1 - 2026-04-04

### Fixed

- `ArrayRule::compiledRules()` includes `array` type (see 0.3.2).
- Clone support for FormRequest inheritance.
- `OptimizedValidator::validateAttribute()` signature.

### Added

- Callback support for `exists()` and `unique()` — optional `?Closure $callback` 3rd arg for `->where()`/`->whereNull()`/`->ignore()`.
- `FluentFormRequest` base class.
- Release benchmark workflow.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/0.3.0...0.3.1

## 0.3.0 - 2026-04-04

### Added

- `FluentFormRequest` — `FormRequest` + `HasFluentRules` + per-attribute fast-check via `OptimizedValidator`.
- `OptimizedValidator` — `Validator` subclass overriding `validateAttribute()` with per-attribute fast-check cache.
- Callback support for `exists()` and `unique()` (optional `?Closure $callback` 3rd arg).
- Release benchmark workflow.

### Fixed

- `compiledRules()` delegates to `buildValidationRules()`; only joins to pipe-string when all rules are strings.

**Full Changelog**: https://github.com/SanderMuller/laravel-fluent-validation/compare/0.2.4...0.3.0

## 0.2.4 - 2026-04-04

### Fixed

- Object rules (`ExcludeIf`, `RequiredIf`, `ProhibitedIf`) come before string constraints in compiled output.
- Closure-based conditional rules no longer eagerly stringified during compilation.

## 0.2.3 - 2026-04-03

### Fixed

- `FluentValidator` and `HasFluentRules` reverted to safe `prepare()` path for cross-field wildcard compatibility.
- Removed `OptimizedValidator` (per-item optimization remains in `RuleSet::validate()`).

## 0.2.0 - 2026-04-03

### Added

- `HasFluentRules` trait for FormRequest integration.
- `FluentValidator` base class.
- Per-item validation optimization in `RuleSet::validate()`.

## 0.1.3 - 2026-04-03

### Added

- `RuleSet::prepare()` — single-call pipeline returning `PreparedRules` DTO.
- `optimize-validation` Boost skill.

## 0.1.2 - 2026-04-03

### Fixed

- Bool serialization in conditional rule values (`false` no longer becomes empty string).

## 0.1.1 - 2026-04-03

### Fixed

- Laravel 11 CI compat.
- Security improvement for conditional rule handling.

## 0.1.0 - 2026-04-03

### Added

- `FluentRule` factory: `string`, `numeric`, `date`, `dateTime`, `boolean`, `array`, `email`, `file`, `image`, `password`, `field`, `anyOf`.
- `RuleSet` builder: `from()`, `field()`, `merge()`, `when()`/`unless()`, `expandWildcards()`, `validate()`.
- `WildcardExpander` (O(n) tree traversal).
- Inline labels via constructor (`FluentRule::string('Full Name')`).
- `message()` and `fieldMessage()` for per-rule custom error messages.
- `each()` (wildcard child rules) and `children()` (fixed-key child rules) on `ArrayRule`.
- `whenInput()`.
- Conditional modifiers: `requiredIf`, `requiredUnless`, `excludeIf`, `excludeUnless`, `prohibitedIf`, `prohibitedUnless`.
- `in()` and `notIn()` accept BackedEnum class names.
- Field modifiers: `required`, `nullable`, `sometimes`, `present`, `filled`, `bail`, `exclude`.
- `Macroable` and `Conditionable` support.
- `fluent-validation` Boost skill.
