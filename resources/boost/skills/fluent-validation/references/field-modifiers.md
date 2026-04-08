# Field Modifiers Reference

All rule types share these modifiers.

## Labels and Messages

- `label($label)` — set the `:attribute` name used in error messages. Also available as factory argument: `FluentRule::string('Full Name')`
- `message($msg)` — custom error message for the most recently added rule. Must be called AFTER the rule it applies to.
- `messageFor($rule, $msg)` — custom error message by rule name. Can be called at any point in the chain: `->messageFor('required', 'We need this!')`
- `fieldMessage($msg)` — fallback error message for ANY rule failure on this field. Rule-specific messages take priority.

## Presence

- `required()`, `nullable()`, `sometimes()`, `filled()`, `present()`, `missing()`
- `requiredIf($field, ...$values)` — also accepts `Closure|bool`: `requiredIf(fn () => true)`, `requiredIf(true)`
- `requiredUnless($field, ...$values)` — also accepts `Closure|bool`
- `requiredWith(...$fields)`, `requiredWithAll(...$fields)`
- `requiredWithout(...$fields)`, `requiredWithoutAll(...$fields)`
- `requiredIfAccepted($field)`, `requiredIfDeclined($field)`
- `presentIf($field, ...$values)`, `presentUnless($field, ...$values)`
- `presentWith(...$fields)`, `presentWithAll(...$fields)`
- `missingIf($field, ...$values)`, `missingUnless($field, ...$values)`
- `missingWith(...$fields)`, `missingWithAll(...$fields)`

All variadic `$values` parameters accept `string|int|bool`.

## Prohibition

- `prohibited()`, `prohibits(...$fields)`
- `prohibitedIf($field, ...$values)` — also accepts `Closure|bool`
- `prohibitedUnless($field, ...$values)` — also accepts `Closure|bool`
- `prohibitedIfAccepted($field)`, `prohibitedIfDeclined($field)`

## Exclusion

- `exclude()`
- `excludeIf($field, ...$values)` — also accepts `Closure|bool`
- `excludeUnless($field, ...$values)` — also accepts `Closure|bool`
- `excludeWith($field)`, `excludeWithout($field)`

**Caveat:** `exclude` rules only affect `validated()` output when placed at the outer validator level. To exclude a field, place `exclude` alongside the fluent rule: `'field' => ['exclude', FluentRule::string()]`

### Closure/bool examples for conditional modifiers

All conditional modifiers (`requiredIf`, `excludeIf`, `prohibitedIf`, etc.) accept BOTH forms:

```php
// Field + value form:
->excludeIf('type', 'guest')
->requiredIf('role', 'admin', 'editor')

// Closure/bool form (DO NOT use ->rule(Rule::excludeIf(...)) — use this directly):
->excludeIf(fn () => $this->user()->isGuest())
->excludeIf(true)
->requiredIf(fn () => $someCondition)
```

## Flow Control

- `bail()` — stop on first failure
- `rule($rule)` — escape hatch for any Laravel rule: string, `ValidationRule` object, `Closure`, or array tuple `['mimetypes', ...$types]`

## Debugging

- `toArray()` — returns compiled rules as an array (e.g., `['required', 'string', 'max:255']`)
- `dd(...$args)` — dumps compiled rules and terminates
- `dump(...$args)` — dumps compiled rules and continues (chainable)

## Conditional Rules

- `when($condition, $callback, $default?)` — build-time condition (from `Conditionable`). Evaluated when building the rules array.
- `whenInput($condition, $rules, $default?)` — validation-time condition. The closure receives the full input as a `Fluent` object. Rules can be closures (receive a fresh builder) or strings.

```php
// Build-time
FluentRule::string()->when($isAdmin, fn ($r) => $r->min(12))

// Validation-time (data-dependent)
FluentRule::string()->whenInput(
    fn ($input) => $input->role === 'admin',
    fn ($r) => $r->required()->min(12),
    fn ($r) => $r->sometimes()->max(100),
)
```
