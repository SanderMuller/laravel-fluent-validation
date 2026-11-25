## FluentRule Validation

- This project uses `sandermuller/laravel-fluent-validation` for type-safe validation rules. Use `FluentRule::` instead of string rules or `Rule::` where possible.
- Add the `HasFluentRules` trait to FormRequests and the `HasFluentValidation` trait to Livewire components.
- Use the native FluentRule method instead of `->rule('string_rule')` when one exists. The skill references list them.
- Available types: `FluentRule::string()`, `integer()`, `numeric()`, `email()`, `date()`, `dateTime()`, `boolean()`, `array()`, `file()`, `image()`, `password()`, `field()`.
- Convenience shortcuts: `FluentRule::url()`, `uuid()`, `ulid()`, `ip()` — shorthand for `FluentRule::string()->url()`, etc.
- `email()` and `password()` use app defaults (`Email::default()`, `Password::default()`). Pass `defaults: false` to opt out.
- `requiredIf`, `requiredUnless`, `excludeIf`, `excludeUnless`, `prohibitedIf` and `prohibitedUnless` accept both `(string $field, ...$values)` and `(Closure|bool)`, so pass the closure directly instead of wrapping it in `Rule::requiredIf()`. The other conditional modifiers have no closure or bool form.
- For converting validation rules, activate the `fluent-validation-optimize` skill which has a complete method reference.
- For Livewire-specific guidance, activate the `fluent-validation-livewire` skill.
