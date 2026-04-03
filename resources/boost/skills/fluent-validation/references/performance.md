# Performance Reference

## The Problem

Laravel's wildcard validation (`items.*.name`) has O(n²) performance for large arrays due to `explodeWildcardRules()` and `shouldBeExcluded()` scanning.

## Optimization Layers

| Optimization | What it does | Speedup |
|---|---|---|
| **Per-item validation** | Reuses one small validator per item | ~40x for complex rules |
| **Compiled fast-checks** | PHP closures skip Laravel for valid items | ~77x for simple rules |
| **Conditional rule rewriting** | Rewrites `exclude_unless` for per-item context | Enables per-item for real-world validators |

## How to Use

### FormRequest (recommended)

```php
use SanderMuller\FluentValidation\ExpandsWildcards;

class ImportRequest extends FormRequest
{
    use ExpandsWildcards;

    public function rules(): array
    {
        return [
            'items' => FluentRule::array()->required()->each([...]),
        ];
    }
}
```

### Inline validation

```php
$validated = RuleSet::from([...])->validate($request->all());
```

### Custom Validator subclasses

Use `RuleSet::compile()` to convert FluentRules to native format. Required when rules reference other fields using wildcards (`requiredUnless('*.type', ...)`):

```php
parent::__construct($translator, $data, rules: RuleSet::compile($this->buildRules()));
```

## RuleSet API

- `RuleSet::from([...])` — create from array
- `RuleSet::make()->field(...)` — fluent builder
- `->merge($ruleSetOrArray)` — merge rules
- `->when($cond, $callback)` / `->unless(...)` — conditional fields
- `->toArray()` — flat rules with `each()` expanded
- `->validate($data, $messages?, $attributes?)` — validate with full optimization
- `->expandWildcards($data)` — pre-expand without validating
- `RuleSet::compile($rules)` — compile to native Laravel format
