<?php declare(strict_types=1);

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

// Hint-table parity — one assertion per row. Source of truth: TypedBuilderHint::for().
// When the table changes, this dataset changes in lockstep.
dataset('hintTableRows', [
    ['min', 'FluentRule::numeric()', '->min(...)'],
    ['max', 'FluentRule::numeric()', '->max(...)'],
    ['between', 'FluentRule::numeric()', '->between(...)'],
    ['exactly', "Laravel's `size:` rule", '->exactly(...)'],
    ['size', "renames Laravel's `size:` rule to `exactly()`", '->exactly(...)'],
    ['gt', '->greaterThan(FIELD)', 'FluentRule::numeric()'],
    ['gte', '->greaterThanOrEqualTo(FIELD)', 'FluentRule::numeric()'],
    ['lt', '->lessThan(FIELD)', 'FluentRule::numeric()'],
    ['lte', '->lessThanOrEqualTo(FIELD)', 'FluentRule::numeric()'],
    ['digits', 'FluentRule::numeric()', '->digits(...)'],
    ['digitsBetween', 'FluentRule::numeric()', '->digitsBetween(...)'],
    ['decimal', 'FluentRule::numeric()', '->decimal(...)'],
    ['multipleOf', 'FluentRule::numeric()', '->multipleOf(...)'],
    ['integer', 'FluentRule::numeric()', '->integer(...)'],
    ['email', 'FluentRule::string()', '->email(...)'],
    ['url', 'FluentRule::string()', '->url(...)'],
    ['uuid', 'FluentRule::string()', '->uuid(...)'],
    ['ulid', 'FluentRule::string()', '->ulid(...)'],
    ['ip', 'FluentRule::string()', '->ip(...)'],
    ['regex', 'FluentRule::string()', '->regex(...)'],
    ['alpha', 'FluentRule::string()', '->alpha(...)'],
    ['alphaDash', 'FluentRule::string()', '->alphaDash(...)'],
    ['startsWith', 'FluentRule::string()', '->startsWith(...)'],
    ['endsWith', 'FluentRule::string()', '->endsWith(...)'],
    ['lowercase', 'FluentRule::string()', '->lowercase(...)'],
    ['uppercase', 'FluentRule::string()', '->uppercase(...)'],
    ['json', 'FluentRule::string()', '->json(...)'],
    ['ascii', 'FluentRule::string()', '->ascii(...)'],
    ['dateFormat', 'FluentRule::string()', '->dateFormat(...)'],
    ['alphaNum', 'FluentRule::string()->alphaNumeric(...)', 'alphaNumeric'],
    ['contains', 'FluentRule::array()->contains(...)', '`array()`'],
    ['before', 'FluentRule::date()', '->before(...)'],
    ['after', 'FluentRule::date()', '->after(...)'],
    ['beforeOrEqual', 'FluentRule::date()', '->beforeOrEqual(...)'],
    ['afterOrEqual', 'FluentRule::date()', '->afterOrEqual(...)'],
    ['nowOrFuture', 'FluentRule::date()', '->nowOrFuture(...)'],
    ['nowOrPast', 'FluentRule::date()', '->nowOrPast(...)'],
    ['format', 'FluentRule::date()->format(...)', 'FluentRule::string()->regex(...)'],
    ['accepted', 'FluentRule::accepted()', 'rejects'],
    ['declined', 'FluentRule::boolean()->declined(...)', "->rule('declined')"],
    ['mimes', 'FluentRule::file()', '->mimes(...)'],
    ['mimetypes', 'FluentRule::file()', '->mimetypes(...)'],
    ['extensions', 'FluentRule::file()', '->extensions(...)'],
    ['dimensions', 'FluentRule::image()', '->dimensions(...)'],
]);

it('includes the hint text in the thrown exception message', function (string $method, string $needle1, string $needle2): void {
    $message = UnknownFluentRuleMethod::on($method)->getMessage();

    expect($message)->toContain($method)
        ->toContain($needle1)
        ->toContain($needle2);
})->with('hintTableRows');
