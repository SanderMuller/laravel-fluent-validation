# Extending parent rules in child form requests

To add fields in a child, use the spread operator: `return [...parent::rules(), 'extra' => FluentRule::string()->required()]`. If you need to modify a parent's rule, clone it first since `->rule()` mutates the object: `$rules['type'] = (clone $rules['type'])->rule(new ExtraRule())`.

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
