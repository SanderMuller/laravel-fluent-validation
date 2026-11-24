# Troubleshooting

**Labels not working ("The name field" instead of "The Full Name field")**
Add `use HasFluentRules`. The trait extracts labels from rule objects and passes them to the validator. Without it, labels are only used inside the rule's self-validation.

**Cross-field wildcard references don't work (`requiredUnless('items.*.type', ...)`)**
These require `HasFluentRules` or `FluentValidator` to resolve wildcard paths. Standalone FluentRule objects self-validate in isolation.

**Child form request loses or corrupts parent rules**
`array_merge_recursive` flattens FluentRule objects into arrays. See [Extending parent rules](07-extending-rules.md) for the supported merge patterns (`put()`, `except()`, `modify()`, `modifyEach()`, `modifyChildren()`, spread and clone).

**`items.*` and an explicit key such as `items.1` give a different result than plain Laravel**
In plain Laravel, an explicit key written after the wildcard key replaces the wildcard rules for that element, so `['items.*' => 'integer', 'items.1' => 'nullable']` accepts `'x'` at `items.1`. `RuleSet::validate()` and `check()` apply both rules, as Laravel does when the explicit key comes first. Write the explicit key first to get the same result in both.

**Method not found on a rule type**
Use `->rule('method_name')` as an escape hatch for any Laravel rule not yet available as a fluent method. Accepts strings, objects, and `['rule', ...$params]` tuples.
If you think it should be a native method, [open an issue](https://github.com/SanderMuller/laravel-fluent-validation/issues) and we'll add it.

**`UnknownFluentRuleMethod: FluentRule::field() has no method ...()`**
`FluentRule::field()` is the untyped builder; type-specific rules (`min`, `max`, `regex`, `email`, `digits`, `mimes`, `before`, `after`, `contains`) live on the typed builders. The exception message names the builders that expose the method. Pick the one matching your field's type:

```php
FluentRule::numeric()->required()->min(5);   // numeric value
FluentRule::string()->required()->min(5);    // string length
FluentRule::array()->required()->min(5);     // element count
FluentRule::file()->required()->min('2mb');  // file size
```

The smell-form `FluentRule::field()->rule('min:1')` (or any `->rule('some_type_rule:...')` on `field()`) works at runtime but is non-idiomatic. Pick the typed builder. The [Rector companion](https://github.com/sandermuller/laravel-fluent-validation-rector) auto-simplifies it. For test-time coverage, see `SanderMuller\FluentValidation\Testing\Arch\BansFieldRuleTypeMethods` (requires `nikic/php-parser` dev dep).

**`HasFluentValidation` conflicts with Filament's `InteractsWithForms` / `InteractsWithSchemas`**
Use `HasFluentValidationForFilament` instead. See [Livewire → Filament components](08-livewire.md). The Rector companion picks it plus the `insteadof` block automatically.

**Migration issues (Rector companion)**
Rector-specific issues are tracked in the [laravel-fluent-validation-rector README](https://github.com/sandermuller/laravel-fluent-validation-rector#troubleshooting). Update the Rector companion to the latest version first; most are fixed upstream.
