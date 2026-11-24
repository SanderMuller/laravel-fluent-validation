<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use SanderMuller\FluentValidation\FluentFormRequest;
use SanderMuller\FluentValidation\FluentValidator;
use SanderMuller\FluentValidation\RuleSet;
use SanderMuller\FluentValidation\Tests\Fixtures\DeclaresFluentRules;
use SanderMuller\FluentValidation\Tests\Fixtures\DispatchedFieldsFluentFormRequest;
use SanderMuller\FluentValidation\Tests\Fixtures\ExcludeAfterOtherRuleFluentFormRequest;
use SanderMuller\FluentValidation\Tests\Fixtures\ExcludedItemKeyFluentFormRequest;
use SanderMuller\FluentValidation\Tests\Fixtures\ExcludedItemWithTopErrorFluentFormRequest;
use SanderMuller\FluentValidation\Tests\Fixtures\ExplicitItemKeyRuleFluentFormRequest;
use SanderMuller\FluentValidation\Tests\Fixtures\ExplicitKeyAfterEachFluentFormRequest;
use SanderMuller\FluentValidation\Tests\Fixtures\ExplicitKeyInExcludedItemFluentFormRequest;
use SanderMuller\FluentValidation\Tests\Fixtures\ItemExcludeAfterRuleFluentFormRequest;
use SanderMuller\FluentValidation\Tests\Fixtures\ItemFieldReferenceFluentFormRequest;
use SanderMuller\FluentValidation\Tests\Fixtures\NestedWildcardExcludeFluentFormRequest;

// =========================================================================
// Each fixture pins a wildcard rule shape that once gave other errors or
// other validated data than Laravel. A form request, RuleSet and Laravel's
// own validator must give the same result, and so must FluentValidator.
// =========================================================================

/**
 * @param  class-string<FluentFormRequest>  $class
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function fixtureRequestOutcome(string $class, array $data): array
{
    $request = $class::createFrom(Request::create('/test', 'POST', $data));
    $request->setContainer(app());
    $request->setRedirector(resolve(Redirector::class));

    try {
        $request->validateResolved();
    } catch (ValidationException $validationException) {
        return ['errors' => $validationException->errors()];
    }

    return ['validated' => $request->validated()];
}

/**
 * @param  array<string, mixed>  $rules
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function fixtureNativeOutcome(array $rules, array $data): array
{
    $validator = validator($data, RuleSet::compile(RuleSet::from($rules)->flattenRules()));

    return $validator->fails() ? ['errors' => $validator->errors()->toArray()] : ['validated' => $validator->validated()];
}

/**
 * @param  array<string, mixed>  $rules
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function fixtureRuleSetOutcome(array $rules, array $data): array
{
    $result = RuleSet::from($rules)->check($data);

    return $result->fails() ? ['errors' => $result->errors()->toArray()] : ['validated' => $result->validated()];
}

/**
 * @param  array<string, mixed>  $rules
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function fixtureFluentValidatorOutcome(array $rules, array $data): array
{
    $validator = new class ($data, $rules) extends FluentValidator {};
    // FluentValidator is not made by the factory, so it does not get the
    // factory's default for this.
    $validator->excludeUnvalidatedArrayKeys = true;

    return $validator->fails() ? ['errors' => $validator->errors()->toArray()] : ['validated' => $validator->validated()];
}

/**
 * @return array<string, array{0: class-string<FluentFormRequest&DeclaresFluentRules>, 1: array<string, mixed>, 2: array<string, mixed>}>
 */
function fixtureCases(): array
{
    return [
        'item field reference, item excluded' => [ItemFieldReferenceFluentFormRequest::class, ['items' => [['skip' => 1], ['skip' => 0, 'a' => 1]]], ['validated' => ['items' => [1 => ['skip' => 0, 'a' => 1]]]]],
        'item field reference, item kept and too small' => [ItemFieldReferenceFluentFormRequest::class, ['items' => [['skip' => 0]]], ['errors' => ['items.0' => ['The items.0 field must have at least 2 items.']]]],
        'explicit key after each(), string allowed' => [ExplicitKeyAfterEachFluentFormRequest::class, ['items' => [1, 'text', 3]], ['validated' => ['items' => [1 => 'text', 0 => 1, 2 => 3]]]],
        'explicit key after each(), other items still checked' => [ExplicitKeyAfterEachFluentFormRequest::class, ['items' => ['x', 'text']], ['errors' => ['items.0' => ['The items.0 field must be a number.', 'The items.0 field must be an integer.']]]],
        'nested wildcard exclude, locked item' => [NestedWildcardExcludeFluentFormRequest::class, ['items' => [['locked' => 1, 'tags' => ['a', 'b']], ['locked' => 0, 'tags' => [1, 2]]]], ['validated' => ['items' => [['locked' => 1, 'tags' => []], ['locked' => 0, 'tags' => [1, 2]]]]]],
        'nested wildcard exclude, open item fails' => [NestedWildcardExcludeFluentFormRequest::class, ['items' => [['locked' => 0, 'tags' => ['a']]]], ['errors' => ['items.0.tags.0' => ['The items.0.tags.0 field must be a number.', 'The items.0.tags.0 field must be an integer.']]]],
        'excluded item keys, flag on' => [ExcludedItemKeyFluentFormRequest::class, ['flag' => 1, 'items' => [['x' => 1], ['name' => 'n', 'lines' => [['y' => 1], ['sku' => 's']]]]], ['validated' => ['flag' => 1, 'items' => [1 => ['name' => 'n', 'lines' => [1 => ['sku' => 's']]]]]]],
        'excluded item keys, flag off' => [ExcludedItemKeyFluentFormRequest::class, ['flag' => 0, 'items' => [['x' => 1], ['name' => 'n', 'lines' => [['y' => 1]]]]], ['errors' => ['items.0.name' => ['The items.0.name field is required.'], 'items.1.lines.0.sku' => ['The items.1.lines.0.sku field is required.']]]],
        'explicit key in excluded items' => [ExplicitKeyInExcludedItemFluentFormRequest::class, ['items' => [['status' => 'drop', 'note' => 'n'], ['status' => 'drop']]], ['validated' => ['items' => []]]],
        'explicit key in a kept item' => [ExplicitKeyInExcludedItemFluentFormRequest::class, ['items' => [['status' => 'keep', 'note' => 'n'], ['status' => 'drop']]], ['validated' => ['items' => [['note' => 'n']]]]],
        'explicit item key rule, required fails' => [ExplicitItemKeyRuleFluentFormRequest::class, ['items' => [[], ['a' => null]]], ['errors' => ['items.1.a' => ['The items.1.a field is required.']]]],
        'explicit item key rule, passes' => [ExplicitItemKeyRuleFluentFormRequest::class, ['items' => [['a' => null], ['a' => [1]]]], ['validated' => ['items' => [1 => ['a' => [1]], 0 => ['a' => null]]]]],
        'exclude after another rule, the other rule fails' => [ExcludeAfterOtherRuleFluentFormRequest::class, ['items' => [['b' => 's'], ['status' => 'drop']]], ['errors' => ['items.0' => ['The items.0 field must be a string.']]]],
        'exclude after another rule, the other rule passes' => [ExcludeAfterOtherRuleFluentFormRequest::class, ['items' => ['text', ['status' => 'keep']]], ['validated' => ['items' => [1 => ['status' => 'keep']]]]],
        'item exclude after a rule, the rule fails' => [ItemExcludeAfterRuleFluentFormRequest::class, ['items' => ['text', ['status' => 'keep']]], ['errors' => ['items.0' => ['The items.0 field must be an array.']]]],
        'item exclude after a rule, the rule passes' => [ItemExcludeAfterRuleFluentFormRequest::class, ['items' => [['status' => 'drop'], ['status' => 'keep']]], ['validated' => ['items' => [1 => ['status' => 'keep']]]]],
        'excluded item next to a failing top-level rule' => [ExcludedItemWithTopErrorFluentFormRequest::class, ['flag' => 1, 'note' => 'x', 'items' => [['x' => 1], ['name' => 'n']]], ['errors' => ['note' => ['The note field must be at least 3 characters.']]]],
    ];
}

foreach (fixtureCases() as $name => [$class, $data, $expected]) {
    it("gives the same result in a form request, RuleSet, FluentValidator and Laravel: {$name}", function () use ($class, $data, $expected): void {
        $rules = $class::fluentRules();

        expect(fixtureNativeOutcome($rules, $data))->toBe($expected)
            ->and(fixtureRuleSetOutcome($rules, $data))->toBe($expected)
            ->and(fixtureRequestOutcome($class, $data))->toBe($expected)
            ->and(fixtureFluentValidatorOutcome($rules, $data))->toBe($expected);
    });
}

it('keeps the rules of one dispatch value out of the next request with the same rules', function (): void {
    $requests = [
        ['entries' => [['type' => 'note', 'text' => 't'], ['type' => 'link', 'url' => 'https://a.test'], ['type' => 'scroll', 'from' => 1]]],
        ['entries' => [['type' => 'link', 'text' => 'kept out'], ['type' => 'note'], ['type' => 'scroll', 'from' => 'x']]],
        ['entries' => [['type' => 'link', 'url' => str_repeat('u', 21)], ['type' => 'note', 'text' => 't', 'at' => 'x']]],
        ['entries' => [['type' => 'note', 'text' => 't', 'url' => 'not checked', 'at' => 2]]],
    ];
    $rules = DispatchedFieldsFluentFormRequest::fluentRules();

    foreach ([1, 2] as $round) {
        foreach ($requests as $data) {
            $native = fixtureNativeOutcome($rules, $data);

            expect(fixtureRuleSetOutcome($rules, $data))->toBe($native, "RuleSet, round {$round}")
                ->and(fixtureRequestOutcome(DispatchedFieldsFluentFormRequest::class, $data))->toBe($native, "form request, round {$round}")
                ->and(fixtureFluentValidatorOutcome($rules, $data))->toBe($native, "FluentValidator, round {$round}");
        }
    }
});

it('removes the excluded data before the after hooks, as Laravel does', function (): void {
    $rules = NestedWildcardExcludeFluentFormRequest::fluentRules();
    $data = ['items' => [['locked' => 1, 'tags' => ['a', 'b']], ['locked' => 0, 'tags' => [1, 2]]]];
    $seen = [];

    $native = validator($data, RuleSet::compile(RuleSet::from($rules)->flattenRules()));
    $native->after(static function (Validator $validator) use (&$seen): void {
        $seen['native'] = $validator->getData();
    });
    $native->passes();

    $fluent = new class ($data, $rules) extends FluentValidator {};
    $fluent->after(static function (Validator $validator) use (&$seen): void {
        $seen['fluent'] = $validator->getData();
    });
    $fluent->passes();

    expect($seen['fluent'])->toBe($seen['native'])
        ->and($seen['native']['items'][0]['tags'])
        ->toBeEmpty();
});
