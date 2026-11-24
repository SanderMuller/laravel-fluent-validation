<?php declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use SanderMuller\FluentValidation\FluentValidator;
use SanderMuller\FluentValidation\Internal\ConditionalEvaluationPhase;

/**
 * @return array<string, mixed>
 */
function presenceFastCheckResult(Illuminate\Validation\Validator $validator): array
{
    return $validator->fails() ? ['errors' => $validator->errors()->toArray()] : ['validated' => $validator->validated()];
}

/** @return array<string, array{array<string, mixed>, array<string, mixed>}> */
function presenceFastCheckCases(): array
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
        'required_unless' => [['items' => $items], ['items.*.type' => 'nullable|string', 'items.*.url' => ['bail', ['exclude_unless', 'items.*.type', 'image', 'hotspot'], ['required_unless', 'items.*.type', 'hotspot'], 'nullable', 'string']]],
        'prohibited_if' => [['items' => $items], ['items.*.type' => 'nullable|string', 'items.*.url' => [['exclude_unless', 'items.*.type', 'image', 'hotspot'], ['prohibited_if', 'items.*.type', 'hotspot'], 'nullable', 'string']]],
        'prohibited_unless' => [['items' => $items], ['items.*.type' => 'nullable|string', 'items.*.url' => [['exclude_unless', 'items.*.type', 'image', 'hotspot'], 'prohibited_unless:items.*.type,hotspot', 'nullable', 'string']]],
        'prohibited_if with an empty value' => [
            ['items' => [['type' => 'a', 'url' => ''], ['type' => 'a', 'url' => []], ['type' => 'a', 'url' => '0']]],
            ['items.*.type' => 'string', 'items.*.url' => [['exclude_unless', 'items.*.type', 'a'], ['prohibited_if', 'items.*.type', 'a']]],
        ],
        'a missing dependent for each rule' => [
            ['items' => [['url' => null], ['url' => 'x']]],
            ['items.*.url' => [['exclude_unless', 'items.*.type', 'null'], ['required_unless', 'items.*.type', 'null'], ['prohibited_if', 'items.*.type', 'a'], ['prohibited_unless', 'items.*.type', 'null'], 'nullable', 'string']],
        ],
        'a dependent outside the item' => [
            ['mode' => 'strict', 'items' => [['type' => 'a', 'url' => null], ['type' => 'a', 'url' => 'x']]],
            ['mode' => 'string', 'items.*.type' => 'string', 'items.*.url' => [['exclude_unless', 'items.*.type', 'a'], ['required_if', 'mode', 'strict'], 'nullable', 'string']],
        ],
    ];
}

it('matches Laravel for a value-conditional rule next to an exclude rule', function (string $case): void {
    [$data, $rules] = presenceFastCheckCases()[$case];
    $validator = new class ($data, $rules) extends FluentValidator {};
    $validator->excludeUnvalidatedArrayKeys = true;

    expect(presenceFastCheckResult($validator))->toBe(presenceFastCheckResult(Validator::make($data, $rules)));
})->with(array_keys(presenceFastCheckCases()));

it("leaves passing attributes with a value-conditional rule out of Laravel's loop", function (mixed $conditional, string $failing): void {
    $data = ['items' => [['type' => 'image', 'url' => 'a.png'], ['type' => 'hotspot', 'url' => null], ['type' => 'image', 'url' => null]]];
    $rules = ['items.*.type' => 'string', 'items.*.url' => ['bail', ['exclude_unless', 'items.*.type', 'image', 'hotspot'], $conditional, 'nullable', 'string']];

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
        ->and(array_values(array_unique($validator->validatedByLaravel)))->toBe([$failing]);
})->with([
    'a tuple' => [['required_if', 'items.*.type', 'image'], 'items.2.url'],
    'a string' => ['required_if:items.*.type,image', 'items.2.url'],
    'required_unless' => ['required_unless:items.*.type,hotspot', 'items.2.url'],
    'prohibited_unless' => [['prohibited_unless', 'items.*.type', 'hotspot'], 'items.0.url'],
]);

it('resolves a value-conditional rule for the attribute as Laravel does', function (string $name, array $parameters, ?string $expected, string $attribute = 'items.0.url'): void {
    $data = ['flag' => null, 'items' => [['type' => 'image', 'on' => '1']]];
    $rules = ['items.0.on' => ['boolean']];

    expect(ConditionalEvaluationPhase::presenceRule($name, $attribute, $parameters, $data, $rules))->toBe($expected);
})->with([
    'an active required_if rule' => ['required_if', ['items.*.type', 'video', 'image'], 'required'],
    'an inactive required_if rule' => ['required_if', ['items.*.type', 'video'], ''],
    'an active required_unless rule' => ['required_unless', ['items.*.type', 'video'], 'required'],
    'an inactive required_unless rule' => ['required_unless', ['items.*.type', 'image'], ''],
    'an active prohibited_if rule' => ['prohibited_if', ['items.*.type', 'image'], 'prohibited'],
    'an inactive prohibited_if rule' => ['prohibited_if', ['items.*.type', 'video'], ''],
    'an active prohibited_unless rule' => ['prohibited_unless', ['items.*.type', 'video'], 'prohibited'],
    'an inactive prohibited_unless rule' => ['prohibited_unless', ['items.*.type', 'image'], ''],
    'required_if with a missing dependent' => ['required_if', ['items.*.missing', 'null'], ''],
    'required_unless with a missing dependent' => ['required_unless', ['items.*.missing', 'a'], 'required'],
    'prohibited_if with a missing dependent' => ['prohibited_if', ['items.*.missing', 'null'], 'prohibited'],
    'a null dependent' => ['required_if', ['flag', 'null'], 'required'],
    'a boolean dependent' => ['required_if', ['items.*.on', 'true'], null],
    'a wildcard that the attribute does not resolve' => ['required_if', ['items.*.type', 'image'], null, 'top'],
    'an escaped dot' => ['required_if', ['items\\.x', 'image'], null],
    'no value' => ['required_if', ['items.*.type'], null],
    'a value that is not a string' => ['required_if', ['items.*.type', true], null],
    'another rule' => ['required_with', ['items.*.type', 'image'], null],
]);
