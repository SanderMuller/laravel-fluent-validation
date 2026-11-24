<?php declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use SanderMuller\FluentValidation\FluentValidator;

/**
 * @return array<string, mixed>
 */
function flatFastCheckResult(Illuminate\Validation\Validator $validator): array
{
    return $validator->fails() ? ['errors' => $validator->errors()->toArray()] : ['validated' => $validator->validated()];
}

/** @return array<string, array{array<string, mixed>, array<string, mixed>}> */
function flatFastCheckCases(): array
{
    $login = ['email' => 'required|string|email|max:255', 'password' => 'required|string|min:8', 'remember' => 'nullable|boolean'];

    return [
        'a valid login' => [['email' => 'user@example.com', 'password' => 'secure-password', 'remember' => true], $login],
        'an invalid login' => [['email' => 'not-an-email', 'password' => 'short', 'remember' => 'yes'], $login],
        'missing and null fields' => [['a' => null], ['a' => 'string', 'b' => 'string', 'c' => 'nullable|string', 'd' => 'integer', 'e' => 'required']],
        'a dotted key' => [['user' => ['name' => 'Ann', 'age' => 'x']], ['user' => 'array', 'user.name' => 'string|max:10', 'user.age' => 'integer']],
        'a flat field that another field reads' => [['min' => '3', 'max' => 2], ['min' => 'numeric', 'max' => 'numeric|gte:min']],
        'a flat field next to wildcard fields' => [['title' => 'x', 'items' => [['n' => 1], ['n' => 'y']]], ['title' => 'string|min:2', 'items' => 'array', 'items.*.n' => 'integer']],
    ];
}

/**
 * @param  array<string, mixed>  $data
 * @param  array<string, mixed>  $rules
 */
function expectFlatFastCheckParity(array $data, array $rules, ?Closure $change = null): void
{
    $validator = new class ($data, $rules) extends FluentValidator {};
    $validator->excludeUnvalidatedArrayKeys = true;

    $native = Validator::make($data, $rules);

    if ($change instanceof Closure) {
        $change($validator);
        $change($native);
    }

    expect(flatFastCheckResult($validator))->toBe(flatFastCheckResult($native));
}

it('matches Laravel for flat fields', function (string $case): void {
    expectFlatFastCheckParity(...flatFastCheckCases()[$case]);
})->with(array_keys(flatFastCheckCases()));

it('validates a rule that sometimes() adds after the fast checks were built', function (): void {
    expectFlatFastCheckParity(
        ['name' => 'cd', 'items' => [['n' => 'ab']]],
        ['name' => 'string', 'items' => 'array', 'items.*.n' => 'string'],
        static fn (Illuminate\Validation\Validator $validator) => $validator->sometimes(['name', 'items.0.n'], 'min:5', static fn (): bool => true),
    );
});

it("leaves passing flat fields out of Laravel's loop", function (): void {
    $validator = new class (['email' => 'user@example.com', 'password' => 'short'], ['email' => 'required|email', 'password' => 'required|string|min:8']) extends FluentValidator {
        /** @var list<string> */
        public array $validatedByLaravel = [];

        protected function validateAttribute($attribute, $rule): void
        {
            $this->validatedByLaravel[] = $attribute;
            parent::validateAttribute($attribute, $rule);
        }
    };

    expect($validator->fails())->toBeTrue()
        ->and(array_values(array_unique($validator->validatedByLaravel)))->toBe(['password']);
});
