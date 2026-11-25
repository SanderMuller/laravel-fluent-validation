<?php declare(strict_types=1);

use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Contracts\Validation\ParameterizedRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use SanderMuller\FluentValidation\FluentFormRequest;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\Internal\PrevalidatedValidator;
use SanderMuller\FluentValidation\Internal\RuleSetFirstGate;
use SanderMuller\FluentValidation\OptimizedValidator;
use SanderMuller\FluentValidation\RuleSet;

/**
 * @param  array<array-key, mixed>  $data
 */
function ruleSetFirstRequest(FormRequest $request, array $data): FormRequest
{
    $instance = $request::createFrom(Request::create('/test', 'POST', $data));
    $instance->setContainer(app());
    $instance->setRedirector(resolve(Redirector::class));

    return $instance;
}

function ruleSetFirstValidator(FormRequest $request): Validator
{
    $factory = resolve(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($request);

    if (! $validator instanceof Validator) {
        throw new RuntimeException('The form request made no Laravel validator.');
    }

    return $validator;
}

/**
 * @param  array<string, mixed>  $rules
 * @param  array<string, mixed>  $data
 */
function plainRuleSetFirstRequest(array $rules, array $data): FormRequest
{
    RuleSetFirstRequest::$testRules = $rules;

    return ruleSetFirstRequest(new RuleSetFirstRequest(), $data);
}

final class RuleSetFirstRequest extends FluentFormRequest
{
    /** @var array<string, mixed> */
    public static array $testRules = [];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::$testRules;
    }
}

it('returns the validated data of Laravel when the request passes in RuleSet first', function (): void {
    $rules = ['items.*.b' => 'string', 'items.*.a' => 'string', 'name' => 'string'];
    // Item 1 has no `b`, so Laravel puts it after item 2.
    $data = ['name' => 'n', 'items' => [['b' => 'x', 'a' => 'y', 'extra' => 1], ['a' => 'z'], ['b' => 'w']]];
    $request = plainRuleSetFirstRequest($rules, $data);

    $request->validateResolved();

    $native = validator($data, $rules)->validated();

    expect((fn () => $this->validator)->call($request)::class)->toBe(PrevalidatedValidator::class)
        ->and($request->validated())->toBe($native)
        ->and($request->safe()->all())->toBe($native)
        ->and($request->validated('items.0.a'))->toBe('y');
});

it('gives the rules of the full path after a pass in RuleSet first', function (): void {
    $rules = ['items' => FluentRule::array()->required()->each(['name' => FluentRule::string()->required()])];
    $data = ['items' => [['name' => 'a'], ['name' => 'b']]];

    $validator = ruleSetFirstValidator(plainRuleSetFirstRequest($rules, $data));

    expect($validator::class)->toBe(PrevalidatedValidator::class)
        ->and($validator->passes())->toBeTrue()
        ->and(array_keys($validator->getRules()))->toBe(['items', 'items.0.name', 'items.1.name']);
});

it('takes the full path when the request fails in RuleSet', function (): void {
    $validator = ruleSetFirstValidator(plainRuleSetFirstRequest(['items.*.name' => 'string|min:3'], ['items' => [['name' => 'ab']]]));

    expect($validator::class)->toBe(OptimizedValidator::class)
        ->and($validator->errors()->keys())->toBe(['items.0.name']);
});

/** @return array<string, array<string, mixed>> */
function ruleSetFirstFullPathRules(): array
{
    return [
        'an exclude rule after another rule' => ['items.*.name' => ['string', 'exclude_unless:items.*.type,b']],
        'an exclude rule after required_if' => ['items.*.name' => ['required_if:items.*.type,a', 'exclude_unless:items.*.type,b']],
        'an exclude_with rule after another rule' => ['items.*.name' => 'string|exclude_with:items.*.type'],
        'an exists rule' => ['items.*.name' => 'string|exists:users,name'],
        'a rule that reads the data' => ['items.*.name' => ['string', new class implements DataAwareRule, ValidationRule {
            /** @param  array<mixed>  $data */
            public function setData(array $data): static
            {
                return $this;
            }

            public function validate(string $attribute, mixed $value, Closure $fail): void {}
        }]],
    ];
}

it('takes the full path for a rule that a pass in RuleSet does not cover', function (string $case): void {
    $validator = ruleSetFirstValidator(plainRuleSetFirstRequest(ruleSetFirstFullPathRules()[$case], ['items' => [['type' => 'a', 'name' => 'xyz']]]));

    expect($validator::class)->not->toBe(PrevalidatedValidator::class);
})->with(array_keys(ruleSetFirstFullPathRules()));

/**
 * @return array<string, array{array<string, mixed>, array<string, mixed>}>
 */
function ruleSetFirstBareReferenceCases(): array
{
    $cases = [
        'required_without' => [['addresses.*.postcode' => 'required_without:birthdate|string'], ['addresses' => [['birthdate' => '1990-01-01']]]],
        'required_without in each()' => [['addresses' => FluentRule::array()->each(['postcode' => FluentRule::string()->requiredWithout('birthdate')])], ['addresses' => [['birthdate' => '1990-01-01']]]],
        'required_without_all' => [['addresses.*.postcode' => 'required_without_all:birthdate,age|string'], ['addresses' => [['birthdate' => '1990-01-01', 'age' => 30]]]],
        'same' => [['items.*.code' => 'same:code'], ['items' => [['code' => 'x']]]],
        'same as a tuple' => [['items.*.code' => [['same', 'code']]], ['items' => [['code' => 'x']]]],
        'same as a tuple with an integer field' => [['items.*.code' => [['same', 0]]], ['items' => [['code' => 'x', 0 => 'x']]]],
        'same as a tuple with a boolean field' => [['items.*.code' => [['same', true]]], ['items' => [['code' => 'x', 1 => 'x']]]],
        'same as a tuple with a null field' => [['items.*.code' => [['same', null]]], ['items' => [['code' => 'x', '' => 'x']]]],
        'same as a tuple with a stringable field' => [['items.*.code' => [['same', new class implements Stringable {
            public function __toString(): string
            {
                return 'code';
            }
        }]]], ['items' => [['code' => 'x']]]],
        'after a literal date that names a field with a date format' => [['items.*.end' => 'date_format:Y|after:2020', 'items.*.2020' => 'date_format:Hi'], ['items' => [['end' => '2000']]]],
        'before in a date rule object' => [['items.*.end' => [Rule::date()->before('start')]], ['items' => [['start' => '2030-01-01', 'end' => '2025-01-01']]]],
    ];

    // Laravel 12 may not have this interface.
    if (interface_exists(ParameterizedRule::class)) {
        $cases['same as a parameterized rule'] = [['items.*.code' => [new class implements ParameterizedRule {
            /** @return list<string> */
            public function toArray(): array
            {
                return ['same', 'code'];
            }

            public function __toString(): string
            {
                return 'same:code';
            }
        }]], ['items' => [['code' => 'x']]]];
    }

    return $cases;
}

it('reads a bare name in a wildcard rule as the top-level field, as Laravel does', function (string $case): void {
    [$rules, $data] = ruleSetFirstBareReferenceCases()[$case];
    $validator = ruleSetFirstValidator(plainRuleSetFirstRequest($rules, $data));

    expect($validator->errors()->toArray())
        ->not->toBeEmpty()
        ->toBe(validator($data, RuleSet::from($rules)->toArray())->errors()->toArray());
})->with(array_keys(ruleSetFirstBareReferenceCases()));

it('runs a rule callback as often as Laravel does', function (): void {
    $rules = static function (): array {
        $calls = 0;

        return ['items.*.code' => [Rule::excludeIf(static function () use (&$calls): bool {
            return ++$calls > 1;
        }), 'string']];
    };
    $data = ['items' => [['code' => 'x']]];

    expect(ruleSetFirstValidator(plainRuleSetFirstRequest($rules(), $data))->validated())
        ->toBe(validator($data, $rules())->validated());
});

it('leaves a custom rule object that Laravel reads as a string to Laravel', function (): void {
    $rules = static fn (): array => ['items.*.code' => [new class implements Stringable {
        private int $calls = 0;

        public function __toString(): string
        {
            return ++$this->calls === 1 ? 'same:code' : 'string';
        }
    }]];
    $data = ['items' => [['code' => 'x']]];

    expect(ruleSetFirstValidator(plainRuleSetFirstRequest($rules(), $data))->errors()->toArray())
        ->not->toBeEmpty()
        ->toBe(validator($data, $rules())->errors()->toArray());
});

it('leaves a custom parameterized rule to Laravel', function (): void {
    $rules = static fn (): array => ['items.*.code' => [new class implements ParameterizedRule {
        private int $calls = 0;

        /** @return list<string> */
        public function toArray(): array
        {
            return ++$this->calls === 1 ? ['same', 'code'] : ['string'];
        }

        public function __toString(): string
        {
            return 'same:code';
        }
    }]];
    $data = ['items' => [['code' => 'x']]];

    expect(ruleSetFirstValidator(plainRuleSetFirstRequest($rules(), $data))->errors()->toArray())
        ->not->toBeEmpty()
        ->toBe(validator($data, $rules())->errors()->toArray());
})->skip(! interface_exists(ParameterizedRule::class), 'Laravel 12 may not have this interface.');

it('takes the path in RuleSet first for a wildcard reference and a literal date', function (): void {
    $rules = ['items.*.end' => ['date', 'after:items.*.start', 'before:2030-01-01', 'after:today'], 'items.*.start' => 'date|required_with:items.*.end'];

    expect(ruleSetFirstValidator(plainRuleSetFirstRequest($rules, ['items' => [['start' => '2029-01-01', 'end' => '2029-06-01']]]))::class)
        ->toBe(PrevalidatedValidator::class);
});

it('takes the path in RuleSet first for a literal date in the date format of the field', function (): void {
    $rules = ['items.*.end' => 'date_format:d/m/Y|before:31/12/2030'];

    expect(ruleSetFirstValidator(plainRuleSetFirstRequest($rules, ['items' => [['end' => '01/01/2030']]]))::class)
        ->toBe(PrevalidatedValidator::class);
});

it('leaves a database rule object to the full path', function (mixed $rule): void {
    expect(RuleSetFirstGate::allows(['items.*.name' => ['string', $rule]]))->toBeFalse()
        ->and(RuleSetFirstGate::allows(['items.*.name' => ['string']]))->toBeTrue();
})->with([
    'exists' => [Rule::exists('users', 'name')],
    'unique' => [Rule::unique('users', 'name')],
]);

it('takes the path in RuleSet first for an exclude rule after bail, nullable or sometimes', function (): void {
    $rules = ['items.*.name' => ['bail', 'nullable', 'sometimes', 'exclude_unless:items.*.type,b', 'string']];

    expect(ruleSetFirstValidator(plainRuleSetFirstRequest($rules, ['items' => [['type' => 'a', 'name' => 'xyz']]]))::class)
        ->toBe(PrevalidatedValidator::class);
});

it('takes the full path for a top-level wildcard', function (): void {
    $request = ruleSetFirstRequest(new class extends FluentFormRequest {
        /** @return array<string, mixed> */
        public function rules(): array
        {
            return ['*' => ['string']];
        }
    }, ['a', 'b']);

    expect(ruleSetFirstValidator($request)::class)->not->toBe(PrevalidatedValidator::class);
});

it('takes the full path when a hook of the request receives the validator', function (FormRequest $request): void {
    $request = ruleSetFirstRequest($request, ['items' => [['name' => 'xyz']]]);
    $request->validateResolved();

    expect((fn () => $this->validator)->call($request)::class)->not->toBe(PrevalidatedValidator::class);
})->with([
    'withValidator' => [new class extends FluentFormRequest {
        /** @return array<string, mixed> */
        public function rules(): array
        {
            return ['items.*.name' => ['string']];
        }

        public function withValidator(Validator $validator): void {}
    }],
    'after' => [new class extends FluentFormRequest {
        /** @return array<string, mixed> */
        public function rules(): array
        {
            return ['items.*.name' => ['string']];
        }

        /** @return list<Closure> */
        public function after(): array
        {
            return [];
        }
    }],
]);

it('fails on an unknown field after a pass in RuleSet first', function (bool $unknown): void {
    if (version_compare(app()->version(), '13', '<')) {
        $this->markTestSkipped('Laravel 12 has no failOnUnknownFields().');
    }

    FormRequest::failOnUnknownFields();

    try {
        $request = plainRuleSetFirstRequest(['items.*.name' => 'string'], ['items' => [['name' => 'xyz']], ...($unknown ? ['extra' => 1] : [])]);
        $errors = [];

        try {
            $request->validateResolved();
        } catch (ValidationException $validationException) {
            $errors = array_keys($validationException->errors());
        }
    } finally {
        FormRequest::failOnUnknownFields(false);
    }

    expect($errors)->toBe($unknown ? ['extra'] : []);
})->with(['an unknown field' => [true], 'no unknown field' => [false]]);
