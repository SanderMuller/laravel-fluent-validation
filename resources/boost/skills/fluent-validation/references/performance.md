# Performance Reference

## The Problem

Laravel's wildcard validation (`items.*.name`) has O(n²) performance for large arrays due to `explodeWildcardRules()` and `shouldBeExcluded()` scanning.

## Optimization (automatic via HasFluentRules)

The `HasFluentRules` trait (and `FluentFormRequest`) applies three optimizations automatically:

| Optimization | What it does | Speedup |
|---|---|---|
| **O(n) wildcard expansion** | `WildcardExpander` replaces Laravel's O(n²) approach | ~20% for large arrays |
| **Per-attribute fast-checks** | Pure PHP closures skip Laravel for valid items (25 rules supported) | Up to 97x for simple rules |
| **Partial fast-check** | Fast-checkable fields use PHP, non-eligible fields go through Laravel | 10x for mixed rule sets |

Fast-checked rules: `required`, `filled`, `string`, `numeric`, `integer`, `boolean`, `date`, `email`, `url`, `ip`, `uuid`, `ulid`, `alpha`, `alpha_dash`, `alpha_num`, `accepted`, `declined`, `min`, `max`, `digits`, `digits_between`, `in`, `not_in`, `regex`, `not_regex`.

```php
use SanderMuller\FluentValidation\HasFluentRules;

class ImportRequest extends FormRequest
{
    use HasFluentRules;

    public function rules(): array
    {
        return [
            'items' => FluentRule::array()->required()->each([...]),
        ];
    }
}
```

### Benchmarks

| Scenario | Native Laravel | With HasFluentRules |
|---|---|---|
| 500 items, simple rules (string, numeric, in) | ~200ms | **~2ms** |
| 500 items, mixed rules (string + date) | ~200ms | **~20ms** |
| 100 items, 47 conditional fields (exclude_unless) | ~3,200ms | **~83ms** |

### RuleSet::validate() (inline validation)

For validation outside FormRequests. Applies the same optimizations with an additional per-item approach (reuses one small validator per item instead of one giant validator).

```php
$validated = RuleSet::from([...])->validate($request->all());
```

## Custom Validator Subclasses

Extend `FluentValidator` instead of `Illuminate\Validation\Validator`. It handles the full pipeline automatically:

```php
use SanderMuller\FluentValidation\FluentValidator;

class JsonImportValidator extends FluentValidator
{
    public function __construct(array $data, protected ?User $user = null)
    {
        parent::__construct($data, $this->buildRules());
    }
}
```

Resolves translator, presence verifier, calls `prepare()`, sets implicit attributes. For manual control, use `RuleSet::prepare($data)` which returns a `PreparedRules` DTO.

## RuleSet API

- `RuleSet::from([...])` — create from array
- `RuleSet::make()->field(...)` — fluent builder
- `->merge($ruleSetOrArray)` — merge rules
- `->when($cond, $callback)` / `->unless(...)` — conditional fields
- `->prepare($data)` — expand + extract metadata + compile (returns `PreparedRules` DTO)
- `->toArray()` — flat rules with `each()` expanded
- `->validate($data, $messages?, $attributes?)` — validate with full optimization
- `->failOnUnknownFields()` — reject input keys not present in the rule set
- `->stopOnFirstFailure()` — stop validating after the first field fails
- `->expandWildcards($data)` — pre-expand without validating
- `RuleSet::compile($rules)` — compile to native Laravel format
- `RuleSet::compileToArrays($rules)` — compile to array format (`array<string, array<mixed>>`), useful for Livewire's `$this->validate()`
- `->dump()` — returns `{rules, messages, attributes}` for debugging
- `->dd()` — dumps and terminates
