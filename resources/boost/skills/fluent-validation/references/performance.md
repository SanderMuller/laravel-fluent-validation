# Performance Reference

## The Problem

Laravel's wildcard validation (`items.*.name`) has O(n²) performance for large arrays due to `explodeWildcardRules()` and `shouldBeExcluded()` scanning.

## Optimization (automatic via HasFluentRules)

The `HasFluentRules` trait (and `FluentFormRequest`) applies four optimizations automatically:

| Optimization | What it does | Speedup |
|---|---|---|
| **O(n) wildcard expansion** | `WildcardExpander` replaces Laravel's O(n²) approach | ~20% for large arrays |
| **Per-attribute fast-checks** | Pure PHP closures skip Laravel for valid items (25 rules supported) | Up to 97x for simple rules |
| **Partial fast-check** | Fast-checkable fields use PHP, non-eligible fields go through Laravel | 10x for mixed rule sets |
| **Batched DB validation** | `exists`/`unique` rules batched into single `whereIn` query | N queries → 1 query |

Fast-checked rules: `required`, `prohibited`, `filled`, `string`, `numeric`, `integer`, `boolean`, `date`, `email`, `url`, `ip`, `uuid`, `ulid`, `alpha`, `alpha_dash`, `alpha_num`, `accepted`, `declined`, `min`, `max`, `digits`, `digits_between`, `in`, `not_in`, `regex`, `not_regex`, `required_with`, `required_without`, `required_with_all`, `required_without_all`.

The presence-conditional family (`required_with*` / `required_without*`) is fast-checkable in two ways: (1) the per-item closure handles simple dependent field names directly; (2) when a dependent field uses a dotted path (`required_without:profile.birthdate`), `RuleSet::reduceRulesForItem` pre-evaluates the conditional against the item data and rewrites the rule to plain `required` (or drops it when inactive) before the closure compiles. The rewrite is skipped when the user has supplied a custom message for the original rule name so `{field}.required_without` overrides continue to fire. ~7x speedup measured on 500-item wildcard forms with nested dependent paths (`tests/SlowPathBenchTest.php`).

### Batched database validation (N+1 prevention)

`exists` and `unique` rules on wildcard arrays are automatically batched into a single `whereIn` query instead of one query per item. 500 items with `exists('products', 'id')` fires 1 query, not 500.

**What's batched:** Simple `exists('table', 'column')` and `unique('table', 'column')` rules, including those with scalar `where()` clauses (e.g., `->exists('subjects', 'id', fn ($r) => $r->where('video_id', $id))`).

**What's NOT batched (falls through to per-item):** Rules with closure callbacks (`->where(fn ($q) => ...)`), rules without an explicit column, and non-wildcard single-field rules.

The batching is transparent — error messages, custom messages, `validated()` output, and `ignore()` on unique rules all work unchanged. Uses `useWritePdo()` to match Laravel's native verifier behavior in read/write split setups.

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
| 200 items, exists rule (DB validation) | ~11ms (200 queries) | **~6ms (1 query)** |

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
