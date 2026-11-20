<?php declare(strict_types=1);

use Illuminate\Contracts\Validation\ValidationRule;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\FluentValidator;
use SanderMuller\FluentValidation\Internal\ItemRuleCompiler;
use SanderMuller\FluentValidation\RuleSet;

// =========================================================================
// Items take the per-item fast path when the reduced rules compile to fast
// checks. These shapes used to fall through to a Laravel validator for every
// item: a kept field still carried its string-form exclude rule, a rule key
// had a dotted path after the wildcard, or `prohibited` came with other
// rules. The results must stay the same as native Laravel.
// =========================================================================

/**
 * @param  array<string, mixed>  $rules
 * @param  array<string, mixed>  $data
 */
function assertFastPathParity(array $rules, array $data, bool $compareValidated = true): void
{
    $native = validator($data, $rules);
    $result = RuleSet::from($rules)->check($data);

    // Per-item validation reports errors item by item; native reports them
    // rule by rule. Compare the messages per field.
    $expected = $native->errors()->toArray();
    $actual = $result->errors()->toArray();
    ksort($expected);
    ksort($actual);

    expect($actual)->toBe($expected);

    if ($compareValidated && ! $native->fails()) {
        expect($result->validated())->toEqual($native->validated());
    }
}

// -- string-form exclude rules -------------------------------------------

it('strips a string-form exclude rule from a kept field', function (mixed $rule, mixed $expected): void {
    $reduced = (new ItemRuleCompiler())->reduceRulesForItem(
        ['price' => $rule],
        ['type' => 'product'],
        ['price' => [['action' => 'exclude_unless', 'field' => 'type', 'values' => ['product']]]],
    );

    expect($reduced['price'])->toBe($expected);
})->with([
    'pipe string' => ['nullable|exclude_unless:type,product|numeric', 'nullable|numeric'],
    'element in an array' => [['exclude_unless:type,product', 'nullable', 'numeric'], 'nullable|numeric'],
    'exclude_if' => ['exclude_if:type,draft|numeric', 'numeric'],
]);

it('leaves a string rule with a regex as it is', function (): void {
    $rule = 'exclude_unless:type,product|regex:/^(a|b)$/';

    $reduced = (new ItemRuleCompiler())->reduceRulesForItem(
        ['code' => $rule],
        ['type' => 'product'],
        ['code' => [['action' => 'exclude_unless', 'field' => 'type', 'values' => ['product']]]],
    );

    expect($reduced['code'])->toBe($rule);
});

it('keeps a pipe inside an array element as one rule, as native does', function (): void {
    $rules = ['items' => 'array', 'items.*.type' => 'required', 'items.*.price' => ['nullable|exclude_unless:items.*.type,product', 'numeric']];
    $data = ['items' => [['type' => 'product', 'price' => 5]]];

    expect(fn () => validator($data, $rules)->passes())->toThrow(BadMethodCallException::class)
        ->and(fn () => RuleSet::from($rules)->check($data))->toThrow(BadMethodCallException::class);
});

it('matches native for string-form exclude rules with coerced values', function (string $dependencies, string $noteRule, array $items): void {
    $rules = ['items' => 'array', 'items.*.note' => $noteRule];

    foreach (explode(',', $dependencies) as $dependency) {
        $rules["items.*.{$dependency}"] = 'nullable';
    }

    assertFastPathParity($rules, ['items' => $items]);
})->with([
    'exclude_if on true' => ['flag', 'exclude_if:items.*.flag,true|integer', [['flag' => true, 'note' => 'x'], ['flag' => '1', 'note' => 'x'], ['flag' => false, 'note' => 'x']]],
    'exclude_unless on null' => ['state', 'exclude_unless:items.*.state,null|integer', [['state' => null, 'note' => 'x'], ['note' => 'x'], ['state' => 'a', 'note' => 'x']]],
    'exclude_if on a number' => ['n', 'exclude_if:items.*.n,1|integer', [['n' => '1', 'note' => 'x'], ['n' => 1.0, 'note' => 'x'], ['n' => '01', 'note' => 'x'], ['n' => '1.0', 'note' => 'x'], ['n' => 'x1', 'note' => 'x']]],
    'exclude_unless on a number' => ['n', 'exclude_unless:items.*.n,1|integer', [['n' => '01', 'note' => 'x'], ['n' => 2, 'note' => 'x']]],
    'quoted value with a comma' => ['t', 'exclude_unless:items.*.t,"a,b"|integer', [['t' => 'a,b', 'note' => 'x'], ['t' => 'a', 'note' => 'x']]],
    'two exclude_if rules' => ['a,b', 'exclude_if:items.*.a,1|exclude_if:items.*.b,1|integer', [['a' => 1, 'note' => 'x'], ['b' => 1, 'note' => 'x'], ['a' => 2, 'b' => 2, 'note' => 'x']]],
]);

it('throws like native for a string-form exclude rule without a value', function (string $rule): void {
    $rules = ['items' => 'array', 'items.*.kind' => 'nullable', 'items.*.note' => "{$rule}|integer"];
    $data = ['items' => [['kind' => 'a', 'note' => 1]]];

    expect(fn () => validator($data, $rules)->passes())->toThrow(InvalidArgumentException::class)
        ->and(fn () => RuleSet::from($rules)->check($data))->toThrow(InvalidArgumentException::class);
})->with(['exclude_if:items.*.kind', 'exclude_unless:items.*.kind']);

it('matches native for a field with only a string-form exclude rule', function (array $item): void {
    assertFastPathParity(
        ['items' => 'array', 'items.*.type' => 'required|string', 'items.*.note' => 'exclude_unless:items.*.type,a'],
        ['items' => [$item]],
    );
})->with([
    'kept' => [['type' => 'a', 'note' => 'x']],
    'excluded' => [['type' => 'b', 'note' => 'x']],
    'kept, absent' => [['type' => 'a']],
]);

/** @return array<string, mixed> */
function activityLogParityRules(): array
{
    $action = 'entries.*.action';

    return [
        'entries' => FluentRule::array()->required()->each([
            'action' => FluentRule::string()->required()->in(['scroll', 'share', 'comment', 'open']),
            'email' => FluentRule::email(defaults: false)->nullable()->requiredIf($action, 'share')->excludeUnless($action, 'share'),
            'scroll_from' => FluentRule::numeric()->nullable()->requiredIf($action, 'scroll')->excludeUnless($action, 'scroll')->between(0, 100),
            'comment_id' => FluentRule::uuid()->nullable()->requiredIf($action, 'comment')->excludeUnless($action, 'comment'),
        ]),
    ];
}

it('matches native for fluent requiredIf and excludeUnless on a dispatch field', function (array $entries): void {
    // Laravel does not expand each(), so its validated() keeps the excluded fields.
    assertFastPathParity(activityLogParityRules(), ['entries' => $entries], compareValidated: false);
})->with([
    'valid' => [[
        ['action' => 'scroll', 'scroll_from' => 5, 'email' => 'x'],
        ['action' => 'share', 'email' => 'a@example.com'],
        ['action' => 'comment', 'comment_id' => '0b8e0c5e-4a7a-4d8e-9a3c-2f3c2b1a0d9e'],
        ['action' => 'open', 'scroll_from' => 'not a number'],
    ]],
    'missing required value' => [[['action' => 'scroll'], ['action' => 'share', 'email' => null]]],
    'wrong value' => [[
        ['action' => 'scroll', 'scroll_from' => 500],
        ['action' => 'share', 'email' => 'not an email'],
        ['action' => 'comment', 'comment_id' => 'not a uuid'],
    ]],
]);

it('leaves out the fields a fluent excludeUnless removes on a dispatch field', function (): void {
    $validated = RuleSet::from(activityLogParityRules())->validate(['entries' => [
        ['action' => 'scroll', 'scroll_from' => 5, 'email' => 'x'],
        ['action' => 'share', 'email' => 'a@example.com'],
        ['action' => 'open', 'scroll_from' => 'not a number'],
    ]]);

    expect($validated)->toEqual(['entries' => [
        ['action' => 'scroll', 'scroll_from' => 5],
        ['action' => 'share', 'email' => 'a@example.com'],
        ['action' => 'open'],
    ]]);
});

// -- exclude rules on a field of a nested item ---------------------------

it('matches native for an exclude rule that depends on a field of a nested item', function (string $excludeRule, array $answers): void {
    assertFastPathParity(
        ['q' => 'array', 'q.*.answers' => 'array', 'q.*.answers.*.type' => 'string', 'q.*.answers.*.target' => $excludeRule . '|integer|min:1'],
        ['q' => [['answers' => $answers], ['answers' => array_reverse($answers)]]],
    );
})->with([
    'exclude_unless, kept and invalid' => ['exclude_unless:q.*.answers.*.type,jump', [['type' => 'jump', 'target' => -5]]],
    'exclude_unless, excluded' => ['exclude_unless:q.*.answers.*.type,jump', [['type' => 'link', 'target' => 'abc']]],
    'exclude_unless, mixed answers' => ['exclude_unless:q.*.answers.*.type,jump', [['type' => 'link', 'target' => 'abc'], ['type' => 'jump', 'target' => -5]]],
    'exclude_unless, mixed and valid' => ['exclude_unless:q.*.answers.*.type,jump', [['type' => 'link', 'target' => 'abc'], ['type' => 'jump', 'target' => 5]]],
    'exclude_if, excluded and invalid' => ['exclude_if:q.*.answers.*.type,link', [['type' => 'link', 'target' => 'abc']]],
    'exclude_if, kept and invalid' => ['exclude_if:q.*.answers.*.type,link', [['type' => 'jump', 'target' => -5]]],
    'exclude_if, mixed and valid' => ['exclude_if:q.*.answers.*.type,link', [['type' => 'link', 'target' => 'abc'], ['type' => 'jump', 'target' => 5]]],
]);

/**
 * Other exclude rules in the same item as a nested-item exclude rule: their
 * rule order and their exclusions must stay as native has them.
 *
 * @return array<string, array{0: array<string, string>, 1: array<string, mixed>}>
 */
function nestedExcludeNeighbourCases(): array
{
    return [
        'a child rule before its excluded parent' => [
            ['q.*.answers.*.size' => 'integer', 'q.*.answers' => 'exclude_if:q.*.mode,off|array'],
            ['mode' => 'off', 'answers' => [['type' => 'link', 'size' => 'bad']]],
        ],
        'a field with an item condition and a nested-item condition' => [
            ['q.*.score' => 'exclude_if:q.*.mode,off|exclude_unless:q.*.answers.*.type,jump|integer'],
            ['mode' => 'off', 'score' => 9, 'answers' => [['type' => 'jump', 'target' => 1]]],
        ],
    ];
}

it('matches native for a nested-item exclude rule next to other exclude rules', function (string $case): void {
    [$rules, $item] = nestedExcludeNeighbourCases()[$case];

    assertFastPathParity(
        ['q' => 'array', 'q.*.mode' => 'string', 'q.*.answers' => 'array', 'q.*.answers.*.type' => 'string', 'q.*.answers.*.target' => 'exclude_unless:q.*.answers.*.type,jump|integer', ...$rules],
        ['q' => [$item]],
    );
})->with(array_keys(nestedExcludeNeighbourCases()));

it('stops at the first failure for a nested-item exclude rule as native does', function (): void {
    $rules = ['q' => 'array', 'q.*.flag' => 'integer', 'q.*.answers' => 'array', 'q.*.answers.*.type' => 'string', 'q.*.answers.*.target' => 'exclude_unless:q.*.answers.*.type,jump|integer'];
    $data = ['q' => [['flag' => 1, 'answers' => [['type' => 'link', 'target' => 'bad']]], ['flag' => 'bad', 'answers' => [['type' => 'jump', 'target' => 'bad']]]]];

    $native = validator($data, $rules)->stopOnFirstFailure();

    expect(RuleSet::from($rules)->stopOnFirstFailure()->check($data)->errors()->keys())->toBe($native->errors()->keys());
});

// -- dotted path after the wildcard --------------------------------------

it('fast-checks a dotted path after the wildcard', function (): void {
    [$checks, $slowRules] = (new ItemRuleCompiler())->buildFastChecks([
        'answers.*.action.type' => 'required|string|in:none,jump',
    ]);

    expect($checks)->toHaveKey('answers.*.action.type')
        ->and($slowRules)->toBeEmpty();
});

it('leaves a dotted path after the wildcard with a sibling reference to Laravel', function (): void {
    [$checks, $slowRules] = (new ItemRuleCompiler())->buildFastChecks([
        'answers.*.action.type' => 'required|same:other',
    ]);

    expect($checks)->toBeEmpty()
        ->and($slowRules)->toHaveKey('answers.*.action.type');
});

it('matches native for a dotted path after the wildcard', function (array $answers): void {
    assertFastPathParity(
        [
            'questions.*.answers' => 'nullable|array',
            'questions.*.answers.*.action' => 'required|array',
            'questions.*.answers.*.action.type' => 'required|string|in:none,jump',
            'questions.*.answers.*.action.time' => 'nullable|numeric|min:0',
        ],
        ['questions' => [['answers' => $answers]]],
    );
})->with([
    'valid' => [[['action' => ['type' => 'none', 'time' => 1]], ['action' => ['type' => 'jump']]]],
    'invalid type' => [[['action' => ['type' => 'other']]]],
    'action absent' => [[['text' => 'a']]],
    'action not an array' => [[['action' => 'x']]],
    'answer not an array' => [['x']],
    'negative time' => [[['action' => ['type' => 'none', 'time' => -1]]]],
    'literal dotted key' => [[['action' => ['type' => 'none'], 'action.type' => 'other']]],
]);

it('matches native when the parent of a dotted path is not an array', function (): void {
    assertFastPathParity(
        ['questions.*.answers' => 'nullable', 'questions.*.answers.*.action.type' => 'required|string'],
        ['questions' => [['answers' => 'x']]],
    );
});

it('matches native for a rule object on a dotted path after the wildcard', function (): void {
    $failing = new class implements ValidationRule {
        public function validate(string $attribute, mixed $value, Closure $fail): void
        {
            $fail('The :attribute is not allowed.');
        }
    };

    assertFastPathParity(
        ['questions.*.answers' => 'array', 'questions.*.answers.*.action.type' => ['required', 'string', $failing]],
        ['questions' => [['answers' => [['action' => ['type' => 'x']]]]]],
    );
});

it('matches native for an escaped dot in a dotted path after the wildcard', function (mixed $value): void {
    assertFastPathParity(
        ['questions.*.answers' => 'nullable|array', 'questions.*.answers.*.meta.first\\.name' => 'nullable|string'],
        ['questions' => [['answers' => [['meta' => ['first.name' => $value]]]]]],
    );
})->with([
    'valid' => ['Alice'],
    'invalid' => [5],
]);

// -- prohibited with other rules -----------------------------------------

it('matches native for prohibited with other value rules', function (string $rule, array $item): void {
    assertFastPathParity(['items.*.answers' => $rule], ['items' => [$item]]);
})->with(static function (): iterable {
    $rules = ['nullable|prohibited|array|max:2', 'prohibited|array', 'prohibited|string|max:3', 'prohibited|integer|min:5'];
    $items = [
        'absent' => [],
        'null' => ['answers' => null],
        'empty string' => ['answers' => ''],
        'blank string' => ['answers' => '   '],
        'empty array' => ['answers' => []],
        'non-empty array' => ['answers' => [1]],
        'zero' => ['answers' => 0],
    ];

    foreach ($rules as $rule) {
        foreach ($items as $name => $item) {
            yield "{$rule}, {$name}" => [$rule, $item];
        }
    }
});

it('matches native for prohibited with other value rules in FluentValidator and on top-level keys', function (string $rule, bool $present, mixed $value): void {
    $item = $present ? ['a' => $value] : [];
    $rules = ['a' => $rule, 'items' => 'array', 'items.*.a' => $rule];
    $data = $present ? ['a' => $value, 'items' => [$item]] : ['items' => [$item]];
    $native = validator($data, $rules);
    $fluent = new class ($data, $rules) extends FluentValidator {};

    $expected = $native->errors()->toArray();
    $actual = $fluent->errors()->toArray();
    ksort($expected);
    ksort($actual);

    expect($actual)->toBe($expected);

    assertFastPathParity(['a' => $rule], $item);
})->with(static function (): iterable {
    $rules = ['nullable|prohibited|array|max:2', 'prohibited|string|max:3', 'prohibited|integer|min:5', 'prohibited|array|min:1'];
    $values = [
        'absent' => [false, null],
        'null' => [true, null],
        'empty string' => [true, ''],
        'blank string' => [true, '   '],
        'empty array' => [true, []],
        'non-empty array' => [true, [1]],
        'zero' => [true, 0],
        'false' => [true, false],
    ];

    foreach ($rules as $rule) {
        foreach ($values as $name => [$present, $value]) {
            yield "{$rule}, {$name}" => [$rule, $present, $value];
        }
    }
});

it('matches native for a fluent prohibitedUnless that reduces to prohibited', function (array $item): void {
    assertFastPathParity(
        ['items' => FluentRule::array()->each([
            'type' => FluentRule::string()->required(),
            'answers' => FluentRule::array()->nullable()->prohibitedUnless('items.*.type', 'single')->max(2),
        ])],
        ['items' => [$item]],
    );
})->with([
    'open, no answers' => [['type' => 'open']],
    'open, null answers' => [['type' => 'open', 'answers' => null]],
    'open, answers' => [['type' => 'open', 'answers' => [1]]],
    'single, answers' => [['type' => 'single', 'answers' => [1]]],
    'single, too many answers' => [['type' => 'single', 'answers' => [1, 2, 3]]],
]);
