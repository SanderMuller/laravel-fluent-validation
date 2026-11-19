<?php declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\RuleSet;

/**
 * expandWildcards() gives each path the rules that Laravel gives it.
 *
 * @param  array<string, mixed>  $rules
 * @param  array<string, mixed>  $data
 */
function expectNativeExpandedRules(array $rules, array $data): void
{
    $expanded = RuleSet::compile(RuleSet::from($rules)->expandWildcards($data));
    $normalized = array_map(static fn (mixed $rule): array => is_string($rule) ? explode('|', $rule) : (array) $rule, $expanded);

    expect($normalized)->toEqual(Validator::make($data, $rules)->getRules());
}

it('merges a wildcard rule into an explicit rule for the same path', function (): void {
    expectNativeExpandedRules(
        ['items.0.secret' => 'exclude', 'items.*.secret' => 'string'],
        ['items' => [['secret' => 'a'], ['secret' => 'b']]],
    );
});

it('keeps only an explicit rule that follows the wildcard', function (): void {
    expectNativeExpandedRules(
        ['items.*.secret' => 'string', 'items.0.secret' => 'exclude'],
        ['items' => [['secret' => 'a'], ['secret' => 'b']]],
    );
});

it('merges two wildcard rules for the same path', function (): void {
    expectNativeExpandedRules(
        ['items.*.secret' => 'string', 'items.0.*' => 'max:5'],
        ['items' => [['secret' => 'a'], ['secret' => 'b']]],
    );
});

it('leaves out an item field that an explicit item rule excludes', function (): void {
    $rules = ['items.0.secret' => 'exclude', 'items.*.secret' => 'string'];
    $data = ['items' => [['secret' => 'a'], ['secret' => 'b']]];

    expect(RuleSet::from($rules)->validate($data))
        ->toEqual(Validator::make($data, $rules)->validated());
});

it('merges a wildcard FluentRule into an explicit FluentRule as one rule list', function (): void {
    $expanded = RuleSet::from([
        'items.0.name' => FluentRule::string()->max(3),
        'items.*.name' => FluentRule::string()->nullable(),
    ])->expandWildcards(['items' => [['name' => null]]]);

    expect($expanded['items.0.name'])->toBe(['string', 'max:3', 'nullable', 'string'])
        ->and(validator(['items' => [['name' => null]]], $expanded)->passes())->toBeTrue();
});
