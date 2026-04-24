---
name: fluent-validation-migrate-messages
description: "Migrate `public function messages(): array` overrides in FormRequests to inline `message:` on fluent rule chains. Dry-run first, then apply. Activates when: migrating messages array, removing messages() method, inline message rewrite, or user mentions: migrate messages, messages array, inline message, remove messages()."
---

# Migrate `messages(): array` → inline `message:`

Rewrite FormRequest `messages(): array` overrides into colocated `message:` named args on the rule chain in `rules()`. Remove the now-empty `messages()` method when all keys port. Keep only unportable entries behind a comment-stub.

## When to Activate

- User asks to migrate `messages(): array`, "kill messages arrays", "inline messages"
- User mentions: migrate messages, messages array, inline message, remove messages(), pull messages into rules
- After Phase 3 of laravel-fluent-validation 1.19+ inline-message rollout lands in their app

## Step 1: Verify installation

```
rg "sandermuller/laravel-fluent-validation" composer.json
```

Required: `sandermuller/laravel-fluent-validation ^1.19` or newer (inline `message:` param). If older, tell the user to upgrade first — earlier versions lack the `message:` named arg.

## Step 2: Find migration targets

```
rg "function messages\(\)" --type php -l
```

For each match, also verify it overrides `rules(): array` on the same class (FormRequests). Skip test files and non-FormRequest classes.

## Step 3: Classify each `messages(): array` entry

For every `'field.rule' => 'msg'` key, determine the migration path. Read the corresponding entry in `rules()` (same class) and walk the fluent chain.

### Portable cases

| Shape | Rewrite |
|---|---|
| Key `'field.rule'` matches a chain method like `->rule(…)` | Inline: `->rule(…, message: 'msg')` |
| Key `'field.ruleName'` matches the factory's implicit constraint (e.g. `'field.email'` on `FluentRule::email()`) | Factory-level: `FluentRule::email(message: 'msg')` |
| Key matches `->rule(someRule, …)` class-basename fallback | Stays as `->messageFor('someRule', 'msg')`; inline unavailable |

### Unportable cases — keep the `messages()` entry, flag with a comment

Each has a specific reason. Include the reason in the migration report so the user understands why.

1. **Multi-rule-per-factory**: one `FluentRule::email()` chain with three `messages()` keys `'email.required'`, `'email.string'`, `'email.email'`. The factory emits multiple validator rules internally; `message:` carries one binding. The `email` key can port via `FluentRule::email(message: '…')`; `required` ports if the chain has `->required()`; `string` has no method to attach to (it's a byproduct of `FluentRule::email()` setting `$constraints = ['string']`). **Keep `string` in `messages()` array.**
2. **Variadic-trailing methods**: `'field.required_with'` + chain has `->requiredWith('email', 'phone')`. PHP forbids params after variadic. **Rewrite to `->requiredWith('email', 'phone')->message('msg')` (shorter; `->message()` binds to `$lastConstraint` which `addRule` set to `'required_with'`). Not technically inline `message:`, but removes the `messages()` entry.**
3. **Composite method, non-last sub-rule**: `'field.integer'` + chain has `->digits(5)`. `->digits()` adds `integer` then `digits:N`; `message:` binds to the last. **Rewrite as `->digits(5)->messageFor('integer', 'msg')`.**
4. **DateRule build-time key**: `'field.date_format'` + chain is `FluentRule::date()->format('Y-m-d')`. DateRule's key varies between `'date'` and `'date_format:...'` at build. **Rewrite to `FluentRule::date()->format('Y-m-d')->messageFor('date_format', 'msg')`.**
5. **Dynamic key**: `"{$field}.required" => 'msg'`, `match` expression, interpolated variable. Static analysis can't resolve. **Leave entirely.**
6. **Wildcard key on nested `each()`/`children()`**: `'items.*.name.required'`. Walk the outer chain's `->each(...)` closure / array to find the matching inner FluentRule. Rewrite there. Flag complex cases (deeply-nested / multi-level wildcards) for manual review.
7. **Chain interrupted by `->when(…, fn ($r) => $r->required())`**: target rule lives inside the closure. **Rewrite inside the closure: `->when($cond, fn ($r) => $r->required(message: 'msg'))`.**
8. **`->rule('x:args')` escape-hatch string**: `'field.x' => 'msg'` + `->rule('x:args')`. No named method. **Rewrite to `->rule('x:args', message: 'msg')` (rule() accepts message: since Phase 3a).**
9. **Translated-value wrapper**: `'field.required' => __('messages.required')`. Value is still an expr, inline works: `->required(message: __('messages.required'))`. Port normally.
10. **Helper-method extraction**: `'field.required' => 'msg'` where the corresponding rule in `rules()` is `'email' => $this->emailRules()`. Cross-method resolution needed. **Flag for manual review, tip: "inline the chain or move message into helper return."**
11. **Macroable method in chain**: chain includes a method defined via `Macroable::macro(...)` at runtime. Not statically resolvable. **Leave with `messageFor` / `message`.**
12. **Custom `ValidationRule` object via `->rule(new MyRule())`**: message key derived from class-basename at runtime (`'myRule'` from `MyRule`). **Rewrite to `->rule(new MyRule(), message: 'msg')` — `rule()` accepts `message:` and `addRule` resolves the key correctly.**

## Step 4: Present the migration report (DRY-RUN)

Before any edits, output a summary for each target file:

```
## Migration report: app/Http/Requests/ClearSelectedVideoContentRequest.php

### Portable (4)
- items.*.action.type.required → ->required(message: '…') inside each() closure
- search.value.string           → inline string(message: '…')
- name.max                      → ->max(255, message: '…')
- email.email                   → FluentRule::email(message: '…')

### Needs messageFor (2)
- items.*.qty.integer          → ->digits(5)->messageFor('integer', '…') (composite non-last sub-rule)
- required_with on email/phone → ->requiredWith(…)->messageFor('required_with', '…') (variadic method)

### Unportable, stays in messages() (1)
- email_address.string  →  FluentRule::email() emits 'string' implicitly; no chain method to target. Keep.

### messages() method
After migration: keeps 1 key, method retained.
```

Ask the user to confirm per-file before applying.

## Step 5: Apply

One file at a time. For each:

1. Locate the target entry in `rules()`.
2. Rewrite the chain with `message:` / `messageFor` / closure inline per the classification.
3. Remove the migrated key from `messages()`.
4. If `messages()` returns `[]` after all migrations, delete the method.
5. Run tests on that file's FormRequest immediately (`vendor/bin/pest --filter={ClassName}`).
6. If tests fail, inspect and fix or revert — do not proceed to the next file until the current one is green.

## Step 6: Preserve behaviour — test parity

Before migration, capture the actual error messages produced by each `messages()` entry:

```
# Write a snapshot test that exercises each rule with a failing input and asserts the custom message surfaces.
```

Run the snapshot test after migration. The custom messages must still surface identically. If they don't, the migration is wrong — the skip-log classification probably missed a case.

## Guardrails

- **Never delete `messages()` if ANY key survives.** Keep the method with just the unportable keys.
- **Do not migrate keys using dynamic expressions** (interpolation, `match`, property access). Report and skip.
- **Preserve translation wrappers.** `__()`, `trans()`, `Lang::get()` values stay intact — they're just PHP expressions on the value side.
- **One FormRequest at a time.** Don't batch across files; each class may have subtle cross-references.
- **Do not change rule semantics.** Messages are cosmetic; any rewrite that changes WHICH rule fires or WHEN is out of scope for this skill.

## Common Patterns

### Simple unique key

```php
// Before
public function rules(): array {
    return ['route' => ['required', FluentRule::string()->unique('workshops')]];
}

public function messages(): array {
    return ['route.unique' => __('WorkshopRouteAlreadyExists')];
}

// After
public function rules(): array {
    return [
        'route' => ['required', FluentRule::string()->unique('workshops', message: __('WorkshopRouteAlreadyExists'))],
    ];
}

// messages() method removed entirely.
```

### each() inner closure

```php
// Before
public function rules(): array {
    return [
        'items' => FluentRule::array()->required()->each([
            'qty' => FluentRule::numeric()->required()->integer(),
        ]),
    ];
}

public function messages(): array {
    return ['items.*.qty.required' => 'Qty required.'];
}

// After
public function rules(): array {
    return [
        'items' => FluentRule::array()->required()->each([
            'qty' => FluentRule::numeric()->required(message: 'Qty required.')->integer(),
        ]),
    ];
}
```

### Composite method — messageFor for first sub-rule

```php
// Before — `digits` fires after `integer`; custom message targets `integer`.
public function rules(): array {
    return ['code' => FluentRule::numeric()->digits(5)];
}

public function messages(): array {
    return ['code.integer' => 'Must be a whole number.'];
}

// After — messageFor retains the distinction; messages() deletable.
public function rules(): array {
    return [
        'code' => FluentRule::numeric()->digits(5)->messageFor('integer', 'Must be a whole number.'),
    ];
}
```

### Unportable — keep messages() entry

```php
// FluentRule::email() emits 'required' + 'string' + 'email' under some chains.
// 'email.string' has no method to attach to.
public function rules(): array {
    return ['email_address' => FluentRule::email()->when(..., fn ($r) => $r->required())];
}

public function messages(): array {
    return [
        'email_address.required' => 'Required.',   // portable via closure inline
        'email_address.email'    => 'Invalid.',    // portable via FluentRule::email(message: ...)
        'email_address.string'   => 'Must be text.', // UNPORTABLE — stays
    ];
}

// After
public function rules(): array {
    return [
        'email_address' => FluentRule::email(message: 'Invalid.')
            ->when(..., fn ($r) => $r->required(message: 'Required.')),
    ];
}

public function messages(): array {
    return [
        // email.string is emitted implicitly by FluentRule::email();
        // no chain method exists to target it, so it stays here.
        'email_address.string' => 'Must be text.',
    ];
}
```

## See Also

- `fluent-validation` skill: full FluentRule API reference
- `fluent-validation-optimize` skill: broader validation improvements (labels, each(), children())
