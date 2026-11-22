<?php declare(strict_types=1);

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\FluentValidator;
use SanderMuller\FluentValidation\HasFluentRules;
use SanderMuller\FluentValidation\RuleSet;
use SanderMuller\FluentValidation\Validated;
use SanderMuller\FluentValidation\WildcardExpander;

/**
 * A data key that contains a dot, such as `2.5`, is one key. Laravel keeps it
 * one key when it expands a wildcard; every entry point must do the same.
 *
 * @return array<string, array{0: array<string, string>, 1: array<string, mixed>}>
 */
function dottedDataKeyCases(): array
{
    return [
        'nested key, invalid value' => [['groups' => 'array', 'groups.*.options' => 'array', 'groups.*.options.*' => 'in:0,1'], ['groups' => [7 => ['options' => ['2.5' => '9', '3' => '0']]]]],
        'nested key, valid value' => [['groups' => 'array', 'groups.*.options' => 'array', 'groups.*.options.*' => 'in:0,1'], ['groups' => [7 => ['options' => ['2.5' => '1', '3' => '0']]]]],
        'outer key, invalid value' => [['groups' => 'array', 'groups.*.options' => 'array', 'groups.*.options.*' => 'in:0,1'], ['groups' => ['x.y' => ['options' => ['1' => '9']]]]],
        'dependent rule under an outer key' => [['rows' => 'array', 'rows.*.size' => 'same:rows.*.copy', 'rows.*.copy' => 'integer'], ['rows' => ['a.b' => ['size' => 1, 'copy' => 1], 'c' => ['size' => 1, 'copy' => 2]]]],
        'exclude rule under an outer key' => [['rows' => 'array', 'rows.*.flag' => 'string', 'rows.*.id' => 'exclude_unless:rows.*.flag,yes|integer'], ['rows' => ['a.b' => ['flag' => 'yes', 'id' => 'bad'], 'c' => ['flag' => 'no', 'id' => 'bad']]]],
        // Laravel does not resolve the dependent field under a dotted key, so it keeps the rule.
        'exclude rule under an outer key, condition not met' => [['rows' => 'array', 'rows.*.flag' => 'string', 'rows.*.id' => 'exclude_unless:rows.*.flag,yes|integer'], ['rows' => ['x.y' => ['flag' => 'no', 'id' => 'bad'], 'z' => ['flag' => 'no', 'id' => 'bad']]]],
        'dependent rule under a key with a comma' => [['rows' => 'array', 'rows.*.id' => 'same:rows.*.copy', 'rows.*.copy' => 'integer'], ['rows' => ['a.b,c' => ['id' => 1, 'copy' => 1]]]],
        'dependent rule under a key with a pipe' => [['rows' => 'array', 'rows.*.id' => 'same:rows.*.copy', 'rows.*.copy' => 'integer'], ['rows' => ['a.b|c' => ['id' => 1, 'copy' => 1]]]],
        'dotted key in a list item' => [['items' => 'array', 'items.*.first\.name' => 'required|string'], ['items' => [['first.name' => 'x']]]],
        'key with an asterisk, valid value' => [['items' => 'array', 'items.*.name' => 'required|string'], ['items' => ['c*d' => ['name' => 'x']]]],
        'key with an asterisk, invalid value' => [['items' => 'array', 'items.*.name' => 'required|string'], ['items' => ['c*d' => ['name' => '']]]],
        'key with an asterisk, wildcard at the end' => [['items' => 'array', 'items.*' => 'integer'], ['items' => ['c*d' => 'x', 'e' => 1]]],
        'key with an asterisk next to a plain key' => [['items' => 'array', 'items.*.name' => 'string'], ['items' => ['c*d' => ['name' => 'x'], 'e' => ['name' => 'y']]]],
        'dependent rule under a key with an asterisk' => [['items' => 'array', 'items.*.id' => 'same:items.*.copy'], ['items' => ['c*d' => ['id' => 1, 'copy' => 2]]]],
        'key with a dot and an asterisk' => [['items' => 'array', 'items.*.name' => 'required'], ['items' => ['a.b*c' => ['name' => '']]]],
        'distinct across keys' => [['groups' => 'array', 'groups.*.options' => 'array', 'groups.*.options.*' => 'distinct'], ['groups' => [7 => ['options' => ['2.5' => 'a', '3' => 'a', '4' => 'b']]]]],
    ];
}

/**
 * The errors and, when there are none, the validated data.
 *
 * @param  Closure(): (ValidatorContract|Validated)  $makeValidator
 * @return array{0: list<string>, 1: array<array-key, mixed>|null}
 */
function dottedDataKeyResult(Closure $makeValidator): array
{
    $validator = $makeValidator();
    $errors = $validator->errors()->keys();
    sort($errors);

    return [$errors, $errors === [] ? $makeValidator()->validated() : null];
}

it('expands a wildcard over a key that contains a dot as one escaped key', function (): void {
    expect(WildcardExpander::expand('groups.*.options.*', ['groups' => ['x.y' => ['options' => ['2.5' => '1']]]]))
        ->toBe(['groups.x\.y.options.2\.5']);
});

it('expands one wildcard over a key that contains a dot as one escaped key', function (): void {
    expect(WildcardExpander::expand('rows.*.size', ['rows' => ['a.b' => [], 3 => 'x']]))
        ->toBe(['rows.a\.b.size', 'rows.3.size']);
});

it('validates data keys that contain a dot as native does', function (string $case, string $entryPoint): void {
    [$rules, $data] = dottedDataKeyCases()[$case];

    $expected = dottedDataKeyResult(static fn () => Validator::make($data, $rules));

    $actual = match ($entryPoint) {
        'prepare' => dottedDataKeyResult(static function () use ($rules, $data) {
            $prepared = RuleSet::from($rules)->prepare($data);

            return Validator::make($data, $prepared->rules, $prepared->messages, $prepared->attributes);
        }),
        'FluentValidator' => dottedDataKeyResult(static fn () => new class ($data, $rules) extends FluentValidator {}),
        'check' => dottedDataKeyResult(static fn () => RuleSet::from($rules)->check($data)),
        'check with full expansion' => dottedDataKeyResult(static fn () => RuleSet::from($rules)->dropUnknownFields()->check($data)),
        default => throw new LogicException($entryPoint),
    };

    expect($actual)->toEqual($expected);
})->with(function (): Generator {
    foreach (array_keys(dottedDataKeyCases()) as $case) {
        foreach (['prepare', 'FluentValidator', 'check', 'check with full expansion'] as $entryPoint) {
            // distinct needs the implicit attributes, which Validator::make() does not receive (see docs/11-ruleset.md).
            if ($case !== 'distinct across keys' || $entryPoint !== 'prepare') {
                yield "{$case} / {$entryPoint}" => [$case, $entryPoint];
            }
        }
    }
});

it('validates data keys that contain a dot in a form request as native does', function (string $case): void {
    [$rules, $data] = dottedDataKeyCases()[$case];

    $request = new class extends FormRequest {
        use HasFluentRules;

        /** @var array<string, string> */
        public static array $fluentRules = [];

        /** @return array<string, string> */
        public function rules(): array
        {
            return self::$fluentRules;
        }
    };
    $request::$fluentRules = $rules;
    $request = $request::createFrom(Request::create('/import', 'POST', $data));
    $request->setContainer(app())->setRedirector(resolve(Redirector::class));

    try {
        $request->validateResolved();
        $actual = [[], $request->validated()];
    } catch (ValidationException $validationException) {
        $errors = array_keys($validationException->errors());
        sort($errors);
        $actual = [$errors, null];
    }

    expect($actual)->toEqual(dottedDataKeyResult(static fn () => Validator::make($data, $rules)));
})->with(array_keys(dottedDataKeyCases()));

it('uses the fluent label and message for a key that contains a dot or an asterisk', function (string $entryPoint, string $key): void {
    $rules = ['rows' => FluentRule::array()->each(FluentRule::integer(label: 'row value')->message('Give :attribute as a number.'))];
    $data = ['rows' => [$key => 'bad']];

    $errors = match ($entryPoint) {
        'prepare' => (static function () use ($rules, $data) {
            $prepared = RuleSet::from($rules)->prepare($data);

            return Validator::make($data, $prepared->rules, $prepared->messages, $prepared->attributes)->errors();
        })(),
        'FluentValidator' => (new class ($data, $rules) extends FluentValidator {})->errors(),
        'check' => RuleSet::from($rules)->check($data)->errors(),
        'check with full expansion' => RuleSet::from($rules)->dropUnknownFields()->check($data)->errors(),
        default => throw new LogicException($entryPoint),
    };

    // The same rules on a key without a dot give the messages to expect.
    $expected = RuleSet::from($rules)->check(['rows' => ['ab' => 'bad']])->errors()->get('rows.ab');

    expect($errors->toArray())->toBe(["rows.{$key}" => $expected]);
})->with(['prepare', 'FluentValidator', 'check', 'check with full expansion'])->with(['a.b', 'c*d']);

it('keeps the label of a nested field when a literal dotted field decodes to the same name', function (string $entryPoint): void {
    $rules = ['rows' => FluentRule::array()->children([
        'a' => FluentRule::array()->children(['b' => FluentRule::integer(label: 'nested value')]),
        'a\\.b' => FluentRule::integer(label: 'literal value'),
    ])];
    $data = ['rows' => ['a' => ['b' => 'bad'], 'a.b' => 5]];

    $errors = $entryPoint === 'prepare'
        ? (static function () use ($rules, $data) {
            $prepared = RuleSet::from($rules)->prepare($data);

            return Validator::make($data, $prepared->rules, $prepared->messages, $prepared->attributes)->errors();
        })()
        : RuleSet::from($rules)->check($data)->errors();

    expect($errors->first('rows.a.b'))->toContain('nested value');
})->with(['prepare', 'check']);

it('does not give the label of a literal dotted field to a nested field without a label', function (string $entryPoint): void {
    $rules = ['rows' => 'array', 'rows.a.b' => 'integer', 'rows.a\\.b' => FluentRule::integer(label: 'literal only')];
    $data = ['rows' => ['a' => ['b' => 'bad'], 'a.b' => 5]];

    $errors = $entryPoint === 'prepare'
        ? (static function () use ($rules, $data) {
            $prepared = RuleSet::from($rules)->prepare($data);

            return Validator::make($data, $prepared->rules, $prepared->messages, $prepared->attributes)->errors();
        })()
        : RuleSet::from($rules)->check($data)->errors();

    expect($errors->first('rows.a.b'))->toBe(Validator::make($data, ['rows.a.b' => ['integer']])->errors()->first('rows.a.b'));
})->with(['prepare', 'check']);

it('validates a key with an asterisk before any validator sets the placeholder hash', function (): void {
    Illuminate\Validation\Validator::flushState();

    $data = ['items' => ['c*d' => ['name' => '']]];

    $prepared = RuleSet::from(['items.*.name' => 'required|string'])->prepare($data);

    expect(Validator::make($data, $prepared->rules)->errors()->keys())->toBe(['items.c*d.name']);
});
