<?php declare(strict_types=1);

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Validation\Validator;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\Internal\OutsideItemReferences;
use SanderMuller\FluentValidation\RuleSet;

// =========================================================================
// RuleSet validates a wildcard item with the item's data only, and reads a
// bare field name as a sibling. When the top-level data has that field,
// Laravel reads the top-level field, so the group goes to full expansion.
// =========================================================================

/**
 * @param  array<array-key, mixed>  $array
 * @return array<string, mixed>
 */
function stringKeyed(array $array): array
{
    return array_filter($array, is_string(...), ARRAY_FILTER_USE_KEY);
}

/**
 * @param  array<array-key, mixed>  $groupRules
 */
function readsOutsideItems(array $groupRules): bool
{
    return OutsideItemReferences::exist(stringKeyed($groupRules), 'items', ['mode' => 'strict', 'order_date' => '2025-06-01', 'items' => []]);
}

/**
 * @param  array<array-key, mixed>  $fluent
 * @param  array<array-key, mixed>  $native
 * @param  array<array-key, mixed>  $data
 */
function assertOutsideReferenceParity(array $fluent, array $native, array $data): void
{
    $data = stringKeyed($data);

    expect(RuleSet::from(stringKeyed($fluent))->check($data)->errors()->keys())
        ->toBe(validator($data, $native)->errors()->keys());
}

it('reports the same errors as native for references inside each()', function (array $fluent, array $native, array $data): void {
    assertOutsideReferenceParity($fluent, $native, $data);
})->with([
    'top-level date reference' => [
        ['order_date' => FluentRule::date()->required(), 'items' => FluentRule::array()->required()->each(['d' => FluentRule::date()->required()->after('order_date')])],
        ['order_date' => 'required|date', 'items' => 'required|array', 'items.*.d' => 'required|date|after:order_date'],
        ['order_date' => '2025-06-01', 'items' => [['d' => '2025-01-01'], ['d' => '2025-09-01']]],
    ],
    'top-level reference in a string rule' => [
        ['order_date' => 'required|date', 'items' => 'required|array', 'items.*.d' => 'required|date|after:order_date'],
        ['order_date' => 'required|date', 'items' => 'required|array', 'items.*.d' => 'required|date|after:order_date'],
        ['order_date' => '2025-06-01', 'items' => [['d' => '2025-01-01']]],
    ],
    'top-level conditional' => [
        ['mode' => FluentRule::string()->required(), 'items' => FluentRule::array()->required()->each(['x' => FluentRule::string()->requiredIf('mode', 'strict')])],
        ['mode' => 'required|string', 'items' => 'required|array', 'items.*.x' => 'required_if:mode,strict|string'],
        ['mode' => 'strict', 'items' => [['y' => 1]]],
    ],
    'bare name that is a sibling and a top-level field' => [
        ['start_date' => FluentRule::date()->required(), 'items' => FluentRule::array()->required()->each(['start_date' => FluentRule::date()->required(), 'd' => FluentRule::date()->required()->before('start_date')])],
        ['start_date' => 'required|date', 'items' => 'required|array', 'items.*.start_date' => 'required|date', 'items.*.d' => 'required|date|before:start_date'],
        ['start_date' => '2025-01-01', 'items' => [['start_date' => '2025-06-01', 'd' => '2025-03-01']]],
    ],
    'sibling path whose name parses as a date' => [
        ['items' => FluentRule::array()->required()->each(['s' => FluentRule::date()->required(), 'd' => FluentRule::date()->required()->after('items.*.s')])],
        ['items' => 'required|array', 'items.*.s' => 'required|date', 'items.*.d' => 'required|date|after:items.*.s'],
        ['items' => [['s' => '2025-01-01', 'd' => '2025-06-01']]],
    ],
    'sibling path' => [
        ['items' => FluentRule::array()->required()->each(['start' => FluentRule::date()->required(), 'end' => FluentRule::date()->required()->after('items.*.start')])],
        ['items' => 'required|array', 'items.*.start' => 'required|date', 'items.*.end' => 'required|date|after:items.*.start'],
        ['items' => [['start' => '2025-01-01', 'end' => '2025-06-01'], ['start' => '2025-06-01', 'end' => '2025-01-01']]],
    ],
]);

it('keeps the per-item path for references inside the item and literal values', function (array $groupRules): void {
    expect(readsOutsideItems($groupRules))->toBeFalse();
})->with([
    'sibling path' => [['end' => 'date|after:items.*.start']],
    'sibling path in a tuple' => [['x' => [['required_if', 'items.*.type', 'a']]]],
    'literal date' => [['d' => 'date|after:2025-01-01|before:tomorrow']],
    'numeric size' => [['n' => 'numeric|gt:10']],
    'comparison values of a conditional' => [['x' => 'required_if:items.*.type,order_date']],
    'unique table and column' => [['email' => 'unique:users,email']],
    'rules that name no field' => [['name' => 'required|string|in:order_date']],
    'bare sibling name not in the top-level data' => [['x' => 'required_if:flag,admin']],
]);

it('sends a group with a field outside the item to full expansion', function (array $groupRules): void {
    expect(readsOutsideItems($groupRules))->toBeTrue();
})->with([
    'top-level date reference' => [['d' => 'date|after:order_date']],
    'top-level conditional' => [['x' => 'required_if:mode,strict']],
    'top-level conditional in a tuple' => [['x' => [['required_if', 'mode', 'strict']]]],
    'reference into another group' => [['x' => 'same:other.*.x']],
    'sibling path whose name parses as a date' => [['d' => 'date|after:items.*.now']],
    'fluent object' => [['d' => FluentRule::date()->after('order_date')]],
    'rule list with a validator-aware object' => [['d' => ['date', 'after:order_date', new class implements ValidationRule, ValidatorAwareRule {
        public function setValidator(Validator $validator): static
        {
            return $this;
        }

        public function validate(string $attribute, mixed $value, Closure $fail): void {}
    }]]],
]);
