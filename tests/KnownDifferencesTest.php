<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Validation\ValidationException;
use SanderMuller\FluentValidation\FluentFormRequest;
use SanderMuller\FluentValidation\RuleSet;
use SanderMuller\FluentValidation\Tests\Fixtures\DeclaresFluentRules;
use SanderMuller\FluentValidation\Tests\Fixtures\ItemFieldsFluentValidator;
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

it('keeps unvalidated item keys in FluentValidator, which the factory default leaves out', function (): void {
    $data = ['items' => [['a' => 1, 'junk' => 2]]];
    $validator = new ItemFieldsFluentValidator($data);
    $native = validator($data, RuleSet::compile(RuleSet::from(['items' => 'required|array', 'items.*.a' => 'required|integer'])->flattenRules()));

    expect($native->validated())->toBe(['items' => [['a' => 1]]])
        ->and($validator->validated())->toBe(['items' => [['a' => 1, 'junk' => 2]]]);
});
