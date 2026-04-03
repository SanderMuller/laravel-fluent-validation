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

Use `->prepare($data)` for a single-call pipeline that handles expand, extract metadata, and compile in the correct order:

```php
$prepared = RuleSet::from($this->buildRules())->prepare($data);

parent::__construct($translator, $data, $prepared->rules, $prepared->messages, $prepared->attributes);

// Apply wildcard mapping (needed for distinct, cross-field comparisons)
if ($prepared->implicitAttributes !== []) {
    (new ReflectionProperty($this, 'implicitAttributes'))->setValue($this, $prepared->implicitAttributes);
}
```

`PreparedRules` contains: `rules`, `messages`, `attributes`, `implicitAttributes`.

## RuleSet API

- `RuleSet::from([...])` — create from array
- `RuleSet::make()->field(...)` — fluent builder
- `->merge($ruleSetOrArray)` — merge rules
- `->when($cond, $callback)` / `->unless(...)` — conditional fields
- `->prepare($data)` — expand + extract metadata + compile (returns `PreparedRules` DTO)
- `->toArray()` — flat rules with `each()` expanded
- `->validate($data, $messages?, $attributes?)` — validate with full optimization
- `->expandWildcards($data)` — pre-expand without validating
- `RuleSet::compile($rules)` — compile to native Laravel format
