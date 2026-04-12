# Extract Rector Rules to a Separate Package

## Overview

Move the Rector migration rules (currently under `src/Rector/`) out of the `sandermuller/laravel-fluent-validation` package into a new sibling package `sandermuller/laravel-fluent-validation-rector`. Users who want runtime FluentRule support install the main package; users who want the one-time migration tool install the Rector package as a `require-dev`. This keeps the main package's production dependencies small (no `rector/rector` needed) while letting the migration tool evolve independently.

Reference: `/Users/sandermuller/Documents/GitHub/rector-rules` (the `hihaho/rector-rules` package) provides a working template for this exact structure — a dedicated `type: rector-extension` package with set lists, consumable via a single set import. We'll mirror its layout and composer conventions.

---

## 1. Current State

### Files to extract

```
src/Rector/
├── AddHasFluentRulesTraitRector.php
├── AddHasFluentValidationTraitRector.php
├── Concerns/ConvertsValidationRules.php
├── GroupWildcardRulesToEachRector.php
├── SimplifyFluentRuleRector.php
├── ValidationArrayToFluentRuleRector.php
└── ValidationStringToFluentRuleRector.php

tests-rector/
├── AddHasFluentRulesTraitRectorTest.php
├── AddHasFluentValidationTraitRectorTest.php
├── GroupWildcardRulesToEachRectorTest.php
├── SimplifyFluentRuleRectorTest.php
├── ValidationArrayToFluentRuleRectorTest.php
├── ValidationStringToFluentRuleRectorTest.php
├── Fixture/              ← string rule fixtures (7 files)
├── FixtureArray/         ← array rule fixtures (24 files)
├── FixtureGrouping/      ← grouping rule fixtures (16 files)
├── FixtureLivewire/      ← Livewire trait fixtures (3 files)
├── FixtureSimplify/      ← simplify rule fixtures (7 files)
├── FixtureTrait/         ← trait rule fixtures (5 files)
└── config/               ← Rector test configs (6 files)
```

**Total:** 6 test classes, 62 fixture files, 6 config files — 61 tests, 68 assertions.

### Current cross-package dependencies

Each Rector rule references symbols from the main package:

| Symbol | Used by |
|--------|---------|
| `SanderMuller\FluentValidation\FluentRule` (class) | All 6 rules — used as the target class in generated code via `FullyQualified(FluentRule::class)` |
| `SanderMuller\FluentValidation\HasFluentRules` (trait) | `AddHasFluentRulesTraitRector` — added to target classes |
| `SanderMuller\FluentValidation\HasFluentValidation` (trait) | `AddHasFluentValidationTraitRector`, `AddHasFluentRulesTraitRector` (Livewire guard) |
| `SanderMuller\FluentValidation\Rector\Concerns\ConvertsValidationRules` (trait) | `ValidationStringToFluentRuleRector`, `ValidationArrayToFluentRuleRector` |
| `SanderMuller\FluentValidation\Tests\Rector\*Test` | `@see` class references in rule classes for test discoverability |

The rules **reference** these symbols but don't **import** or **use** the runtime code. Class-string references like `FluentRule::class` resolve to a string at compile time; no class loading happens during Rector execution.

### Cross-process state in ConvertsValidationRules trait

The `ConvertsValidationRules` trait includes a three-layer detection system for skipping parent classes whose subclasses manipulate `parent::rules()` with array functions. This is important for extraction because:

1. **Layer 1 (same-file AST scan)** — no external dependencies, fully self-contained
2. **Layer 2 (file-based IPC)** — writes to `sys_get_temp_dir() . '/rector-fluent-unsafe-parents-' . hash('xxh128', getcwd()) . '.txt'` for cross-process parallel worker communication
3. **Layer 3 (filesystem scan)** — uses `RecursiveCallbackFilterIterator` to scan the project root (found via `composer.json` traversal), excluding `vendor/`, `node_modules/`, and hidden directories

These mechanisms work independently and require no package-level state. The temp file is scoped to `getcwd()` which is the consumer's project root. No changes needed for extraction.

### Current test infrastructure

- Tests live under `tests-rector/` (separate from the main `tests/` dir)
- Test namespace: `SanderMuller\FluentValidation\Tests\Rector\*`
- Test configs under `tests-rector/config/` use per-rule configurations
- Fixtures use `.php.inc` format (Rector convention)
- Tests extend `Rector\Testing\PHPUnit\AbstractRectorTestCase` from `rector/rector`

### Current `require-dev` that only Rector tests need

From `composer.json`:
- `rector/rector` — runtime dependency for the Rector rules themselves
- `rector/type-perfect` — helper rules
- `driftingly/rector-laravel` — helper rules
- `mrpunyapal/rector-pest` — helper rules

These aren't needed by the main package's validation runtime or tests.

## 2. Target State

### New package: `sandermuller/laravel-fluent-validation-rector`

Layout mirrors `hihaho/rector-rules`:

```
laravel-fluent-validation-rector/
├── composer.json                      ← type: rector-extension
├── README.md
├── LICENSE
├── phpstan.neon.dist
├── phpstan-baseline.neon
├── pint.json                          ← copy from main package
├── rector.php                         ← for self-analysis
├── config/
│   ├── config.php                     ← entry point (optional global services)
│   └── sets/
│       ├── all.php                    ← imports every set
│       ├── convert.php                ← ValidationString/Array rules
│       ├── group.php                  ← GroupWildcardRulesToEachRector
│       ├── traits.php                 ← AddHasFluentRules/Validation traits
│       └── simplify.php               ← SimplifyFluentRuleRector
├── src/
│   ├── Rector/
│   │   ├── AddHasFluentRulesTraitRector.php
│   │   ├── AddHasFluentValidationTraitRector.php
│   │   ├── Concerns/
│   │   │   └── ConvertsValidationRules.php
│   │   ├── GroupWildcardRulesToEachRector.php
│   │   ├── SimplifyFluentRuleRector.php
│   │   ├── ValidationArrayToFluentRuleRector.php
│   │   └── ValidationStringToFluentRuleRector.php
│   └── Set/
│       └── FluentValidationSetList.php
└── tests/
    ├── Pest.php
    ├── Rector/
    │   ├── AddHasFluentRulesTraitRectorTest.php
    │   ├── Fixture/
    │   └── … (all current tests-rector/ files, relocated)
    └── config/                        ← per-rule test configs
```

### New package `composer.json` (matches hihaho/rector-rules conventions)

```json
{
    "name": "sandermuller/laravel-fluent-validation-rector",
    "description": "Rector rules for migrating Laravel validation to sandermuller/laravel-fluent-validation",
    "homepage": "https://github.com/sandermuller/laravel-fluent-validation-rector",
    "type": "rector-extension",
    "license": "MIT",
    "keywords": ["laravel", "validation", "fluent", "rector", "migration"],
    "require": {
        "php": "^8.2",
        "rector/rector": "^2.4.1",
        "sandermuller/laravel-fluent-validation": "^1.0",
        "symplify/rule-doc-generator-contracts": "^11.2"
    },
    "require-dev": {
        "laravel/pint": "^1.29",
        "nikic/php-parser": "^5.4",
        "orchestra/testbench": "^9.0||^10.11",
        "pestphp/pest": "^3.0||^4.4",
        "phpstan/phpstan": "^2.0",
        "rector/type-perfect": "^2.0",
        "sandermuller/package-boost": "^0.2",
        "tomasvotruba/cognitive-complexity": "^1.0",
        "tomasvotruba/type-coverage": "^2.0"
    },
    "autoload": {
        "psr-4": {
            "SanderMuller\\FluentValidationRector\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "SanderMuller\\FluentValidationRector\\Tests\\": "tests/"
        }
    },
    "extra": {
        "rector": {
            "includes": ["config/config.php"]
        }
    },
    "config": {
        "allow-plugins": {
            "pestphp/pest-plugin": true,
            "phpstan/extension-installer": true,
            "rector/extension-installer": true
        },
        "sort-packages": true
    }
}
```

Key conventions adopted from `hihaho/rector-rules`:
- `type: "rector-extension"` — signals Rector's extension installer to auto-register
- `extra.rector.includes` — Rector auto-imports `config/config.php`
- `rector/extension-installer` as an allowed plugin — auto-loads the package's config
- Set list class under `src/Set/` (not `src/RectorSetList.php`) — matches the reference layout

### Namespace and layout

The package root namespace is `SanderMuller\FluentValidationRector`. The rule classes keep the `Rector\` sub-namespace, matching the reference (`Hihaho\RectorRules\Rector\*`) and the filesystem layout (`src/Rector/`).

| Old (in main package) | New (standalone package) |
|------------------------|--------------------------|
| `SanderMuller\FluentValidation\Rector\ValidationStringToFluentRuleRector` | `SanderMuller\FluentValidationRector\Rector\ValidationStringToFluentRuleRector` |
| `SanderMuller\FluentValidation\Rector\Concerns\ConvertsValidationRules` | `SanderMuller\FluentValidationRector\Rector\Concerns\ConvertsValidationRules` |
| `SanderMuller\FluentValidation\Tests\Rector\*Test` | `SanderMuller\FluentValidationRector\Tests\Rector\*Test` |

The `Set/` namespace lives at `SanderMuller\FluentValidationRector\Set\FluentValidationSetList` (no `Rector\` prefix — it's the public API for set consumption, matching `Hihaho\RectorRules\Set\HihahoSetList`).

### Main package changes

After extraction:
- Remove `src/Rector/` directory
- Remove `tests-rector/` directory
- Remove `rector.php` references to local Rector rules (if any — for self-linting)
- Remove Rector-only `require-dev` dependencies:
  - `rector/type-perfect` — only used by the custom Rector rules
- **Keep** `rector/rector`, `driftingly/rector-laravel`, `mrpunyapal/rector-pest` — the main package's `rector.php` uses vendor sets (Laravel, Pest, code quality) for its own QA. These are not extraction targets.
- **Keep** the `rector` script and `@rector` step in `composer.json` — the main package still runs Rector for code quality
- Update README — full audit of all Rector class references (see Phase 5 for details)

## 3. Consumer Impact

### Before

```bash
composer require sandermuller/laravel-fluent-validation   # includes runtime + Rector rules
```

```php
// rector.php
use SanderMuller\FluentValidation\Rector\ValidationStringToFluentRuleRector;
```

### After

```bash
composer require sandermuller/laravel-fluent-validation                       # runtime only
composer require --dev sandermuller/laravel-fluent-validation-rector          # migration tool
```

```php
// rector.php
use SanderMuller\FluentValidationRector\Rector\ValidationStringToFluentRuleRector;
use SanderMuller\FluentValidationRector\Set\FluentValidationSetList;
```

This only affects `rector.php` configs (dev tooling), not application code. The main package's runtime API (FluentRule, HasFluentRules, etc.) is unchanged.

## 4. Release Strategy

**Minor release** — the Rector rules are migration tooling, not runtime API. No application code breaks when they're removed; only `rector.php` configs need updating. A major version bump would falsely signal "your app will break."

The main package ships the extraction as a **minor release** (e.g., 1.5.0) with prominent release notes. Users migrate by:

1. `composer require --dev sandermuller/laravel-fluent-validation-rector`
2. Updating imports: find/replace `SanderMuller\FluentValidation\Rector\` → `SanderMuller\FluentValidationRector\Rector\` in their `rector.php`
3. Optionally switching to the set list API (`FluentValidationSetList::ALL`)

Release notes must call out the removed classes and link to the new package.

## 5. Set list convenience

Mirrors `Hihaho\RectorRules\Set\HihahoSetList`:

```php
// src/Set/FluentValidationSetList.php
namespace SanderMuller\FluentValidationRector\Set;

final class FluentValidationSetList
{
    private const string SETS_DIR = __DIR__ . '/../../config/sets/';

    public const string ALL = self::SETS_DIR . 'all.php';

    public const string CONVERT = self::SETS_DIR . 'convert.php';

    public const string GROUP = self::SETS_DIR . 'group.php';

    public const string TRAITS = self::SETS_DIR . 'traits.php';

    public const string SIMPLIFY = self::SETS_DIR . 'simplify.php';
}
```

Each set in `config/sets/*.php` registers its rules:

```php
// config/sets/convert.php
use Rector\Config\RectorConfig;
use SanderMuller\FluentValidationRector\Rector\ValidationStringToFluentRuleRector;
use SanderMuller\FluentValidationRector\Rector\ValidationArrayToFluentRuleRector;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->rule(ValidationStringToFluentRuleRector::class);
    $rectorConfig->rule(ValidationArrayToFluentRuleRector::class);
};

// config/sets/all.php
return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->import(__DIR__ . '/convert.php');
    $rectorConfig->import(__DIR__ . '/group.php');
    $rectorConfig->import(__DIR__ . '/traits.php');
};
```

**Note:** `simplify.php` is intentionally NOT included in `all.php`. The simplify rules are post-migration cleanup that should be run separately after verifying the conversion is correct. Users opt in explicitly via `FluentValidationSetList::SIMPLIFY`.

Consumers:

```php
// rector.php
use SanderMuller\FluentValidationRector\Set\FluentValidationSetList;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/app'])
    ->withSets([FluentValidationSetList::ALL]);

// Or just the conversion rules:
->withSets([FluentValidationSetList::CONVERT])

// Or mix granular sets:
->withSets([
    FluentValidationSetList::CONVERT,
    FluentValidationSetList::TRAITS,
])

// Post-migration simplification (run separately):
->withSets([FluentValidationSetList::SIMPLIFY])
```

Granular sets (matching hihaho/rector-rules' routing/naming/migrations split) let users opt in to specific transformations. Resolves Open Question 3.

## 6. Tooling & CI

- New repo should reuse the main package's `pint.json`, `phpstan.neon.dist` structure, `.github/workflows/*.yml` CI templates
- `phpstan-baseline.neon` starts empty; regenerate once all code is moved
- CI matrix: PHP 8.2/8.3/8.4, Rector 2.x
- GitHub Actions workflow should test against the latest stable release of the main package AND the dev-main branch (to catch breaking changes early)

## 7. Notable Implementation Details

### BackedEnum detection in ValidationArrayToFluentRuleRector

The `adaptEnumArg()` method uses runtime reflection (`class_exists()` + `is_subclass_of(BackedEnum::class)`) to decide whether to add `->value` to foreign class constant references. This works because:
- BackedEnum classes: `class_exists` = true, `is_subclass_of` = true → add `->value`
- Non-BackedEnum classes (e.g., constants on regular classes): `class_exists` = true, `is_subclass_of` = false → leave untouched
- Unknown/non-autoloadable classes: `class_exists` = false → add `->value` (safe default)

This requires the consumer's classes to be autoloadable during the Rector run, which is standard for Rector.

### Parallel worker support in ConvertsValidationRules

The parent::rules() manipulation detection uses three layers to work correctly with Rector's parallel mode (`->withParallel()`):

1. **Same-file AST scan** — pre-scans all classes in the current file by unwrapping Rector's `FileNode` and `Namespace_` wrappers via `$this->getFile()->getNewStmts()`
2. **File-based IPC** — writes unsafe parent FQCNs to a shared temp file using `flock(LOCK_EX)` for safe concurrent writes across parallel worker processes
3. **Filesystem scan** — on first conversion attempt per worker, scans all project PHP files (excluding `vendor/`, `node_modules/`, hidden dirs) using `RecursiveCallbackFilterIterator`. Resolves parent FQCNs from raw source via namespace + `use` import analysis

Layer 3 is the most robust — it works independently of process boundaries and catches all cases regardless of file processing order. Verified working with `->withParallel(300, 15, 15)` on hihaho's 3469-test codebase (448 files converted, 0 regressions).

## Implementation

### Phase 1: Set up the new package repo (Priority: HIGH)

- [x] Create new repo `sandermuller/laravel-fluent-validation-rector` on GitHub
- [x] Copy `LICENSE`, `pint.json`, `phpstan.neon.dist`, `.github/workflows/ci.yml` from main package (adjust paths)
- [x] Write initial `composer.json` per Section 2 sketch
- [x] Write initial `README.md` with installation, usage example, link back to main package
- [x] Set up `phpstan-baseline.neon` (empty initially)
- [x] Set up Pest with a minimal `tests/Pest.php` + `tests/TestCase.php`
- [x] Verify empty-package CI passes (PHP syntax check, composer validate)
- [x] Tests — verify `composer install` and `vendor/bin/phpstan analyse` pass on an empty skeleton

### Phase 2: Move the Rector source files (Priority: HIGH)

- [x] Copy all files from `src/Rector/` in main package to `src/Rector/` in new package (keep directory structure)
- [x] Rewrite namespaces: `SanderMuller\FluentValidation\Rector\*` → `SanderMuller\FluentValidationRector\Rector\*`
- [x] `Concerns` subnamespace stays as `Rector\Concerns` → `SanderMuller\FluentValidationRector\Rector\Concerns`
- [x] Update `@see` doc-comments that reference tests — point to the new test class FQCNs under `SanderMuller\FluentValidationRector\Tests\Rector\*`
- [x] Update internal `use` statements to match new namespaces
- [x] Keep references to `SanderMuller\FluentValidation\FluentRule`, `HasFluentRules`, `HasFluentValidation` as-is (they're external symbols from the main package, still valid — resolved via the `require: sandermuller/laravel-fluent-validation` dependency)
- [x] Run `vendor/bin/phpstan analyse src/` on the new package; expect 0 errors (outside existing cognitive complexity baselines)
- [x] Tests — run `composer install && vendor/bin/phpstan analyse` in the new repo; zero namespace-related errors

### Phase 3: Move the Rector tests (Priority: HIGH)

The target layout is a flat `tests/` directory (NOT per-rule subdirectories) to minimize path rewrites. The current test classes use `__DIR__`-relative paths for fixtures and configs — keeping them as siblings preserves this.

```
tests/
├── Pest.php
├── AddHasFluentRulesTraitRectorTest.php
├── AddHasFluentValidationTraitRectorTest.php
├── GroupWildcardRulesToEachRectorTest.php
├── SimplifyFluentRuleRectorTest.php
├── ValidationArrayToFluentRuleRectorTest.php
├── ValidationStringToFluentRuleRectorTest.php
├── Fixture/              ← string rule fixtures
├── FixtureArray/         ← array rule fixtures
├── FixtureGrouping/      ← grouping rule fixtures
├── FixtureLivewire/      ← Livewire trait fixtures
├── FixtureSimplify/      ← simplify rule fixtures
├── FixtureTrait/         ← trait rule fixtures
└── config/               ← per-rule test configs
```

- [x] Copy all files from `tests-rector/` in main package to `tests/` in new package (preserve directory structure)
- [x] Create `tests/Pest.php` (minimal Pest bootstrap)
- [x] Rewrite test namespace: `SanderMuller\FluentValidation\Tests\Rector\*` → `SanderMuller\FluentValidationRector\Tests\*`
- [x] Rewrite fixture namespaces in ALL `.php.inc` files: `SanderMuller\FluentValidation\Tests\Rector\Fixture*` → `SanderMuller\FluentValidationRector\Tests\Fixture*`
- [x] Update test `use` imports to reference the new rule namespaces (e.g., `use SanderMuller\FluentValidationRector\Rector\ValidationArrayToFluentRuleRector`)
- [x] Update `tests/config/*.php` Rector configs: rewrite rule class FQCNs to new namespace
- [x] Verify `provideData()` and `provideConfigFilePath()` paths still resolve (they use `__DIR__` — no change needed since directory structure is preserved)
- [x] Verify each fixture's `use SanderMuller\FluentValidation\FluentRule` stays UNCHANGED (fixture code imports from the main package, not the rector package)
- [x] Run all tests (`vendor/bin/pest`); expect 61 tests, 68 assertions — actual: 63 passed, 70 assertions (peer added `fluent_rules_attribute` fixtures)
- [x] Tests — full suite passes

### Phase 4: Add set list convenience (Priority: MEDIUM)

- [x] Create `config/config.php` (empty entry point — placeholder for future global services)
- [x] Create `config/sets/all.php` that imports convert, group, and traits (NOT simplify — see Section 5)
- [x] Create `config/sets/convert.php` with `ValidationStringToFluentRuleRector` + `ValidationArrayToFluentRuleRector`
- [x] Create `config/sets/group.php` with `GroupWildcardRulesToEachRector`
- [x] Create `config/sets/traits.php` with `AddHasFluentRulesTraitRector` + `AddHasFluentValidationTraitRector`
- [x] Create `config/sets/simplify.php` with `SimplifyFluentRuleRector`
- [x] Create `src/Set/FluentValidationSetList.php` exposing constants for each set path
- [x] Add `extra.rector.includes` to `composer.json` pointing at `config/config.php`
- [x] Document set usage in the new package's README (both `withSets()` and `->withRules()` forms)
- [x] Add an integration test that loads each set and asserts the expected rules are registered in the Rector container
- [x] Tests — each set file loads without error, registers the expected rule count, and `FluentValidationSetList::*` constants resolve to readable files

### Phase 5: Update the main package (Priority: HIGH)

- [x] Delete `src/Rector/` directory
- [x] Delete `tests-rector/` directory
- [x] Remove `rector/type-perfect` from `require-dev` (only used by the custom Rector rules)
- [x] **Keep** `rector/rector`, `driftingly/rector-laravel`, `mrpunyapal/rector-pest` — still used by `rector.php` for main package QA
- [x] **Keep** the `rector` script and `@rector` step in composer.json
- [x] Remove the `src/Rector/*` entries from `phpstan-baseline.neon` (they'll no longer match any path — 18 entries to remove)
- [x] **Full README audit** — the README has extensive Rector references beyond the migration section:
  - [x] Line 28: "the package ships Rector rules" banner → update to "companion Rector package available"
  - [x] Lines 38-40: TOC migration links → update to point at new package
  - [x] Lines 679: `FluentRule::field()` Rector mention → kept as-is (describes behavior, not imports)
  - [x] Lines 801-895: Full "Migrating existing validation with Rector" section → replaced with compact version: install new package, use `FluentValidationSetList::ALL`, link to Rector package README for full details
  - [x] Lines 843-844: `AddHasFluentRulesTraitRector::BASE_CLASSES` config example → removed (now in Rector package README)
  - [x] Lines 857-859: Rule table with class names → removed (now in Rector package README)
- [x] Update `resources/boost/skills/fluent-validation/references/migration-patterns.md` if it references old namespaces
- [x] Run `vendor/bin/pest` — full suite passes without the Rector tests (609 tests, 1227 assertions — count higher than expected due to batch DB and other tests added since spec was written)
- [x] Run `vendor/bin/phpstan analyse --memory-limit=2G` — 0 errors
- [x] Tests — main package's existing tests all pass; total file count reduced

### Phase 6: Publish & release (Priority: HIGH)

- [ ] Tag the new package `v1.0.0` on GitHub
- [ ] Register on Packagist
- [ ] Verify `composer require --dev sandermuller/laravel-fluent-validation-rector` works on a fresh project
- [ ] Verify the rules run correctly in a test consumer (reuse the hihaho integration test pattern)
- [ ] Tag the main package as a minor release (Rector rules are dev tooling, not runtime API — no major bump needed)
- [ ] Update main package CHANGELOG/release notes: list removed classes, link to new package, show migration steps
- [ ] Tests — smoke test on a consumer project

---

## Open Questions

None.

---

## Resolved Questions

1. **Should we ship a single set or multiple granular sets?** **Decision:** Multiple granular sets (ALL, CONVERT, GROUP, TRAITS, SIMPLIFY). **Rationale:** Matches `hihaho/rector-rules` convention and gives users finer control. `ALL` imports all sub-sets for the common case; users can opt into individual transformations (e.g. just CONVERT without GROUP).

2. **Should the new package namespace be `SanderMuller\FluentValidationRector` (flat) or `SanderMuller\FluentValidation\Rector` (nested)?** **Decision:** Flat — `SanderMuller\FluentValidationRector\*`. **Rationale:** Once the package is standalone, the nested form reads oddly ("the Rector subsection of FluentValidation, but in a different package"). Flat matches how other vendor/tool split packages work (e.g. `laravel/framework` vs `laravel/prompts`).

3. **Merge tests under `tests/` or keep `tests-rector/` split?** **Decision:** Flat `tests/` directory preserving the current `tests-rector/` structure (test files + fixture dirs as siblings). **Rationale:** The tests use `__DIR__`-relative paths for `provideData()` and `provideConfigFilePath()`. A flat layout avoids rewriting these paths. Per-rule subdirectories would require updating every test's `__DIR__ . '/FixtureArray'` references. Since all tests in the new package ARE Rector tests, a single `tests/` dir is sufficient.

4. **Hard break or soft deprecation?** **Decision:** Hard removal in a minor release, no shims. **Rationale:** The Rector rules are migration tooling, not runtime API — no application code breaks, only `rector.php` configs. The current user base is hihaho only; they switch namespaces with a single search/replace. A major version bump would falsely signal "your app will break." Ship as a minor release with prominent release notes.

5. **Should the Rector package depend on a specific version of the main package?** **Decision:** Use `^1.0`. **Rationale:** The extraction ships as a minor release on the main package (no major bump), so `^1.0` covers both the pre- and post-extraction releases. The Rector rules only reference class-string symbols (`FluentRule::class`, `HasFluentRules::class`) which are stable within the 1.x line.

6. **What success criteria for Phase 1's "empty skeleton"?** **Decision:** Defer PHPStan setup to Phase 2 when actual source exists. **Rationale:** `phpstan analyse` on an empty `src/` produces "no paths" errors and provides no value. Phase 1 success = `composer validate` passes and `composer install` succeeds.

7. **Should SIMPLIFY be included in ALL?** **Decision:** No — `all.php` imports convert, group, and traits only. **Rationale:** Simplify rules are post-migration cleanup (factory shortcuts, min/max→between, label→factory arg). Running them alongside conversion rules can mask conversion errors. Users should verify conversion results first, then run simplification as a separate pass.

## Findings

- **Main package still uses Rector for QA.** The `rector.php` config runs vendor sets (Laravel, Pest, code quality, dead code, etc.) over `src/` and `tests/`. Only `rector/type-perfect` is exclusively used by the custom Rector rules — the other Rector dependencies must stay. Codex review caught this (2026-04-12).
- **README has 60+ lines of Rector class references.** Lines 801-859 contain full FQCNs, code samples, and a rule table. Phase 5 must do a full audit, not just update the migration section. Codex review caught this (2026-04-12).
- **No major bump needed.** Codex initially flagged a version pin conflict assuming a 2.0 release. Since the extraction ships as a minor release (Rector rules are dev tooling, not runtime API), `^1.0` is sufficient. Resolved (2026-04-12).
- **Test layout stays flat.** Resolved Question 3 chose per-rule subdirectories, but Phase 3 analysis shows the tests use `__DIR__`-relative paths for fixtures/configs. Keeping a flat `tests/` layout (mirroring the current `tests-rector/` structure) avoids path rewrites. Updated Phase 3 accordingly (2026-04-12).
- **Parallel worker support is critical.** The three-layer detection for parent::rules() manipulation was specifically designed to work with Rector's `->withParallel()` mode. Hihaho's production config uses `->withParallel(300, 15, 15)` and the filesystem scan (Layer 3) was the only mechanism that reliably caught cross-file parent/child relationships across separate worker processes. This is documented in Section 7.
- **BackedEnum runtime reflection works for all practical cases.** The `adaptEnumArg()` method uses `class_exists()` + `is_subclass_of()` rather than PHPStan type analysis, which is simpler and more reliable in the Rector context where consumer classes are autoloadable.
- **Spec missed `LogsSkipReasons` trait and `FluentRules` attribute.** The `src/Rector/Concerns/` directory also contains `LogsSkipReasons.php` (used by AddHasFluentRulesTraitRector, AddHasFluentValidationTraitRector, GroupWildcardRulesToEachRector). The main package also has `src/FluentRules.php` (marker attribute). Both were discovered during Phase 2 extraction and included.
- **PHPStan config needed additional dev dependencies.** The new package required `phpstan/phpstan-strict-rules`, `spaze/phpstan-disallowed-calls`, and `phpstan/extension-installer` as dev dependencies — these were inherited implicitly from the main package's dependency tree but needed to be explicit in the standalone package.
- **hihaho verified the full pipeline.** 448 files converted with rector, 192 files formatted with pint, 3469/3469 tests passing — zero manual touchups needed. Net -1485 lines of code. This is the strongest validation that the rules are production-ready for extraction.
