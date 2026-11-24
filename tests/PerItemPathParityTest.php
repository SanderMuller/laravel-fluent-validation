<?php declare(strict_types=1);

use Illuminate\Contracts\Validation\ValidationRule;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\RuleSet;

// =========================================================================
// Per-item fast checks read nested values (`style.top`) and nested wildcard
// elements (`tags.*`) from the item. Each must report what native reports.
// =========================================================================

/**
 * @param  array<string, mixed>  $rules
 * @param  array<string, mixed>  $data
 */
function assertPerItemParity(array $rules, array $data): void
{
    expect(RuleSet::from($rules)->check($data)->errors()->keys())
        ->toBe(validator($data, $rules)->errors()->keys());
}

it('checks nested wildcard elements of an item like native', function (mixed $tagRule, array $tags): void {
    assertPerItemParity(['items.*.tags' => 'array', 'items.*.tags.*' => $tagRule], ['items' => [['tags' => $tags]]]);
})->with([
    'array form, bad element' => [['nullable', 'string'], [123]],
    'string form, bad element' => ['nullable|string', [123]],
    'array form, valid elements' => [['nullable', 'string'], ['a', null]],
]);

it('checks nested wildcard elements of a FluentRule each() like native', function (): void {
    $data = ['items' => [['tags' => [123, 'a']]]];

    expect(RuleSet::from(['items.*.tags' => FluentRule::array()->each(FluentRule::string()->nullable())])->check($data)->errors()->keys())
        ->toBe(validator($data, ['items.*.tags' => 'array', 'items.*.tags.*' => 'nullable|string'])->errors()->keys());
});

it('checks a dotted field inside an item like native', function (mixed $topRule, array $item): void {
    assertPerItemParity(['items.*.style' => 'nullable|array', 'items.*.style.top' => $topRule], ['items' => [$item]]);
})->with([
    'string form, bad value' => ['nullable|string', ['style' => ['top' => 5]]],
    'array form, bad value' => [['nullable', 'string'], ['style' => ['top' => 5]]],
    'style absent' => ['nullable|string', []],
    'style not an array' => ['nullable|string', ['style' => 'x']],
    'required, style absent' => ['required|string', []],
]);

it('keeps a regex with a pipe intact after per-item reduction', function (string $code): void {
    assertPerItemParity(
        ['items.*.type' => 'nullable', 'items.*.code' => [['required_if', 'items.*.type', 'a'], 'regex:/^(foo|bar)$/']],
        ['items' => [['type' => 'a', 'code' => $code]]],
    );
})->with(['foo', 'zzz']);

it('keeps a boolean dependency for other value conditionals on a slow field', function (array $urlRule, array $item, bool $withDispatch): void {
    $slow = new class implements ValidationRule {
        public function validate(string $attribute, mixed $value, Closure $fail): void {}
    };
    $rules = ['items.*.type' => 'nullable', 'items.*.flag' => 'boolean', 'items.*.url' => [...($withDispatch ? ['exclude_if:items.*.flag,skip'] : []), ...$urlRule, $slow]];

    assertPerItemParity($rules, ['items' => [$item]]);
})->with([
    'required_if false, "0"' => [['required_if:items.*.flag,false'], ['flag' => '0'], false],
    'prohibited_if true, "1"' => [['prohibited_if:items.*.flag,true'], ['flag' => '1', 'url' => 'x'], false],
    'required_unless true, "0"' => [['required_unless:items.*.flag,true'], ['flag' => '0'], false],
    'required_if true, "1", dispatch cache' => [['required_if:items.*.flag,true'], ['flag' => '1'], true],
]);

it('checks a dotted child under a nested wildcard like native', function (): void {
    assertPerItemParity(
        ['items.*.groups' => 'array', 'items.*.groups.*.profile.name' => ['nullable', 'string']],
        ['items' => [['groups' => [['profile' => ['name' => 5]]]]]],
    );
});

it('compares dates in the date_format of the rule like native', function (string $date): void {
    assertPerItemParity(
        ['items.*.date' => ['date_format:Y-m-d', 'after:2025-01-01']],
        ['items' => [['date' => $date]]],
    );
})->with(['2020-01-01', '2026-01-01']);

it('keeps a dependency date_format for a sibling after rule like native', function (array $item): void {
    assertPerItemParity(
        ['items.*.start' => ['date_format:d/m/Y'], 'items.*.end' => ['date_format:Y-m-d', 'after:items.*.start']],
        ['items' => [$item]],
    );
})->with([
    'end before start' => [['start' => '01/12/2025', 'end' => '2025-06-01']],
    'end after start' => [['start' => '01/12/2025', 'end' => '2026-06-01']],
]);

it('checks several fields of nested wildcard elements of an item like native', function (array $lines): void {
    assertPerItemParity(
        ['orders.*.lines' => 'array', 'orders.*.lines.*.id' => 'required|integer', 'orders.*.lines.*.qty' => 'nullable|integer'],
        ['orders' => [['lines' => [['id' => 1, 'qty' => 2]]], ['lines' => $lines]]],
    );
})->with([
    'valid elements' => [[['id' => 1, 'qty' => 2], ['id' => 3]]],
    'bad first field' => [[['id' => 1], ['id' => 'x', 'qty' => 2]]],
    'bad second field' => [[['id' => 1, 'qty' => 'x']]],
    'missing required field' => [[['qty' => 2]]],
    'scalar element' => [[['id' => 1], 5]],
]);

it('checks number bounds of several nested fields like native', function (array $line): void {
    assertPerItemParity(
        ['orders.*.lines' => 'array', 'orders.*.lines.*.id' => 'required|integer|min:1|max:100', 'orders.*.lines.*.price' => 'required|numeric|min:0.5|max:9.5', 'orders.*.lines.*.qty' => 'nullable|integer|min:1'],
        ['orders' => [['lines' => [['id' => 5, 'price' => 1.5, 'qty' => 2], $line]]]],
    );
})->with([
    'in bounds' => [['id' => 100, 'price' => 9.5, 'qty' => null]],
    'int below the minimum' => [['id' => 0, 'price' => 1, 'qty' => 1]],
    'int above the maximum' => [['id' => 101, 'price' => 1, 'qty' => 1]],
    'float below the minimum' => [['id' => 1, 'price' => 0.25, 'qty' => 1]],
    'float above the maximum' => [['id' => 1, 'price' => 9.75, 'qty' => 1]],
    'float for an integer field' => [['id' => 5.0, 'price' => 1, 'qty' => 1]],
    'numeric string' => [['id' => '7', 'price' => '2.5', 'qty' => '3']],
    'numeric string out of bounds' => [['id' => '700', 'price' => '2.5', 'qty' => 1]],
    'missing required field' => [['price' => 1, 'qty' => 1]],
    'null optional field' => [['id' => 1, 'price' => 1, 'qty' => null]],
    'int below the minimum of a field without a maximum' => [['id' => 1, 'price' => 1, 'qty' => 0]],
]);

it('checks number minimums of several nested fields like native', function (mixed $line): void {
    assertPerItemParity(
        ['orders.*.lines' => 'array', 'orders.*.lines.*.id' => 'required|integer|min:1', 'orders.*.lines.*.price' => 'required|numeric|min:0.5'],
        ['orders' => [['lines' => [['id' => 5, 'price' => 1.5], $line]]]],
    );
})->with([
    'in bounds' => [['id' => 1, 'price' => 0.5]],
    'int below the minimum' => [['id' => 0, 'price' => 1]],
    'float below the minimum' => [['id' => 1, 'price' => 0.25]],
    'float for an integer field' => [['id' => 5.0, 'price' => 1]],
    'non-whole float for an integer field' => [['id' => 5.5, 'price' => 1]],
    'string' => [['id' => 'x', 'price' => 1]],
    'scalar line' => [5],
]);
