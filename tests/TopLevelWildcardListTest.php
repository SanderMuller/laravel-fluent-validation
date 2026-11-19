<?php declare(strict_types=1);

use Illuminate\Contracts\Validation\Factory;
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
