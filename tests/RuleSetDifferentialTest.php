<?php declare(strict_types=1);

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Validation\Rule;
use Random\Engine\Mt19937;
use Random\Randomizer;
use SanderMuller\FluentValidation\FluentFormRequest;
use SanderMuller\FluentValidation\RuleSet;

// =========================================================================
// Differential test: random wildcard rule sets and random items, validated
// by RuleSet, by a FluentFormRequest and by native Laravel. The error keys must match. Fixed seeds
// keep every run the same; a failure prints the seed, rules and data.
//
// exclude_* rules are left out: RuleSet removes an excluded field before any
// of its rules run, while Laravel still runs the rules before the exclude
// rule in the list. That difference is older than this test.
// =========================================================================

const DIFFERENTIAL_RULE_PARTS = [
    'string', 'numeric', 'integer', 'boolean', 'array', 'email', 'date', 'url', 'uuid',
    'alpha', 'alpha_dash', 'alpha_num', 'accepted', 'declined',
    'required', 'nullable', 'sometimes', 'bail', 'filled', 'present',
    'min:1', 'min:3', 'max:2', 'max:10', 'min:0.5', 'max:1.5', 'between:1,10', 'between:2,4', 'size:3', 'digits:2', 'digits_between:1,3',
    'in:a,b', 'in:b,c', 'in:"a,b"', 'not_in:a', 'not_in:b,c', 'regex:/^a/', 'regex:/^(a|b)$/', 'not_regex:/z/',
    'date_format:Y-m-d', 'date_format:d/m/Y', 'after:2025-01-01', 'before:2026-01-01', 'after_or_equal:2025-01-01', 'date_equals:2025-06-01',
    'required_if:items.*.type,a', 'required_if:items.*.flag,true', 'required_unless:items.*.type,b',
    'required_with:items.*.other', 'required_without:items.*.other',
    'prohibited_if:items.*.type,a', 'prohibited_unless:items.*.flag,false',
    'same:items.*.other', 'different:items.*.other', 'gt:items.*.n', 'gte:items.*.n', 'lt:items.*.n',
    'after:items.*.other', 'confirmed',
    'accepted_if:items.*.type,a', 'declined_if:items.*.flag,true', 'missing_if:items.*.type,c', 'present_if:items.*.type,a',
];

const DIFFERENTIAL_TUPLES = [['required_if', 'items.*.type', 'a'], ['required_with', 'items.*.other']];

const DIFFERENTIAL_VALUES = [
    null, '', 'a', 'b', 'c', 'abc', 'a,b', '5', 5, 5.5, '99', 99, 0, '0', '1', true, false, [], ['x'], ['x', 'y', 'z'],
    0.2, 1.7, '2020-01-01', '2025-06-01', '2026-06-01', '01/12/2025', 'test@example.com', 'https://x.test', '12', 'ab',
];

/**
 * @template T
 *
 * @param  non-empty-list<T>  $list
 * @return T
 */
function differentialPick(Randomizer $random, array $list): mixed
{
    return $list[$random->getInt(0, count($list) - 1)];
}

/**
 * A plain custom rule, and one that declares itself implicit.
 *
 * @return list<ValidationRule>
 */
function differentialCustomRules(): array
{
    return [
        new class implements ValidationRule {
            public function validate(string $attribute, mixed $value, Closure $fail): void
            {
                if ($value === 'b' || $value === null) {
                    $fail('The :attribute is not allowed.');
                }
            }
        },
        new class implements ValidationRule {
            public bool $implicit = true;

            public function validate(string $attribute, mixed $value, Closure $fail): void
            {
                if ($value === null || $value === '') {
                    $fail('The :attribute is needed.');
                }
            }
        },
    ];
}

/**
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function differentialCase(int $seed): array
{
    $random = new Randomizer(new Mt19937($seed));
    $rules = [];

    foreach (['type', 'flag', 'other', 'n', 'f1', 'f2'] as $field) {
        if ($random->getInt(0, 3) === 0) {
            continue;
        }

        $list = [];

        for ($count = $random->getInt(1, 4); $count > 0; $count--) {
            $list[] = match ($random->getInt(0, 19)) {
                0 => differentialPick($random, [Rule::in(['a', 'b']), Rule::in(['a,b', 'c']), Rule::notIn(['b']), ...differentialCustomRules()]),
                1 => differentialPick($random, DIFFERENTIAL_TUPLES),
                default => differentialPick($random, DIFFERENTIAL_RULE_PARTS),
            };
        }

        $plain = array_filter($list, static fn (mixed $part): bool => ! is_string($part) || str_contains($part, '|')) === [];
        $rules["items.*.{$field}"] = $plain && $random->getInt(0, 1) === 1 ? implode('|', array_filter($list, is_string(...))) : $list;
    }

    $items = [];

    for ($count = $random->getInt(1, 3); $count > 0; $count--) {
        $item = [];

        foreach (['type', 'flag', 'other', 'n', 'f1', 'f2', 'f1_confirmation'] as $field) {
            if ($random->getInt(0, 2) !== 0) {
                $item[$field] = differentialPick($random, DIFFERENTIAL_VALUES);
            }
        }

        $items[] = $item;
    }

    return [$rules, ['items' => $items]];
}

it('reports the same error keys as native for random rule sets', function (int $from): void {
    for ($seed = $from; $seed < $from + 50; $seed++) {
        [$rules, $data] = differentialCase($seed);

        try {
            $native = validator($data, $rules)->errors()->keys();
        } catch (Throwable) {
            continue;
        }

        $fluent = RuleSet::from($rules)->check($data)->errors()->keys();
        sort($native);
        sort($fluent);

        expect($fluent)->toBe($native, sprintf(
            "seed %d\nrules %s\ndata %s",
            $seed,
            json_encode($rules, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ));
    }
})->with(array_map(static fn (int $chunk): int => $chunk * 50, range(0, 19)));

final class DifferentialFormRequest extends FluentFormRequest
{
    /** @var array<string, mixed> */
    public static array $testRules = [];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::$testRules;
    }
}

it('reports the same error keys as native for random rule sets in a form request', function (int $from): void {
    for ($seed = $from; $seed < $from + 50; $seed++) {
        [$rules, $data] = differentialCase($seed);

        try {
            $native = validator($data, $rules)->errors()->keys();
        } catch (Throwable) {
            continue;
        }

        DifferentialFormRequest::$testRules = $rules;
        $instance = DifferentialFormRequest::createFrom(Request::create('/t', 'POST', $data));
        $instance->setContainer(app());
        $instance->setRedirector(resolve(Redirector::class));
        $factory = resolve(Factory::class);
        $fluent = (fn () => $this->createDefaultValidator($factory))->call($instance)->errors()->keys();
        sort($native);
        sort($fluent);

        expect($fluent)->toBe($native, sprintf(
            "seed %d\nrules %s\ndata %s",
            $seed,
            json_encode($rules, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ));
    }
})->with(array_map(static fn (int $chunk): int => $chunk * 50, range(0, 19)));
