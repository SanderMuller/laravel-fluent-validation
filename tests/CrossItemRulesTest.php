<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Validation\ValidationException;
use SanderMuller\FluentValidation\FluentFormRequest;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\Rules\ArrayRule;
use SanderMuller\FluentValidation\Rules\DateRule;
use SanderMuller\FluentValidation\Rules\EmailRule;
use SanderMuller\FluentValidation\Rules\FieldRule;
use SanderMuller\FluentValidation\RuleSet;
use SanderMuller\FluentValidation\Tests\Fixtures\TestStringEnum;

// =========================================================================
// distinct() / inArray() on field(), enum(), email() and date()
// =========================================================================

function crossItemRule(string $name): ArrayRule|DateRule|EmailRule|FieldRule
{
    return match ($name) {
        'array' => FluentRule::array(),
        'field' => FluentRule::field(),
        'enum' => FluentRule::enum(TestStringEnum::class),
        'email' => FluentRule::email(),
        'date' => FluentRule::date(),
        default => throw new InvalidArgumentException($name),
    };
}

dataset('crossItemBuilders', [
    'field' => ['field', 'a', 'b'],
    'enum' => ['enum', 'active', 'inactive'],
    'email' => ['email', 'a@example.com', 'b@example.com'],
    'date' => ['date', '2026-01-01', '2026-01-02'],
]);

it('rejects duplicates with distinct() on a flat wildcard key', function (string $builder, string $first): void {
    RuleSet::from([
        'items' => FluentRule::array()->required(),
        'items.*' => crossItemRule($builder)->required()->distinct('strict'),
    ])->validate(['items' => [$first, $first]]);
})->with('crossItemBuilders')->throws(ValidationException::class);

it('accepts unique values with distinct() on a flat wildcard key', function (string $builder, string $first, string $second): void {
    $validated = RuleSet::from([
        'items' => FluentRule::array()->required(),
        'items.*' => crossItemRule($builder)->required()->distinct('strict'),
    ])->validate(['items' => [$first, $second]]);

    expect($validated['items'])->toBe([$first, $second]);
})->with('crossItemBuilders');

it('checks distinct() inside each()', function (string $builder, string $first, string $second): void {
    $rules = ['items' => FluentRule::array()->required()->each(crossItemRule($builder)->distinct())];

    expect(RuleSet::from($rules)->validate(['items' => [$first, $second]])['items'])->toBe([$first, $second])
        ->and(fn () => RuleSet::from($rules)->validate(['items' => [$first, $first]]))
        ->toThrow(ValidationException::class);
})->with('crossItemBuilders');

it('checks distinct() in a FluentFormRequest (issue #22)', function (string $builder, string $first, string $second): void {
    $validate = function (array $items) use ($builder): void {
        $formRequest = new class extends FluentFormRequest {
            public static string $builder = '';

            /** @return array<string, mixed> */
            public function rules(): array
            {
                return ['items.*' => crossItemRule(self::$builder)->required()->distinct('strict')];
            }
        };
        $formRequest::$builder = $builder;

        $instance = $formRequest::createFrom(Request::create('/test', 'POST', ['items' => $items]));
        $instance->setContainer(app());
        $instance->setRedirector(resolve(Redirector::class));
        $instance->validateResolved();
    };

    $validate([$first, $second]);

    expect(fn () => $validate([$first, $first]))->toThrow(ValidationException::class);
})->with('crossItemBuilders');

it('checks inArray() against another field', function (string $builder, string $first, string $second): void {
    $rules = ['allowed' => FluentRule::array(), 'value' => crossItemRule($builder)->inArray('allowed.*')];

    expect(RuleSet::from($rules)->validate(['allowed' => [$first], 'value' => $first])['value'])->toBe($first)
        ->and(fn () => RuleSet::from($rules)->validate(['allowed' => [$first], 'value' => $second]))
        ->toThrow(ValidationException::class);
})->with('crossItemBuilders');

it('checks inArray() on wildcard items through the Laravel validator', function (string $builder, string $first, string $second): void {
    $rules = ['items.*' => crossItemRule($builder)->inArray('allowed.*')];

    expect(makeValidator(['allowed' => [$first], 'items' => [$first]], $rules)->passes())->toBeTrue()
        ->and(makeValidator(['allowed' => [$first], 'items' => [$second]], $rules)->passes())->toBeFalse();
})->with('crossItemBuilders');

it('passes the inline message to distinct() and inArray()', function (string $builder): void {
    expect(crossItemRule($builder)->distinct(message: 'dup')->getCustomMessages())->toBe(['distinct' => 'dup'])
        ->and(crossItemRule($builder)->inArray('allowed.*', message: 'nope')->getCustomMessages())->toBe(['in_array' => 'nope']);
})->with('crossItemBuilders');

// =========================================================================
// inArrayKeys() — the value itself must be an array with one of the keys
// =========================================================================

dataset('arrayValuedBuilders', [
    'array' => ['array'],
    'field' => ['field'],
]);

it('validates inArrayKeys() against the keys of the value', function (string $builder): void {
    expect(makeValidator(['v' => ['b' => 1]], ['v' => crossItemRule($builder)->inArrayKeys('a', 'b')])->passes())->toBeTrue()
        ->and(makeValidator(['v' => ['c' => 1]], ['v' => crossItemRule($builder)->inArrayKeys('a', 'b')])->passes())->toBeFalse()
        ->and(makeValidator(['v' => 'a'], ['v' => crossItemRule($builder)->inArrayKeys('a', 'b')])->passes())->toBeFalse();
})->with('arrayValuedBuilders');

it('validates inArrayKeys() through RuleSet on wildcard items', function (string $builder): void {
    $rules = ['items' => FluentRule::array()->required()->each(crossItemRule($builder)->inArrayKeys('id'))];

    expect(RuleSet::from($rules)->validate(['items' => [['id' => 1], ['id' => 2]]])['items'])
        ->toBe([['id' => 1], ['id' => 2]])
        ->and(fn () => RuleSet::from($rules)->validate(['items' => [['id' => 1], ['name' => 'x']]]))
        ->toThrow(ValidationException::class);
})->with('arrayValuedBuilders');

// =========================================================================
// confirmed() on date() — every other scalar builder already has it
// =========================================================================

it('validates confirmed() on date()', function (): void {
    expect(FluentRule::date()->confirmed()->compiledRules())->toBe('date|confirmed')
        ->and(makeValidator(['d' => '2026-01-01', 'd_confirmation' => '2026-01-01'], ['d' => FluentRule::date()->confirmed()])->passes())->toBeTrue()
        ->and(makeValidator(['d' => '2026-01-01', 'd_confirmation' => '2026-01-02'], ['d' => FluentRule::date()->confirmed()])->passes())->toBeFalse();
});
