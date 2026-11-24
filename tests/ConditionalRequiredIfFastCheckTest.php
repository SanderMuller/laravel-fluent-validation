<?php declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use SanderMuller\FluentValidation\FluentValidator;
use SanderMuller\FluentValidation\Internal\ConditionalEvaluationPhase;

/**
 * @return array<string, mixed>
 */
function requiredIfFastCheckResult(Illuminate\Validation\Validator $validator): array
{
    return $validator->fails() ? ['errors' => $validator->errors()->toArray()] : ['validated' => $validator->validated()];
}

/** @return array<string, array{array<string, mixed>, array<string, mixed>}> */
function requiredIfFastCheckCases(): array
{
    $imageUrl = ['bail', ['exclude_unless', 'items.*.type', 'image', 'hotspot'], ['required_if', 'items.*.type', 'image'], 'nullable', 'string'];
    $items = [
        ['type' => 'image', 'url' => 'a.png'],
        ['type' => 'hotspot', 'url' => null],
        ['type' => 'hotspot'],
        ['type' => 'image', 'url' => null],
        ['type' => 'image'],
        ['type' => 'image', 'url' => 5],
        ['type' => 'chapter', 'url' => 5],
        ['url' => 'b.png'],
    ];

    return [
        'a required_if tuple after an exclude rule' => [['items' => $items], ['items.*.type' => 'nullable|string', 'items.*.url' => $imageUrl]],
        'a required_if string after an exclude rule' => [['items' => $items], ['items.*.type' => 'nullable|string', 'items.*.url' => ['exclude_unless:items.*.type,image,hotspot', 'required_if:items.*.type,image', 'nullable', 'string']]],
        'a null dependent' => [
            ['items' => [['type' => null, 'url' => null], ['type' => 'image', 'url' => null]]],
            ['items.*.type' => 'nullable|string', 'items.*.url' => [['exclude_unless', 'items.*.type', 'image', 'null'], ['required_if', 'items.*.type', 'null'], 'nullable', 'string']],
        ],
        'a boolean dependent' => [
            ['items' => [['on' => '1', 'type' => 'a', 'url' => null], ['on' => '0', 'type' => 'a', 'url' => null]]],
            ['items.*.on' => 'boolean', 'items.*.type' => 'string', 'items.*.url' => [['exclude_unless', 'items.*.type', 'a'], ['required_if', 'items.*.on', 'true'], 'nullable', 'string']],
        ],
        'a dependent outside the item' => [
            ['mode' => 'strict', 'items' => [['type' => 'a', 'url' => null], ['type' => 'a', 'url' => 'x']]],
            ['mode' => 'string', 'items.*.type' => 'string', 'items.*.url' => [['exclude_unless', 'items.*.type', 'a'], ['required_if', 'mode', 'strict'], 'nullable', 'string']],
        ],
    ];
}

it('matches Laravel for a required_if rule next to an exclude rule', function (string $case): void {
    [$data, $rules] = requiredIfFastCheckCases()[$case];
    $validator = new class ($data, $rules) extends FluentValidator {};
    $validator->excludeUnvalidatedArrayKeys = true;

    expect(requiredIfFastCheckResult($validator))->toBe(requiredIfFastCheckResult(Validator::make($data, $rules)));
})->with(array_keys(requiredIfFastCheckCases()));

it("leaves passing attributes with a required_if rule out of Laravel's loop", function (mixed $requiredIf): void {
    $data = ['items' => [['type' => 'image', 'url' => 'a.png'], ['type' => 'hotspot', 'url' => null], ['type' => 'image', 'url' => null]]];
    $rules = ['items.*.type' => 'string', 'items.*.url' => ['bail', ['exclude_unless', 'items.*.type', 'image', 'hotspot'], $requiredIf, 'nullable', 'string']];

    $validator = new class ($data, $rules) extends FluentValidator {
        /** @var list<string> */
        public array $validatedByLaravel = [];

        protected function validateAttribute($attribute, $rule): void
        {
            $this->validatedByLaravel[] = $attribute;
            parent::validateAttribute($attribute, $rule);
        }
    };

    expect($validator->fails())->toBeTrue()
        ->and(array_values(array_unique($validator->validatedByLaravel)))->toBe(['items.2.url']);
})->with([
    'a tuple' => [['required_if', 'items.*.type', 'image']],
    'a string' => ['required_if:items.*.type,image'],
]);

it('resolves a required_if rule for the attribute as Laravel does', function (array $parameters, ?string $expected, string $attribute = 'items.0.url'): void {
    $data = ['flag' => null, 'items' => [['type' => 'image', 'on' => '1']]];
    $rules = ['items.0.on' => ['boolean']];

    expect(ConditionalEvaluationPhase::requiredIfRule($attribute, $parameters, $data, $rules))->toBe($expected);
})->with([
    'an active rule' => [['items.*.type', 'video', 'image'], 'required'],
    'an inactive rule' => [['items.*.type', 'video'], ''],
    'a missing dependent' => [['items.*.missing', 'null'], ''],
    'a null dependent' => [['flag', 'null'], 'required'],
    'a boolean dependent' => [['items.*.on', 'true'], null],
    'a wildcard that the attribute does not resolve' => [['items.*.type', 'image'], null, 'top'],
    'an escaped dot' => [['items\\.x', 'image'], null],
    'no value' => [['items.*.type'], null],
    'a value that is not a string' => [['items.*.type', true], null],
]);
