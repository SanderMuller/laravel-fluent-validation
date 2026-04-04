# Changelog

All notable changes to `laravel-fluent-validation` will be documented in this file.

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
