# Migrating to fluent validation

## Migrating existing rules

You don't need to convert all your rules at once. Fluent rules mix freely with string rules and native rule objects in the same array:

```php
$rules = [
    'name'   => FluentRule::string()->required()->min(2)->max(255),  // fluent
    'email'  => 'required|string|email|max:255',               // string, still works
    'role'   => ['required', LaravelRule::in(['admin', 'user'])],  // array, still works
];
```

**Step 1:** Add `use HasFluentRules` to your form request. This works even before you convert any rules.

**Step 2:** Convert fields. Either by hand (start with the ones that benefit most from autocompletion: complex conditionals, date comparisons, nested arrays), or run the [Rector companion](#migrating-existing-validation-with-rector) to migrate the bulk of your rules in one pass and review the diff. Common conversions if you're going manually:

| Before                                              | After                                                                     |
|-----------------------------------------------------|---------------------------------------------------------------------------|
| `'items.*.name' => 'required\|string'`              | `FluentRule::array()->each(['name' => FluentRule::string()->required()])` |
| `'search' => 'array'` and `'search.value' => '...'` | `FluentRule::array()->children(['value' => ...])`                         |
| `Rule::in([...])`                                   | `->in([...])` or `->in(MyEnum::class)`                                    |
| `Rule::unique('users')`                             | `->unique('users')`                                                       |
| `Rule::forEach(fn () => ...)`                       | `FluentRule::array()->each(...)`                                          |

All conditional methods (`requiredIf`, `excludeUnless`, etc.) accept `Closure|bool` in addition to field references. `each()` and `children()` nest naturally. Flat dot-notation keys like `columns.*.data.sort` become nested `each([...children([...])])` trees that mirror the data shape.

> [!TIP]
> **Using Boost?** If you have [Laravel Boost](https://github.com/laravel/boost) installed, ask your AI assistant to run the `fluent-validation-optimize` skill. It scans your codebase for convertible rules, prioritizes by impact, and applies changes file by file.

**Step 3:** For rules without a direct fluent method, use the `rule()` escape hatch:

```php
FluentRule::string()->rule('email:rfc,dns')           // string rule
FluentRule::string()->rule(new MyCustomRule())         // object rule
FluentRule::file()->rule(['mimetypes', ...$types])     // array tuple
```

## Migrating existing validation with Rector

The companion package [`sandermuller/laravel-fluent-validation-rector`](https://github.com/sandermuller/laravel-fluent-validation-rector) automates the bulk of a migration from native Laravel validation to FluentRule. In real-world testing against a production Laravel codebase, the rules converted **448 files across 3469 tests with zero regressions**.

```bash
composer require --dev sandermuller/laravel-fluent-validation-rector
```

The Rector package covers the full migration surface: pipe-delimited strings, array-based rules, `Rule::` objects, `Password::min()` chains, conditional tuples, closures, custom rule objects, Livewire `#[Rule]` / `#[Validate]` attributes, wildcard grouping, trait insertion, and post-migration chain cleanup.

See the [Rector package README](https://github.com/sandermuller/laravel-fluent-validation-rector) for `rector.php` setup, the set catalog (`ALL`, `CONVERT`, `GROUP`, `TRAITS`, `SIMPLIFY`, `POLISH`), per-rector configuration constants, the `#[FluentRules]` per-method opt-in, the post-migration verification workflow, and skip-log diagnostics.

See [Common migration patterns](https://github.com/SanderMuller/laravel-fluent-validation/blob/main/resources/boost/skills/fluent-validation/references/migration-patterns.md) for a detailed reference covering rule-type selection, `Rule::` method conversion, BackedEnum handling, and advanced patterns when Rector leaves a file alone.

The Rector rules aren't just for migration. Run `ALL` (or `SIMPLIFY` on its own) in CI as an ongoing code-quality gate. New validation code (new FormRequests, new Livewire components, new Filament pages) goes through the same converters, grouping, and trait insertion as the initial migration did, so patterns stay consistent as the codebase grows.

> [!TIP]
> **Prefer explicit parent rules for new code.** Pair `'items' => FluentRule::array()->required()` with `'items.*.name' => FluentRule::string()->required()` so nullability/required/size live on the parent. Rector's `GroupWildcardRulesToEachRector` synthesizes `FluentRule::array()->nullable()` when the parent is missing (preserving flat-rule null-parity), so existing codebases migrate fine either way.
