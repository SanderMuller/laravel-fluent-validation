<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Validation\ValidationException;
use SanderMuller\FluentValidation\FluentFormRequest;
use SanderMuller\FluentValidation\RuleSet;
use SanderMuller\FluentValidation\Tests\Fixtures\DeclaresFluentRules;
use SanderMuller\FluentValidation\Tests\Fixtures\ItemFieldsFluentValidator;

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

it('keeps unvalidated item keys in FluentValidator, which the factory default leaves out', function (): void {
    $data = ['items' => [['a' => 1, 'junk' => 2]]];
    $validator = new ItemFieldsFluentValidator($data);
    $native = validator($data, RuleSet::compile(RuleSet::from(['items' => 'required|array', 'items.*.a' => 'required|integer'])->flattenRules()));

    expect($native->validated())->toBe(['items' => [['a' => 1]]])
        ->and($validator->validated())->toBe(['items' => [['a' => 1, 'junk' => 2]]]);
});

it('keeps the field order of the input inside an item in RuleSet, where Laravel uses rule order', function (): void {
    $rules = ['items.*.b' => 'string', 'items.*.a' => 'string'];
    $data = ['items' => [['a' => 'x', 'b' => 'y']]];

    expect(validator($data, $rules)->validated())->toBe(['items' => [['b' => 'y', 'a' => 'x']]])
        ->and(RuleSet::from($rules)->check($data)->validated())->toBe(['items' => [['a' => 'x', 'b' => 'y']]]);
});
