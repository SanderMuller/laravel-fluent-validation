<?php declare(strict_types=1);

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\Internal\ItemRuleCompiler;
use SanderMuller\FluentValidation\Internal\ItemRulePlan;
use SanderMuller\FluentValidation\RuleSet;

/**
 * A plan outlives the validation only when nothing in it can change between
 * two validations of the same rules.
 */
/**
 * @param  array<string, mixed>  $itemRules
 * @param  array<string, string>  $messages
 */
function itemRulePlan(array $itemRules, array $messages = []): object
{
    $compiler = new ItemRuleCompiler();

    return ItemRulePlan::for($compiler, $itemRules, $compiler->analyzeConditionals($itemRules), $messages, false);
}

it('reuses the plan for the same plain rules', function (): void {
    $rules = ['type' => 'required|string', 'size' => 'nullable|exclude_unless:type,big|integer'];

    expect(itemRulePlan($rules))->toBe(itemRulePlan($rules))
        ->and(itemRulePlan($rules, ['size.integer' => 'Whole numbers only']))->not->toBe(itemRulePlan($rules));
});

it('makes a new plan for rules that compile the current time', function (string $rule): void {
    $rules = ['starts_at' => $rule];

    expect(itemRulePlan($rules))->not->toBe(itemRulePlan($rules));
})->with([
    'after:today' => ['required|date|after:today'],
    'before:now' => ['required|date|before:now'],
    'date_equals:today' => ['required|date|date_equals:today'],
]);

it('makes a new plan for rules that hold an object', function (): void {
    $rules = ['name' => ['required', FluentRule::string()]];

    expect(itemRulePlan($rules))->not->toBe(itemRulePlan($rules));
});

it("reuses the plan for rules with Laravel's in and not_in rules", function (): void {
    $rules = static fn (array $values): array => ['type' => ['required', Rule::in($values)], 'kind' => ['nullable', Rule::notIn(['x'])]];

    expect(itemRulePlan($rules(['a', 'b'])))->toBe(itemRulePlan($rules(['a', 'b'])))
        ->and(itemRulePlan($rules(['a', 'c'])))->not->toBe(itemRulePlan($rules(['a', 'b'])));
});

it('makes a new plan for a subclass of the in rule', function (): void {
    $rules = static fn (): array => ['type' => ['required', new class (['a']) extends In {}]];

    expect(itemRulePlan($rules()))->not->toBe(itemRulePlan($rules()));
});

it('drops the plans after the cap and builds them again', function (): void {
    $first = itemRulePlan(['name' => 'required|string|max:1']);

    for ($i = 2; $i <= 65; $i++) {
        itemRulePlan(['name' => 'required|string|max:' . $i]);
    }

    expect(itemRulePlan(['name' => 'required|string|max:1']))->not->toBe($first);
});

it('applies the messages of each validation of the same rules', function (): void {
    $rules = ['items' => FluentRule::array()->each([
        'type' => FluentRule::string()->required(),
        'size' => FluentRule::integer()->nullable()->excludeUnless('items.*.type', 'big'),
    ])];
    $data = ['items' => [['type' => 'big', 'size' => 'x']]];

    expect(RuleSet::from($rules)->check($data, ['items.*.size.numeric' => 'First'])->errors()->first('items.0.size'))->toBe('First')
        ->and(RuleSet::from($rules)->check($data, ['items.*.size.numeric' => 'Second'])->errors()->first('items.0.size'))->toBe('Second')
        ->and(RuleSet::from($rules)->check($data)->errors()->first('items.0.size'))->toBe('The items.0.size field must be a number.');
});

it('reduces the rules again for the items of a later validation', function (): void {
    $rules = ['items' => FluentRule::array()->each([
        'type' => FluentRule::string()->required(),
        'size' => FluentRule::integer()->nullable()->requiredIf('items.*.type', 'big')->excludeUnless('items.*.type', 'big'),
    ])];

    expect(RuleSet::from($rules)->check(['items' => [['type' => 'small']]])->passes())->toBeTrue()
        ->and(RuleSet::from($rules)->check(['items' => [['type' => 'big']]])->errors()->keys())->toBe(['items.0.size'])
        ->and(RuleSet::from($rules)->check(['items' => [['type' => 'small', 'size' => 'x']]])->validated())->toBe(['items' => [['type' => 'small']]]);
});

it('keeps short dispatch values only, and at most 256 of them', function (): void {
    $plan = itemRulePlan(['type' => 'required|string', 'size' => 'nullable|exclude_unless:type,big|integer']);
    $kept = static fn (): mixed => (new ReflectionProperty($plan, 'byDispatch'))->getValue($plan);
    $longValue = 'string:' . str_repeat('x', 200);

    $plan->forDispatch($longValue, ['type' => str_repeat('x', 200)]);

    expect($kept())
        ->toBeEmpty();

    for ($i = 1; $i <= 256; $i++) {
        $plan->forDispatch('string:v' . $i, ['type' => 'v' . $i]);
    }

    expect($kept())->toHaveCount(256);

    $plan->forDispatch('string:big', ['type' => 'big']);

    expect($kept())->toHaveCount(1)->toHaveKey('string:big');
});
