# Testing

`SanderMuller\FluentValidation\Testing\FluentRulesTester` lets you write direct unit tests against fluent rules, RuleSets, `FluentFormRequest` subclasses, and `FluentValidator` subclasses without standing up the HTTP kernel or Livewire harness. It's the package's stable test surface; everything else under `Testing\` is `@internal`.

On this page: [Targets](#targets) · [Assertions](#assertions) · [FormRequest binding](#formrequest-binding) · [Livewire components](#livewire-components) · [Pest expectations](#pest-expectations-optional)

## Targets

`FluentRulesTester::for($target)` accepts any of these. Chain `->with($data)` before assertions (required; calling assertions sooner raises `LogicException`). `with()` is re-callable so one tester can validate multiple payloads.

```php
use SanderMuller\FluentValidation\Testing\FluentRulesTester;

// 1. Array of rules
FluentRulesTester::for(['email' => FluentRule::email()->required()])
    ->with(['email' => 'a@b.test'])->passes();

// 2. RuleSet instance
FluentRulesTester::for(RuleSet::make()->field('name', FluentRule::string()->required()))
    ->with(['name' => 'Ada'])->passes();

// 3. A single FluentRule (wrapped under the "value" key)
FluentRulesTester::for(FluentRule::string()->required()->min(3))
    ->with(['value' => 'hi'])->fails();

// 4. FormRequest class-string, full pipeline including authorize()
FluentRulesTester::for(UpdateVideoRequest::class)
    ->withRoute(['video' => $video])->actingAs($user)
    ->with(['title' => 'Updated'])->passes();

// 5. FluentValidator class-string; variadic args after for(...) forward to the constructor
FluentRulesTester::for(JsonImportValidator::class, $user, 'sku-')
    ->with($payload)->passes();

// 6. Livewire Component class-string; routes through Livewire::test() so the submit() flow runs
FluentRulesTester::for(AppealPage::class)
    ->set('type', 'refund')->call('submit')->passes();
```

## Assertions

| Method                                                       | Purpose                                                                                                                                                            |
|--------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `->passes()`                                                 | No errors on any field                                                                                                                                             |
| `->fails()`                                                  | At least one error                                                                                                                                                 |
| `->failsWith($field, $rule = null)`                          | Field failed. Optional rule key normalized via `Str::studly` (`required` and `Required` both match)                                                                |
| `->failsWithMessage($field, $translationKey, $replacements)` | Rendered translation matches. Use when porting tests that compare against `__()` output. Pass `:attribute` explicitly when rules use labels                        |
| `->failsOnly($field, $rule = null)`                          | Exactly one field failed; surgical regression detection. Wildcard error keys expand (`items.0.name`); requires exactly one matching key                            |
| `->failsWithAny($prefix)`                                    | Prefix matched exactly or any dotted descendant (`actions.0.payload` → also matches `actions.0.payload.stars`). Not a substring match                              |
| `->doesNotFailOn(...$fields)`                                | Named fields did not fail. Chain after `->fails()`/`->passes()` if overall pass/fail matters; `doesNotFailOn` alone does not assert either direction               |
| `->assertUnauthorized()`                                     | FormRequest `authorize()` returned false. The tester records the `AuthorizationException` rather than rethrowing; surface it via `->fails()->assertUnauthorized()` |

Escape hatches: `->errors()` returns `MessageBag`; `->validated()` returns the validated array (throws `ValidationException` on failure).

## FormRequest binding

```php
FluentRulesTester::for(UpdateVideoRequest::class)
    ->withRoute(['video' => $video])   // $this->route('video') returns $video
    ->actingAs($user)                    // $this->user() / auth()->user()
    ->with(['title' => 'New title'])
    ->passes();
```

Both `withRoute()` and `actingAs($user, $guard = null)` are re-callable (later calls fully replace earlier). `actingAs` mirrors Laravel's test helper and sets the user on the auth guard before `validateResolved()` runs.

## Livewire components

Two shapes. Pick the one matching what you're asserting:

| Shape              | When to use                                                                                                              | Target                                            |
|--------------------|--------------------------------------------------------------------------------------------------------------------------|---------------------------------------------------|
| **Rules-only**     | Assert `rules()` has the right shape against a payload. Component lifecycle irrelevant                                   | `for($component->rules())` or `for($ruleSet)`     |
| **Component-flow** | Drive `wire:model` state, dispatch an action, assert validation fires (or `addError` branches, guards, multi-step flows) | `for(ComponentClass::class)->set(...)->call(...)` |

The class-string target auto-detects Livewire `Component` subclasses and routes through `Livewire::test()`, so the full `submit()` flow runs: guard clauses, `addError()` branches, computed state, rate-limit gates. Both `$this->validate()` failures and manual `addError()` calls surface via `failsWith()`.

```php
FluentRulesTester::for(EditAppealPage::class)
    ->mount(['appeal' => $appeal])                 // for components with mount() params
    ->set('type', 'refund')                         // or set([...]) for multiple keys
    ->set('reason', 'Order arrived damaged.')
    ->call('submit')                                // required before any assertion
    ->passes();
```

`with([...])` expands to `set($key, $value)` per pair on Livewire targets. For multi-action chains where action 1 mutates state that action 2 validates against, queue both with `->call(...)->andCall(...)`; state persists across one `Livewire::test()` instance, then clears on the next chain:

```php
FluentRulesTester::for(ImportInteractionsModal::class)
    ->set('video', $targetVideo)
    ->call('selectVideo', $sourceVideo->uuid)
    ->andCall('import')
    ->failsWith('selectedInteractionIds', 'required');
```

`livewire/livewire` is a soft dev dep; the Livewire branch `class_exists`-guards on `\Livewire\Component`. PHPUnit-only suites see the standard "unsupported target" `LogicException` rather than a hard fatal.

<details>
<summary>Edge cases and advanced patterns</summary>

**Re-callable `with()` on one tester:**

```php
$tester = FluentRulesTester::for($rules);
$tester->with(['qty' => 5])->passes();
$tester->with(['qty' => 0])->fails();
```

**`failsWithMessage()` with labels.** When rules use labels, the validator pre-substitutes `:attribute` into the stored message, so pass it explicitly for the comparison to match:

```php
FluentRulesTester::for([
    'password' => FluentRule::password('Password')->required()->min(8),
])
    ->with(['password' => 'short'])
    ->failsWithMessage('password', 'validation.min.string', [
        'attribute' => 'Password',
        'min' => 8,
    ]);
```

**`withRoute()` default semantics.** Inside the FormRequest:

- `$this->route('video')` returns the bound `$video`
- `$this->route('video', $default)` returns `$video` (default ignored when key present)
- `$this->route('missing', $default)` returns `$default`

**Pre-validate vs post-validate `addError`.** Both surface via `failsWith()`. A rate-limit guard that returns before `validate()` runs, and a quota check that runs after a successful `validate()`, both land in the bag:

```php
// Pre-validate guard. Returns before validate() ever runs.
FluentRulesTester::for(AppealPage::class)
    ->set('rateLimited', true)
    ->call('submit')
    ->failsWith('reason');

// Post-validate addError. validate() passes, then addError.
FluentRulesTester::for(AppealPage::class)
    ->set('type', 'refund')
    ->set('reason', 'Long enough reason.')
    ->set('quotaExceeded', true)
    ->call('submit')
    ->failsWith('type');
```

**State lifecycle on Livewire testers.** After one `->call(...)` chain resolves, the accumulated `with()` / `set()` / `call()` state clears. Each new chain starts from a fresh `Livewire::test()` instance, so reused testers don't leak prior cycles into new ones.

**Choosing Livewire target shape.** Don't reach for the class-string target just because the rules live in a Livewire component. Use it only when the `submit()` flow (guards, `addError`, computed state) matters to the test; otherwise stay on the array or RuleSet target with `->with()`.

</details>

## Pest expectations (optional)

```php
// tests/Pest.php
require_once __DIR__ . '/../vendor/sandermuller/laravel-fluent-validation/src/Testing/PestExpectations.php';
```

```php
expect($rules)->toPassWith(['email' => 'a@b.test']);
expect($rules)->toFailOn(['email' => ''], 'email', 'required');
expect(FluentRule::string()->required())->toBeFluentRuleOf(StringRule::class);
```

The file `class_exists`-guards on `Pest\Expectation`, so requiring it under PHPUnit-only suites is safe.
