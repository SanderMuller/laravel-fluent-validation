<?php declare(strict_types=1);

use SanderMuller\FluentValidation\Contracts\FluentRuleContract;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\RuleSet;

it('runs a presence rule object first when the rule validates itself, as the compiled rules do', function (Closure $rule, mixed $value): void {
    $data = ['field' => $value];

    expect(validator($data, ['field' => $rule()])->errors()->toArray())
        ->toBe(validator($data, RuleSet::compile(['field' => $rule()]))->errors()->toArray())
        ->toHaveKey('field');
})->with([
    'string' => [fn (): FluentRuleContract => FluentRule::string()->prohibitedIf(true)->min(5), 'abc'],
    'string with bail' => [fn (): FluentRuleContract => FluentRule::string()->bail()->prohibitedIf(true)->email(), 'nope'],
    'numeric' => [fn (): FluentRuleContract => FluentRule::numeric()->prohibitedIf(true)->min(5), 2],
    'date' => [fn (): FluentRuleContract => FluentRule::date()->prohibitedIf(true)->after('2030-01-01'), '2020-01-01'],
    'array' => [fn (): FluentRuleContract => FluentRule::array()->prohibitedIf(true)->min(3), ['a']],
    'boolean' => [fn (): FluentRuleContract => FluentRule::boolean()->prohibitedIf(true), 'x'],
    'accepted' => [fn (): FluentRuleContract => FluentRule::accepted()->prohibitedIf(true), 'no'],
    'field' => [fn (): FluentRuleContract => FluentRule::field()->prohibitedIf(true)->rule('integer'), 'x'],
    'requiredIf with a closure' => [fn (): FluentRuleContract => FluentRule::string()->requiredIf(fn (): bool => true)->min(5), ''],
]);
