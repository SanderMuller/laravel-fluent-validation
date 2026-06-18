<?php declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use SanderMuller\FluentValidation\FluentValidator;

// =========================================================================
// FluentValidator now extends OptimizedValidator and runs the same
// conditional pre-evaluation + fast-check path as HasFluentRules /
// RuleSet::validate. These tests pin its verdicts to native Laravel for a
// conditional-heavy wildcard ruleset — including the requiredUnless('*.type')
// case that previously diverged in the per-item reducer — so the perf win
// can't silently change behaviour.
// =========================================================================

/** Concrete FluentValidator mirroring an import-style conditional ruleset. */
final class ConditionalParityValidator extends FluentValidator
{
    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        parent::__construct($data, [
            'interactions.*.type' => ['required', 'string'],
            'interactions.*.start_time' => ['required', 'numeric', 'min:0'],
            // requiredUnless against the wildcard sibling path, multi-value.
            'interactions.*.end_time' => [['required_unless', 'interactions.*.type', 'pause', 'stop'], 'numeric'],
            // exclude_unless: position only validated for chapter/menu.
            'interactions.*.position' => [['exclude_unless', 'interactions.*.type', 'chapter', 'menu'], 'required', 'string'],
            // required_if: image_url required when type=image.
            'interactions.*.image_url' => [['required_if', 'interactions.*.type', 'image'], 'nullable', 'string'],
        ]);
    }
}

/**
 * @param  array<string, mixed>  $data
 * @return list<string>  sorted failing attribute keys
 */
function nativeConditionalFailures(array $data): array
{
    $errors = validator($data, [
        'interactions.*.type' => ['required', 'string'],
        'interactions.*.start_time' => ['required', 'numeric', 'min:0'],
        'interactions.*.end_time' => ['required_unless:interactions.*.type,pause,stop', 'numeric'],
        'interactions.*.position' => ['exclude_unless:interactions.*.type,chapter,menu', 'required', 'string'],
        'interactions.*.image_url' => ['required_if:interactions.*.type,image', 'nullable', 'string'],
    ])->errors()->keys();

    sort($errors);

    return $errors;
}

/**
 * @param  array<string, mixed>  $data
 * @return list<string>  sorted failing attribute keys
 */
function fluentConditionalFailures(array $data): array
{
    try {
        (new ConditionalParityValidator($data))->validate();

        return [];
    } catch (ValidationException $validationException) {
        $keys = array_keys($validationException->errors());
        sort($keys);

        return $keys;
    }
}

it('FluentValidator conditional verdicts match native Laravel', function (array $item): void {
    $data = ['interactions' => [$item]];

    expect(fluentConditionalFailures($data))
        ->toBe(nativeConditionalFailures($data), 'item: ' . json_encode($item));
})->with([
    // requiredUnless: type in (pause,stop) → end_time not required
    'pause, no end_time' => [['type' => 'pause', 'start_time' => 1.0]],
    'stop, no end_time' => [['type' => 'stop', 'start_time' => 1.0]],
    // requiredUnless: type not in list → end_time required
    'play, no end_time → error' => [['type' => 'play', 'start_time' => 1.0]],
    'play, with end_time' => [['type' => 'play', 'start_time' => 1.0, 'end_time' => 2.0]],
    // exclude_unless: position only required for chapter/menu
    'chapter, no position → error' => [['type' => 'chapter', 'start_time' => 1.0, 'end_time' => 2.0]],
    'chapter, with position' => [['type' => 'chapter', 'start_time' => 1.0, 'end_time' => 2.0, 'position' => 'top']],
    'button, no position ok' => [['type' => 'button', 'start_time' => 1.0, 'end_time' => 2.0]],
    'menu, no position → error' => [['type' => 'menu', 'start_time' => 1.0, 'end_time' => 2.0]],
    // required_if: image_url required when type=image
    'image, no url → error' => [['type' => 'image', 'start_time' => 1.0, 'end_time' => 2.0]],
    'image, with url' => [['type' => 'image', 'start_time' => 1.0, 'end_time' => 2.0, 'image_url' => 'x.png']],
    // missing start_time always fails
    'missing start_time' => [['type' => 'pause']],
]);

// =========================================================================
// exclude_* dependent-value coercion parity. The optimized pre-evaluation
// (preExcludeRules + ConditionalEvaluationPhase) must NOT pre-decide cases
// that need Laravel's own coercion — null/bool dependents compared against
// 'null'/'true'/'false', and associative (non-numeric) wildcard keys. It
// defers those to the validator so the verdict matches native Laravel.
// =========================================================================

/**
 * @param  array<string, mixed>  $rules  native rule strings keyed by wildcard path
 * @param  array<string, mixed>  $fluentRules
 * @param  array<string, mixed>  $data
 */
function assertFvExcludeParity(array $rules, array $fluentRules, array $data): void
{
    $native = validator($data, $rules)->errors()->keys();
    sort($native);

    $validator = new class ($data, $fluentRules) extends FluentValidator {};

    try {
        $validator->validate();
        $fluent = [];
    } catch (ValidationException $validationException) {
        $fluent = array_keys($validationException->errors());
    }

    sort($fluent);

    expect($fluent)->toBe($native);
}

it('exclude_unless with null dependent matches native (defers coercion)', function (): void {
    assertFvExcludeParity(
        ['items.*.detail' => ['exclude_unless:items.*.state,null', 'required', 'string']],
        ['items.*.detail' => [['exclude_unless', 'items.*.state', 'null'], 'required', 'string']],
        ['items' => [['state' => null]]], // state IS null → not excluded → required fires
    );
});

it('exclude_if with boolean dependent matches native (defers coercion)', function (): void {
    assertFvExcludeParity(
        [
            'items.*.flag' => ['boolean'],
            'items.*.name' => ['exclude_if:items.*.flag,true', 'required', 'string'],
        ],
        [
            'items.*.flag' => ['boolean'],
            'items.*.name' => [['exclude_if', 'items.*.flag', 'true'], 'required', 'string'],
        ],
        ['items' => [['flag' => true]]], // flag IS true → excluded → no error
    );
});

it('exclude_unless with associative-key wildcard matches native', function (): void {
    assertFvExcludeParity(
        ['items.*.extra' => ['exclude_unless:items.*.type,a', 'required', 'string']],
        ['items.*.extra' => [['exclude_unless', 'items.*.type', 'a'], 'required', 'string']],
        ['items' => ['foo' => ['type' => 'a']]], // type matches → not excluded → required fires
    );
});

it('exclude_unless on nested associative+numeric wildcard matches native', function (): void {
    // items is associative-keyed ('foo'); the dep items.*.type must resolve to
    // items.foo.type (the associative parent), not a numeric descendant. type
    // mismatches 'keep' → not excluded → required fires on the missing detail.
    assertFvExcludeParity(
        ['items.*.rows.*.detail' => ['exclude_unless:items.*.type,keep', 'required', 'string']],
        ['items.*.rows.*.detail' => [['exclude_unless', 'items.*.type', 'keep'], 'required', 'string']],
        ['items' => ['foo' => ['type' => 'drop', 'rows' => [['x' => 1]]]]],
    );
});

it('deferred exclude_if does not leak the excluded field into validated()', function (): void {
    // flag=true (a bool dependent → deferred to Laravel) AND name is present and
    // valid. The sibling `label` carries a plain fast-checkable rule so the
    // validator builds fast-checks — without which the fast-check path that
    // could re-add the excluded field never runs. Native excludes name from the
    // payload; the optimizer must not fast-check it back in. Asserts validated()
    // output, not just error keys.
    $data = ['items' => [['flag' => true, 'name' => 'present', 'label' => 'ok']]];

    $rules = static fn (mixed $excludeIf): array => [
        'items.*.flag' => ['boolean'],
        'items.*.label' => ['string'],
        'items.*.name' => [$excludeIf, 'string'],
    ];

    $native = validator($data, $rules('exclude_if:items.*.flag,true'))->validate();

    $validator = new class ($data, $rules(['exclude_if', 'items.*.flag', 'true'])) extends FluentValidator {};

    expect($validator->validate())->toBe($native)
        ->and($native)->toBe(['items' => [['flag' => true, 'label' => 'ok']]]); // name excluded, not leaked
});

it('FluentValidator validates a mixed multi-item array like native', function (): void {
    $data = ['interactions' => [
        ['type' => 'pause', 'start_time' => 1.0],                                  // ok
        ['type' => 'chapter', 'start_time' => 2.0, 'end_time' => 3.0, 'position' => 'top'], // ok
        ['type' => 'image', 'start_time' => 4.0, 'end_time' => 5.0],               // image_url missing → error
        ['type' => 'play', 'start_time' => 6.0],                                   // end_time missing → error
    ]];

    expect(fluentConditionalFailures($data))->toBe(nativeConditionalFailures($data));
});
