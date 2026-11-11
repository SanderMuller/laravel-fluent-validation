<?php declare(strict_types=1);

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\OptimizedValidator;
use SanderMuller\FluentValidation\Rules\BooleanRule;
use SanderMuller\FluentValidation\Rules\Internal\Boolean;
use SanderMuller\FluentValidation\RuleSet;

dataset('boolean words that pass', [
    'string true' => ['true'],
    'string false' => ['false'],
    'yes' => ['yes'],
    'no' => ['no'],
    'on' => ['on'],
    'off' => ['off'],
    'string 1' => ['1'],
    'string 0' => ['0'],
    'int 1' => [1],
    'int 0' => [0],
    'bool true' => [true],
    'bool false' => [false],
]);

dataset('values that fail', [
    'uppercase TRUE' => ['TRUE'],
    'capitalised Yes' => ['Yes'],
    'padded yes' => [' yes'],
    'maybe' => ['maybe'],
    'string 2' => ['2'],
    'int 2' => [2],
    'float 1.0' => [1.0],
    'array' => [['true']],
]);

it('replaces the boolean base rule instead of adding a rule next to it', function (): void {
    $compiled = FluentRule::boolean()->required()->allowWords()->compiledRules();

    expect($compiled)->toBeArray()
        ->and($compiled[0])->toBe('required')
        ->and($compiled[1])->toBeInstanceOf(Boolean::class)
        ->and($compiled)->not->toContain('boolean');
});

it('reports that it cannot compile to a pipe string', function (): void {
    expect(FluentRule::boolean()->canCompile())->toBeTrue()
        ->and(FluentRule::boolean()->allowWords()->canCompile())->toBeFalse();
});

it('passes boolean words through the rule object', function (mixed $value): void {
    expect(makeValidator(['flag' => $value], ['flag' => FluentRule::boolean()->allowWords()])->passes())->toBeTrue();
})->with('boolean words that pass');

it('passes boolean words through RuleSet', function (mixed $value): void {
    $result = RuleSet::from(['flag' => FluentRule::boolean()->required()->allowWords()])->check(['flag' => $value]);

    expect($result->passes())->toBeTrue()
        ->and($result->safe()->boolean('flag'))->toBe(filter_var($value, FILTER_VALIDATE_BOOLEAN));
})->with('boolean words that pass');

it('passes boolean words in each() items', function (mixed $value): void {
    $result = RuleSet::from([
        'items' => FluentRule::array()->required()->each(FluentRule::boolean()->required()->allowWords()),
    ])->check(['items' => [$value, $value]]);

    expect($result->passes())->toBeTrue();
})->with('boolean words that pass');

it('rejects other values through the rule object', function (mixed $value): void {
    expect(makeValidator(['flag' => $value], ['flag' => FluentRule::boolean()->allowWords()])->passes())->toBeFalse();
})->with('values that fail');

it('rejects other values through RuleSet', function (mixed $value): void {
    expect(RuleSet::from(['flag' => FluentRule::boolean()->required()->allowWords()])->check(['flag' => $value])->fails())->toBeTrue();
})->with('values that fail');

it('rejects other values in each() items', function (mixed $value): void {
    $result = RuleSet::from([
        'items' => FluentRule::array()->required()->each(FluentRule::boolean()->required()->allowWords()),
    ])->check(['items' => [true, $value]]);

    expect($result->fails())->toBeTrue()
        ->and($result->errors()->keys())->toBe(['items.1']);
})->with('values that fail');

it('keeps rejecting words without allowWords()', function (): void {
    expect(RuleSet::from(['flag' => FluentRule::boolean()->required()])->check(['flag' => 'true'])->fails())->toBeTrue();
});

it('keeps the Laravel meaning of accepted() and declined()', function (): void {
    $accepted = FluentRule::boolean()->allowWords()->accepted();
    $declined = FluentRule::boolean()->allowWords()->declined();

    expect(RuleSet::from(['flag' => $accepted])->check(['flag' => 'on'])->passes())->toBeTrue()
        ->and(RuleSet::from(['flag' => $accepted])->check(['flag' => 'off'])->fails())->toBeTrue()
        ->and(RuleSet::from(['flag' => $declined])->check(['flag' => 'no'])->passes())->toBeTrue()
        ->and(RuleSet::from(['flag' => $declined])->check(['flag' => 'yes'])->fails())->toBeTrue();
});

it('gives the same result when allowWords() comes after accepted()', function (): void {
    $rule = FluentRule::boolean()->accepted()->allowWords();

    expect(RuleSet::from(['flag' => $rule])->check(['flag' => 'yes'])->passes())->toBeTrue()
        ->and(RuleSet::from(['flag' => $rule])->check(['flag' => 'no'])->fails())->toBeTrue();
});

it('allows null when nullable', function (): void {
    expect(RuleSet::from(['flag' => FluentRule::boolean()->nullable()->allowWords()])->check(['flag' => null])->passes())->toBeTrue()
        ->and(RuleSet::from(['flag' => FluentRule::boolean()->allowWords()->nullable()])->check(['flag' => null])->passes())->toBeTrue();
});

it('rejects null when required', function (): void {
    expect(RuleSet::from(['flag' => FluentRule::boolean()->required()->allowWords()])->check(['flag' => null])->fails())->toBeTrue();
});

it('skips an empty string like other non-implicit rules', function (): void {
    expect(RuleSet::from(['flag' => FluentRule::boolean()->allowWords()])->check(['flag' => ''])->passes())->toBeTrue()
        ->and(RuleSet::from(['flag' => FluentRule::boolean()->required()->allowWords()])->check(['flag' => ''])->fails())->toBeTrue();
});

it('uses the boolean translation and the label', function (): void {
    $result = RuleSet::from(['flag' => FluentRule::boolean('Newsletter')->allowWords()])->check(['flag' => 'maybe']);

    expect($result->errors()->first('flag'))->toBe('The Newsletter field must be true or false.');
});

it('keeps custom messages bound to the boolean rule', function (string $expected, BooleanRule $rule): void {
    expect(RuleSet::from(['flag' => $rule])->check(['flag' => 'maybe'])->errors()->first('flag'))->toBe($expected);
})->with([
    'factory message' => ['Factory', fn () => FluentRule::boolean(message: 'Factory')->allowWords()],
    'message() before allowWords()' => ['Chained', fn () => FluentRule::boolean()->message('Chained')->allowWords()],
    'message() after allowWords()' => ['After', fn () => FluentRule::boolean()->allowWords()->message('After')],
    'messageFor boolean' => ['For', fn () => FluentRule::boolean()->allowWords()->messageFor('boolean', 'For')],
]);

it('uses a flag.boolean entry from a plain messages array', function (): void {
    $validator = ValidatorFacade::make(
        ['flag' => 'maybe'],
        RuleSet::compile(['flag' => FluentRule::boolean()->allowWords()]),
        ['flag.boolean' => 'From messages'],
    );

    expect($validator->errors()->first('flag'))->toBe('From messages');
});

it('keeps the error order of the plain boolean rule', function (): void {
    $plain = RuleSet::from(['flag' => FluentRule::boolean()->accepted()])->check(['flag' => 'maybe']);
    $words = RuleSet::from(['flag' => FluentRule::boolean()->allowWords()->accepted()])->check(['flag' => 'maybe']);

    expect($words->errors()->get('flag'))->toBe($plain->errors()->get('flag'));
});

it('lets messageFor boolean win over fieldMessage, as for the plain rule', function (): void {
    $rule = FluentRule::boolean()->allowWords()->fieldMessage('Field')->messageFor('boolean', 'Rule');

    expect(RuleSet::from(['flag' => $rule])->check(['flag' => 'maybe'])->errors()->first('flag'))->toBe('Rule');
});

it('ignores a global boolean key in a messages array', function (): void {
    $result = RuleSet::from(['flag' => FluentRule::boolean()->allowWords()])->check(['flag' => 'maybe'], ['boolean' => 'Global']);

    expect($result->errors()->first('flag'))->toBe('The flag field must be true or false.');
});

it('ignores a validation.custom lang line for the boolean rule', function (): void {
    expect(__('validation.boolean'))->not->toBe('validation.boolean');
    resolve(Translator::class)->addLines(['validation.custom.flag.boolean' => 'From lang'], 'en');

    $result = RuleSet::from(['flag' => FluentRule::boolean()->allowWords()])->check(['flag' => 'maybe']);

    expect($result->errors()->first('flag'))->toBe('The flag field must be true or false.');
});

it('drops a cached compile when allowWords() comes after compiledRules()', function (): void {
    $rule = FluentRule::boolean();
    $rule->compiledRules();

    expect(RuleSet::from(['flag' => $rule->allowWords()])->check(['flag' => 'yes'])->passes())->toBeTrue();
});

it('lets a bare field key win over flag.boolean in a messages array', function (): void {
    $result = RuleSet::from(['flag' => FluentRule::boolean()->allowWords()])
        ->check(['flag' => 'maybe'], ['flag' => 'Field', 'flag.boolean' => 'Rule']);

    expect($result->errors()->first('flag'))->toBe('Field');
});

it('compares the raw value in a conditional rule on another field', function (mixed $value, bool $required): void {
    $result = RuleSet::from([
        'flag' => FluentRule::boolean()->allowWords(),
        'reason' => FluentRule::string()->requiredIf('flag', true),
    ])->check(['flag' => $value]);

    expect($result->fails())->toBe($required);
})->with([
    'string 1' => ['1', true],
    'string true' => ['true', false],
    'on' => ['on', false],
]);

it('validates boolean words through a FormRequest with the optimized validator', function (): void {
    $rules = [
        'names' => FluentRule::array()->required()->each(FluentRule::string()->required()),
        'items' => FluentRule::array()->required()->each(FluentRule::boolean()->required()->allowWords()),
        'flag' => FluentRule::boolean()->required()->allowWords()->messageFor('boolean', 'Flag message'),
    ];
    $validate = function (array $data) use ($rules): Validator {
        $formRequest = createFormRequest(rules: $rules, data: $data);

        return (fn () => $this->createDefaultValidator(resolve(Factory::class)))->call($formRequest);
    };

    $passing = $validate(['names' => ['a'], 'items' => ['yes', 'off'], 'flag' => 'true']);
    $failing = $validate(['names' => ['a'], 'items' => ['yes', 'maybe'], 'flag' => 'maybe']);

    expect($passing)->toBeInstanceOf(OptimizedValidator::class)
        ->and($passing->fails())->toBeFalse()
        ->and($passing->validated())->toEqual(['names' => ['a'], 'items' => ['yes', 'off'], 'flag' => 'true'])
        ->and($failing->fails())->toBeTrue()
        ->and($failing->errors()->keys())->toEqualCanonicalizing(['items.1', 'flag'])
        ->and($failing->errors()->first('flag'))->toBe('Flag message');
});

it('applies a custom message and label to each() items', function (): void {
    $result = RuleSet::from([
        'items' => FluentRule::array()->required()->each(FluentRule::boolean('Item')->allowWords()->messageFor('boolean', 'Item message')),
        'labels' => FluentRule::array()->required()->each(FluentRule::boolean('Item')->allowWords()),
    ])->check(['items' => ['maybe'], 'labels' => ['maybe']]);

    expect($result->errors()->first('items.0'))->toBe('Item message')
        ->and($result->errors()->first('labels.0'))->toBe('The Item field must be true or false.');
});

it('applies custom messages on the rule-object path', function (): void {
    $validator = ValidatorFacade::make(
        ['flag' => 'maybe'],
        ['flag' => FluentRule::boolean()->allowWords()->fieldMessage('Field')->messageFor('boolean', 'Rule')],
    );

    expect($validator->errors()->first('flag'))->toBe('Rule');
});

it('has no effect inside a whenInput() branch', function (): void {
    $rule = FluentRule::boolean()->whenInput(fn (): bool => true, fn (BooleanRule $rule): BooleanRule => $rule->allowWords());

    expect(RuleSet::from(['flag' => $rule])->check(['flag' => 'yes'])->fails())->toBeTrue();
});
