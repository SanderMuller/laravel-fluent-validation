<?php declare(strict_types=1);

use Illuminate\Contracts\Validation\ValidationRule;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\RuleSet;

// =========================================================================
// RuleSet rewrites an active presence or value conditional to `required`
// for the fast check. A failing item must still get Laravel's message for
// the original rule. Attribute names stay item-relative (`url`, `type`).
// =========================================================================

it('reports the conditional message for a failing item', function (mixed $urlRule): void {
    $data = ['items' => [['type' => 'image'], ['type' => 'video'], ['type' => 'image', 'url' => 'x']]];
    $rules = ['items.*.type' => 'nullable', 'items.*.kind' => 'nullable', 'items.*.url' => $urlRule];

    expect(RuleSet::from($rules)->check($data)->errors()->toArray())
        ->toBe(['items.0.url' => ['The url field is required when type is image.']]);
})->with([
    'tuple' => [[['required_if', 'items.*.type', 'image'], 'string']],
    'string' => ['required_if:items.*.type,image|string'],
    'FluentRule' => [FluentRule::string()->requiredIf('items.*.type', 'image')],
    'with an exclude on the dispatch field' => [['exclude_unless:items.*.type,image,video', 'required_if:items.*.type,image', 'string']],
    'reduced per item' => [['exclude_if:items.*.kind,b', 'required_if:items.*.type,image', 'string']],
]);

it('reports the conditional message when the item also has a slow rule', function (): void {
    $slow = new class implements ValidationRule {
        public function validate(string $attribute, mixed $value, Closure $fail): void {}
    };

    $data = ['items' => [['type' => 'image'], ['type' => 'video']]];
    $rules = ['items.*.type' => 'nullable', 'items.*.url' => ['required_if:items.*.type,image', $slow]];

    expect(RuleSet::from($rules)->check($data)->errors()->toArray())
        ->toBe(['items.0.url' => ['The url field is required when type is image.']]);
});

it('reports the prohibited_unless message for a failing item', function (): void {
    $data = ['items' => [['type' => 'video', 'url' => 'x'], ['type' => 'image', 'url' => 'x']]];
    $rules = ['items.*.type' => 'nullable', 'items.*.url' => 'prohibited_unless:items.*.type,image|nullable|string'];

    expect(RuleSet::from($rules)->check($data)->errors()->toArray())
        ->toBe(['items.0.url' => ['The url field is prohibited unless type is in image.']]);
});

it('keeps a boolean dependency for the Laravel validator of a slow conditional field', function (array $item): void {
    $slow = new class implements ValidationRule {
        public function validate(string $attribute, mixed $value, Closure $fail): void {}
    };

    $data = ['items' => [$item]];
    $rules = ['items.*.flag' => 'boolean', 'items.*.url' => ['required_if:items.*.flag,true', $slow]];

    expect(RuleSet::from($rules)->check($data)->errors()->keys())
        ->toBe(validator($data, $rules)->errors()->keys());
})->with([
    'flag sent as "1"' => [['flag' => '1']],
    'flag sent as "0"' => [['flag' => '0']],
]);
