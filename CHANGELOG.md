# Changelog

All notable changes to `laravel-fluent-validation` will be documented in this file.

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
