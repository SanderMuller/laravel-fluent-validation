# Changelog

All notable changes to `laravel-fluent-validation` will be documented in this file.

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
