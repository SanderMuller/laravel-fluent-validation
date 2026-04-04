# Performance Reference

## The Problem

Laravel's wildcard validation (`items.*.name`) has O(n²) performance for large arrays due to `explodeWildcardRules()` and `shouldBeExcluded()` scanning.

## Two Levels of Optimization

### HasFluentRules / FluentFormRequest / FluentValidator (recommended default)

Replaces Laravel's O(n²) wildcard expansion with an O(n) tree traversal. Also extracts labels and messages from rule objects automatically. For eligible wildcard rules (string, numeric, boolean, integer — without object rules or date comparisons), applies per-attribute fast-checks that skip Laravel's validation entirely for valid items.

| What it does | Speedup |
|---|---|
| O(n) wildcard expansion via `WildcardExpander` | ~20% for large arrays |
| Per-attribute fast-checks on eligible wildcard rules | Significant for valid data |
| Compiles FluentRule objects to native Laravel format | Zero overhead vs string rules |
| Extracts labels and messages in the same pass | No extra iteration |

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

### RuleSet::validate() (maximum performance)

For batch imports and bulk APIs where every millisecond counts. Adds per-item validation and compiled fast-checks on top of the wildcard optimization.

| Optimization | What it does | Speedup |
|---|---|---|
| **Per-item validation** | Reuses one small validator per item | ~40x for complex rules |
| **Compiled fast-checks** | PHP closures skip Laravel for valid items | ~77x for simple rules |
| **Conditional rule rewriting** | Rewrites `exclude_unless` for per-item context | Enables per-item for real-world validators |

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
- `->expandWildcards($data)` — pre-expand without validating
- `RuleSet::compile($rules)` — compile to native Laravel format
