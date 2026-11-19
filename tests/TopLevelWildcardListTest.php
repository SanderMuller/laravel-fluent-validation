<?php declare(strict_types=1);

use Illuminate\Contracts\Validation\Factory;
use SanderMuller\FluentValidation\Contracts\FluentRuleContract;
use SanderMuller\FluentValidation\FluentRule;

it('validates a form request whose body is a list with a top-level wildcard rule', function (array $data, array $errors): void {
    $formRequest = createFormRequest(rules: ['*' => FluentRule::array()->children([
        'title' => FluentRule::string('Title')->required()->max(255),
        'sort_order' => FluentRule::integer()->required()->min(0),
    ])], data: $data);

    $validator = (fn () => $this->createDefaultValidator(resolve(Factory::class)))->call($formRequest);

    expect($validator->errors()->toArray())->toBe($errors);
})->with([
    'valid' => [[['title' => 'X', 'sort_order' => 1]], []],
    'invalid' => [[['title' => 'X', 'sort_order' => -1], ['sort_order' => 2]], [
        '1.title' => ['The Title field is required.'],
        '0.sort_order' => ['The 0.sort_order field must be at least 0.'],
    ]],
]);

it('validates a list body for every FluentRule shape on a top-level wildcard', function (Closure $rule, array $data, array $errorKeys): void {
    $formRequest = createFormRequest(rules: ['*' => $rule()], data: $data);

    $validator = (fn () => $this->createDefaultValidator(resolve(Factory::class)))->call($formRequest);

    expect($validator->errors()->keys())->toBe($errorKeys);
})->with([
    'children() with a list of arrays' => [fn (): FluentRuleContract => FluentRule::array()->children(['t' => FluentRule::string()]), [['t' => 'x']], []],
    'children() with a list of strings' => [fn (): FluentRuleContract => FluentRule::array()->children(['t' => FluentRule::string()]), ['a', 'b'], [0, 1]],
    'each() with a list of arrays' => [fn (): FluentRuleContract => FluentRule::array()->each(FluentRule::string()), [['x']], []],
    'each() with a list of strings' => [fn (): FluentRuleContract => FluentRule::array()->each(FluentRule::string()), ['a', 'b'], [0, 1]],
    'array() with a list of arrays' => [fn (): FluentRuleContract => FluentRule::array(), [['x']], []],
    'array() with a list of strings' => [fn (): FluentRuleContract => FluentRule::array(), ['a', 'b'], [0, 1]],
    'string() with a list of strings' => [fn (): FluentRuleContract => FluentRule::string()->required(), ['a', 'b'], []],
    'string() with a list of arrays' => [fn (): FluentRuleContract => FluentRule::string()->required(), [['t' => 'x']], [0]],
    'string() with a label and a list of strings' => [fn (): FluentRuleContract => FluentRule::string()->label('Item'), ['a'], []],
    'string() with a label and a list of arrays' => [fn (): FluentRuleContract => FluentRule::string()->label('Item'), [['t' => 'x']], [0]],
]);
