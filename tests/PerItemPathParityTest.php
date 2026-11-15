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
