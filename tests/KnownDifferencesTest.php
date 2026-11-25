<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Validation\ValidationException;
use SanderMuller\FluentValidation\FluentFormRequest;
use SanderMuller\FluentValidation\RuleSet;
use SanderMuller\FluentValidation\Tests\Fixtures\DeclaresFluentRules;
use SanderMuller\FluentValidation\Tests\Fixtures\ItemFieldsFluentValidator;
use SanderMuller\FluentValidation\Tests\Fixtures\MixedItemsFluentFormRequest;
use SanderMuller\FluentValidation\Tests\Fixtures\RequiredIfBeforeExcludeFluentFormRequest;

// =========================================================================
// Known differences from Laravel. Each test pins the package's current
// result next to Laravel's. A change that removes a difference makes its
// test fail: then assert parity instead, as FixtureFormRequestParityTest does.
// =========================================================================

/**
 * @param  class-string<FluentFormRequest&DeclaresFluentRules>  $class
 * @param  array<string, mixed>  $data
 * @return array<array-key, mixed>
 */
function knownDifferenceRequestErrors(string $class, array $data): array
{
    $request = $class::createFrom(Request::create('/test', 'POST', $data));
    $request->setContainer(app());
    $request->setRedirector(resolve(Redirector::class));

    try {
        $request->validateResolved();
    } catch (ValidationException $validationException) {
        return $validationException->errors();
    }

    return [];
}

/**
 * @param  class-string<FluentFormRequest&DeclaresFluentRules>  $class
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function knownDifferenceNativeErrors(string $class, array $data, bool $stopOnFirstFailure = false): array
{
    $validator = validator($data, RuleSet::compile(RuleSet::from($class::fluentRules())->flattenRules()));
    $validator->stopOnFirstFailure($stopOnFirstFailure);
    $validator->passes();

    return $validator->errors()->toArray();
}

it('skips a required_if error when the exclude rule after it fires', function (): void {
    $data = ['items' => [['type' => 'link']]];
    $rules = RequiredIfBeforeExcludeFluentFormRequest::fluentRules();

    expect(knownDifferenceNativeErrors(RequiredIfBeforeExcludeFluentFormRequest::class, $data))
        ->toBe(['items.0.email' => ['The items.0.email field is required when items.0.type is link.']])
        ->and(RuleSet::from($rules)->check($data)->errors()->toArray())
        ->toBeEmpty()
        ->and(knownDifferenceRequestErrors(RequiredIfBeforeExcludeFluentFormRequest::class, $data))
        ->toBeEmpty();
});

it('reports item errors in item order, where Laravel puts non-empty arrays last', function (): void {
    $data = ['items' => ['x', [1], 'y']];
    $inItemOrder = [
        'items.0' => ['The items.0 field must be a number.', 'The items.0 field must be an integer.'],
        'items.1' => ['The items.1 field must be a number.', 'The items.1 field must be an integer.'],
        'items.2' => ['The items.2 field must be a number.', 'The items.2 field must be an integer.'],
    ];

    expect(array_keys(knownDifferenceNativeErrors(MixedItemsFluentFormRequest::class, $data)))->toBe(['items.0', 'items.2', 'items.1'])
        ->and(RuleSet::from(MixedItemsFluentFormRequest::fluentRules())->check($data)->errors()->toArray())->toBe($inItemOrder)
        ->and(knownDifferenceRequestErrors(MixedItemsFluentFormRequest::class, $data))->toBe($inItemOrder);
});

it('reports another first error with stopOnFirstFailure when items mix arrays and scalars', function (): void {
    $data = ['items' => [[1], 'x']];

    expect(array_keys(knownDifferenceNativeErrors(MixedItemsFluentFormRequest::class, $data, stopOnFirstFailure: true)))->toBe(['items.1'])
        ->and(array_keys(RuleSet::from(MixedItemsFluentFormRequest::fluentRules())->stopOnFirstFailure()->check($data)->errors()->toArray()))->toBe(['items.0']);
});

it('keeps unvalidated item keys in FluentValidator, which the factory default leaves out', function (): void {
    $data = ['items' => [['a' => 1, 'junk' => 2]]];
    $validator = new ItemFieldsFluentValidator($data);
    $native = validator($data, RuleSet::compile(RuleSet::from(['items' => 'required|array', 'items.*.a' => 'required|integer'])->flattenRules()));

    expect($native->validated())->toBe(['items' => [['a' => 1]]])
        ->and($validator->validated())->toBe(['items' => [['a' => 1, 'junk' => 2]]]);
});
