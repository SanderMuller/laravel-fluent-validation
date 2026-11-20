# Extending parent rules in child form requests

With `HasFluentRules` or `FluentFormRequest`, `rules()` can return a `RuleSet`. A child of such a form request can add, replace, remove or extend fields:

```php
// Parent
public function rules(): RuleSet
{
    return RuleSet::from([
        'name' => FluentRule::string()->required()->max(255),
        'email' => FluentRule::email()->required(),
        'nickname' => FluentRule::string()->nullable(),
    ]);
}

// Child
public function rules(): RuleSet
{
    return parent::rules()
        ->put('company', FluentRule::string()->required())             // add
        ->put('email', FluentRule::email()->nullable())                // replace
        ->except('nickname')                                           // remove
        ->modify('name', fn (StringRule $rule) => $rule->alphaDash()); // extend
}
```

The chain changes the `RuleSet` that `parent::rules()` returns, so the parent builds a new one on each call, as above. `modify()` passes a shallow clone of the rule, so a call on the rule itself, such as `alphaDash()`, leaves a rule object that the parent shares unchanged. For a sub-rule of `each()` or `children()`, use `modifyEach()` or `modifyChildren()` below. The callback adds to the parent's rule and does not remove its constraints: `name` gets `required|string|max:255|alpha_dash`. To change a constraint such as `max:255`, replace the rule with `put()`. A second `max()` adds a limit and keeps `max:255`.

When the parent returns an array, spread it: `[...parent::rules(), 'company' => FluentRule::string()->required()]`. Fluent methods change the rule object, so clone a parent's rule before you change it: `$rules['name'] = (clone $rules['name'])->alphaDash()`.

When the parent defines a keyed `each([...])` or `children([...])` map and the child needs to add or replace one sub-rule, use the extend helpers on `ArrayRule` / `FieldRule` via `RuleSet::modify`, or reach for the `modifyEach` / `modifyChildren` sugar:

```php
// Parent
return RuleSet::from([
    'answers' => FluentRule::array()->nullable()->max(20)->each([
        'text' => FluentRule::string()->required(),
    ]),
]);

// Child, sugar form (later-wins merge)
return parent::rules()->modifyEach('answers', [
    'id' => FluentRule::numeric()->nullable(),
]);

// Or the strict-add primitive; throws on existing-key collision
return parent::rules()->modify('answers', fn (ArrayRule $rule) =>
    $rule->addEachRule('id', FluentRule::numeric()->nullable())
);
```

`modifyEach` and `modifyChildren` replace a sub-rule with the same key. `addEachRule` and `addChildRule` throw instead. The parent's own constraints, such as `nullable` and `max:20`, stay.

`rules()` may return a `RuleSet`, so you can chain `->only()`, `->except()`, `->merge()` or `->put()` without a final `->toArray()`. This works with `HasFluentRules` and `HasFluentValidation`:

```php
// Assumes a class-level helper that returns a RuleSet, e.g.
//   class UserRules { public static function base(): RuleSet { return RuleSet::from([...]); } }
public function rules(): RuleSet
{
    return UserRules::base()
        ->only(['email', 'password'])
        ->put('email_confirmation', FluentRule::email()->required()->same('email'));
}
```

`RuleSet` also implements `IteratorAggregate`, so spread works on it too: `[...$ruleSet, 'extra' => FluentRule::string()->required()]`.
