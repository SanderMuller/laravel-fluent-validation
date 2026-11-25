<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Validator;
use SanderMuller\FluentValidation\FluentFormRequest;
use SanderMuller\FluentValidation\RuleSet;

// =========================================================================
// Laravel's validated() sets the keys rule key by rule key, so its key order
// follows the rules, not the input. A fluent form request gives the same keys
// in the same order. RuleSet does too, except for the fields inside an item,
// see KnownDifferencesTest.
// =========================================================================

/** @return array<string, array{array<string, mixed>, array<string, mixed>}> */
function validatedKeyOrderCases(): array
{
    return [
        'top-level rule order' => [['b' => 'string', 'a' => 'string'], ['a' => 'x', 'b' => 'y']],
        'field order in an item' => [['items.*.b' => 'string', 'items.*.a' => 'string'], ['items' => [['a' => 'x', 'b' => 'y']]]],
        'mixed items' => [['items.*' => 'nullable'], ['items' => [[1], 'x']]],
        'mixed items under an array rule' => [['items' => 'array', 'items.*' => 'nullable'], ['items' => [[1], 'x']]],
        'mixed items under a list rule' => [['items' => 'list', 'items.*' => 'nullable'], ['items' => [[1], 'x']]],
        'mixed items under a second wildcard' => [['items.*.tags.*' => 'nullable'], ['items' => [['tags' => [[1]]], ['tags' => ['b']]]]],
        'mixed tags in one item' => [['items.*.tags.*' => 'nullable'], ['items' => [['tags' => [[1], 'a']]]]],
        'a skipped array before a later field' => [['o.r' => 'nullable|array', 'o.r.*' => 'required', 'flag' => 'nullable'], ['flag' => 0, 'o' => ['r' => [[true]]]]],
        'an array that nothing below sets' => [['r' => 'array', 'r.*.l.*.a' => 'nullable', 'flag' => 'nullable'], ['flag' => 1, 'r' => [[true]]]],
        'an explicit item key under a wildcard' => [['o.r' => 'nullable|array', 'o.r.*' => 'nullable', 'o.r.1.a' => 'required', 'flag' => 'nullable'], ['flag' => 1, 'o' => ['r' => [['f' => 1, 'a' => 0], ['b' => 7, 'a' => -1], [0]]]]],
        'an array whose rules below are excluded' => [['r' => 'list', 'r.*.a' => 'exclude_if:flag,1|nullable', 'flag' => 'nullable'], ['flag' => 1, 'r' => [[true, 1], true]]],
    ];
}

/** @return list<string> */
function ruleSetKeyOrderCases(): array
{
    return array_values(array_diff(array_keys(validatedKeyOrderCases()), ['field order in an item']));
}

it('orders the validated keys like native Laravel in RuleSet', function (string $case): void {
    [$rules, $data] = validatedKeyOrderCases()[$case];

    expect(RuleSet::from($rules)->check($data)->validated())->toBe(validator($data, $rules)->validated());
})->with(ruleSetKeyOrderCases());

it('orders the validated keys like native Laravel in a fluent form request', function (string $case): void {
    [$rules, $data] = validatedKeyOrderCases()[$case];
    $request = new class extends FluentFormRequest {
        /** @var array<string, mixed> */
        public static array $caseRules = [];

        /** @return array<string, mixed> */
        public function rules(): array
        {
            return self::$caseRules;
        }
    };
    $request::$caseRules = $rules;
    $request = $request::createFrom(Request::create('/test', 'POST', $data));
    $request->setContainer(app());
    $request->setRedirector(resolve(Redirector::class));

    $request->validateResolved();

    expect($request->validated())->toBe(validator($data, $rules)->validated());
})->with(array_keys(validatedKeyOrderCases()));

it('orders the validated keys like native Laravel in RuleSet when the factory keeps unvalidated keys', function (string $case): void {
    [$rules, $data] = validatedKeyOrderCases()[$case];
    Validator::includeUnvalidatedArrayKeys();

    try {
        expect(RuleSet::from($rules)->check($data)->validated())->toBe(validator($data, $rules)->validated());
    } finally {
        Validator::excludeUnvalidatedArrayKeys();
    }
})->with(ruleSetKeyOrderCases());

it('orders the validated keys like native Laravel in RuleSet with dropUnknownFields when the factory keeps unvalidated keys', function (string $case): void {
    [$rules, $data] = validatedKeyOrderCases()[$case];
    Validator::includeUnvalidatedArrayKeys();

    try {
        $native = validator($data, $rules);
        $native->excludeUnvalidatedArrayKeys = true;

        expect(RuleSet::from($rules)->dropUnknownFields()->check($data)->validated())->toBe($native->validated());
    } finally {
        Validator::excludeUnvalidatedArrayKeys();
    }
})->with(ruleSetKeyOrderCases());
