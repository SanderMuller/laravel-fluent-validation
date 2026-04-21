<?php declare(strict_types=1);

use SanderMuller\FluentValidation\Exceptions\TypedBuilderHint;
use SanderMuller\FluentValidation\Exceptions\UnknownFluentRuleMethod;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\Rules\FieldRule;

afterEach(function (): void {
    FieldRule::flushMacros();
});

it('throws UnknownFluentRuleMethod when an undefined method is called on field()', function (): void {
    FluentRule::field()->min(5);
})->throws(UnknownFluentRuleMethod::class);

it('extends BadMethodCallException so existing catches keep working', function (): void {
    $caught = null;

    try {
        throw UnknownFluentRuleMethod::on('min');
    } catch (BadMethodCallException $badMethodCallException) {
        $caught = $badMethodCallException;
    }

    expect($caught)->not->toBeNull();
});

it('names the called method in the message', function (): void {
    $exception = UnknownFluentRuleMethod::on('min');

    expect($exception->getMessage())
        ->toContain('FluentRule::field() has no method min()');
});

it('falls back to a generic hint for methods not in the table', function (): void {
    $message = UnknownFluentRuleMethod::on('doesNotExistAnywhere')->getMessage();

    expect($message)->toContain('Use a typed builder');
});

it('preserves registered macros after the override', function (): void {
    FieldRule::macro('customMacro', fn (string $arg): string => 'macro:' . $arg);

    $builder = FluentRule::field();

    expect($builder->__call('customMacro', ['ok']))->toBe('macro:ok');
});

it('routes unregistered __callStatic to the typed exception', function (): void {
    FieldRule::__callStatic('doesNotExistStatic', []);
})->throws(UnknownFluentRuleMethod::class);

it('dispatches macros registered statically via __callStatic', function (): void {
    FieldRule::macro('staticMacro', fn (): string => 'static-ok');

    expect(FieldRule::__callStatic('staticMacro', []))->toBe('static-ok');
});

it('dispatches non-Closure invokable macros (e.g. object with __invoke)', function (): void {
    $invokable = new class {
        public function __invoke(string $arg): string
        {
            return 'invokable:' . $arg;
        }
    };

    FieldRule::macro('invokableMacro', $invokable);

    expect(FluentRule::field()->__call('invokableMacro', ['x']))->toBe('invokable:x');
});

// Coverage invariant — every method on a typed builder that is NOT on
// FieldRule must produce a non-null hint, and that hint must appear
// verbatim in the thrown exception message. The source of truth is
// reflection on the typed builder classes, so new rule methods added
// later are automatically exercised here.
dataset('knownFootgunMethods', fn (): array => array_map(
    static fn (string $method): array => [$method],
    TypedBuilderHint::knownMethods(),
));

it('produces a non-null hint for every known footgun method', function (string $method): void {
    expect(TypedBuilderHint::for($method))->not->toBeNull();
})->with('knownFootgunMethods');

it('includes the method name and the hint in the thrown exception message', function (string $method): void {
    $hint = TypedBuilderHint::for($method);
    $message = UnknownFluentRuleMethod::on($method)->getMessage();

    expect($message)
        ->toContain($method)
        ->toContain($hint);
})->with('knownFootgunMethods');

// Spot checks — anchor specific hint wording so reflection changes that
// accidentally drop a well-known typed builder from a hint are flagged.
it('points `accepted` at the dedicated FluentRule::accepted() factory (not boolean)', function (): void {
    $hint = TypedBuilderHint::for('accepted');

    expect($hint)
        ->toContain('FluentRule::accepted()')
        ->toContain('rejects')
        ->not->toStartWith('Use `FluentRule::boolean()->accepted()`');
});

it('points `size` at the renamed `exactly` method', function (): void {
    expect(TypedBuilderHint::for('size'))->toContain('exactly');
});

it('names every typed builder hosting `min` (string/numeric/array/file/image/password)', function (): void {
    $hint = TypedBuilderHint::for('min');

    expect($hint)
        ->toContain('FluentRule::string()')
        ->toContain('FluentRule::numeric()')
        ->toContain('FluentRule::array()')
        ->toContain('FluentRule::file()')
        ->toContain('FluentRule::image()')
        ->toContain('FluentRule::password()');
});

it('covers the typed-only methods the prior hand-coded table missed', function (string $method): void {
    expect(TypedBuilderHint::knownMethods())->toContain($method);
})->with([
    'ipv4', 'ipv6', 'macAddress', 'notRegex', 'timezone', 'hexColor',
    'currentPassword', 'maxDigits', 'minDigits',
    'beforeToday', 'afterToday', 'betweenOrEqual', 'dateEquals',
    'acceptedIf', 'declinedIf', 'allowSvg', 'width', 'ratio',
]);
