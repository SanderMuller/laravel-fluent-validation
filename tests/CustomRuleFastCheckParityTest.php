<?php declare(strict_types=1);

use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use SanderMuller\FluentValidation\RuleSet;

// =========================================================================
// RuleSet fast-checks a field whose rules mix strings with plain
// ValidationRule objects. It must run an object exactly when Laravel would,
// and report the same errors and messages.
// =========================================================================

final class RejectsBRule implements ValidationRule
{
    public static int $calls = 0;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        ++self::$calls;

        if ($value === 'b') {
            $fail('The :attribute must not be b.')->translate();
        }
    }
}

final class ImplicitRejectsNullRule implements ValidationRule
{
    public bool $implicit = true;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            $fail('The :attribute is needed.');
        }
    }
}

final class DataAwareRejectsBRule implements DataAwareRule, ValidationRule
{
    public static int $dataSets = 0;

    /** @param  array<mixed>  $data */
    public function setData(array $data): static
    {
        ++self::$dataSets;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === 'b') {
            $fail('The :attribute must not be b.');
        }
    }
}

/**
 * @param  array<string, mixed>  $rules
 * @param  array<mixed>  $items
 */
function assertCustomRuleParity(array $rules, array $items): void
{
    $data = ['items' => $items];

    expect(RuleSet::from($rules)->check($data)->errors()->keys())
        ->toBe(validator($data, $rules)->errors()->keys());
}

it('runs a custom rule only when Laravel would', function (array $rule, array $item): void {
    assertCustomRuleParity(['items.*.v' => $rule], [$item]);
})->with([
    'value passes' => [['string', new RejectsBRule()], ['v' => 'a']],
    'value fails' => [['string', new RejectsBRule()], ['v' => 'b']],
    'field absent' => [['string', new RejectsBRule()], []],
    'blank string' => [[new RejectsBRule()], ['v' => '  ']],
    'null without nullable' => [[new RejectsBRule()], ['v' => null]],
    'null with nullable' => [['nullable', new RejectsBRule()], ['v' => null]],
    'implicit rule, field absent' => [[new ImplicitRejectsNullRule()], []],
    'data-aware rule, fails' => [['string', new DataAwareRejectsBRule()], ['v' => 'b']],
    'data-aware rule, passes' => [['string', new DataAwareRejectsBRule()], ['v' => 'a']],
]);

it('reports the custom rule message like the per-item validator', function (): void {
    $data = ['items' => [['v' => 'a'], ['v' => 'b']]];

    expect(RuleSet::from(['items.*.v' => ['required', 'string', new RejectsBRule()]])->check($data)->errors()->toArray())
        ->toBe(['items.1.v' => ['The items.1.v must not be b.']]);
});

it('runs a custom rule once per passing item', function (): void {
    RejectsBRule::$calls = 0;

    RuleSet::from(['items.*.v' => ['required', 'string', new RejectsBRule()]])->check(['items' => [['v' => 'a'], ['v' => 'c']]]);

    expect(RejectsBRule::$calls)->toBe(2);
});

it('leaves a data-aware rule to Laravel, which gives it the data', function (): void {
    DataAwareRejectsBRule::$dataSets = 0;

    RuleSet::from(['items.*.v' => ['required', 'string', new DataAwareRejectsBRule()]])->check(['items' => [['v' => 'a']]]);

    expect(DataAwareRejectsBRule::$dataSets)->toBeGreaterThan(0);
});
