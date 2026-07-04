<?php declare(strict_types=1);

use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Validator as BaseValidator;
use SanderMuller\FluentValidation\Internal\ValidatorStateCopier;

/**
 * @param  array<array-key, mixed>  $data
 * @param  array<array-key, mixed>  $rules
 */
function stateCopierValidator(array $data, array $rules): BaseValidator
{
    return new BaseValidator(new Translator(new ArrayLoader(), 'en'), $data, $rules);
}

function readValidatorProperty(BaseValidator $validator, string $property): mixed
{
    return (new ReflectionProperty(BaseValidator::class, $property))->getValue($validator);
}

it('copies wildcard implicitAttributes so expansion metadata pairs with the copied rules', function (): void {
    // A wildcard rule populates implicitAttributes during explode. The target is
    // built empty (as ItemValidator / HasFluentRules do to skip re-parsing), so
    // without copying this metadata a nested-wildcard per-item validator would
    // lose the pattern→path mapping `:attribute` naming and exists/unique column
    // derivation depend on.
    $base = stateCopierValidator(['tags' => [['label' => 'a'], ['label' => 'b']]], ['tags.*.label' => 'required']);
    $target = stateCopierValidator([], []);

    ValidatorStateCopier::copy($base, $target);

    $baseImplicit = readValidatorProperty($base, 'implicitAttributes');

    expect($baseImplicit)->not->toBeEmpty(); // guard: the base really has wildcard metadata
    expect(readValidatorProperty($target, 'implicitAttributes'))->toBe($baseImplicit);
});

it('copies the exploded rules onto the target', function (): void {
    $base = stateCopierValidator(['name' => 'x'], ['name' => 'required|string']);
    $target = stateCopierValidator([], []);

    ValidatorStateCopier::copy($base, $target);

    expect(readValidatorProperty($target, 'rules'))
        ->toBe(readValidatorProperty($base, 'rules'))
        ->not->toBeEmpty();
});
