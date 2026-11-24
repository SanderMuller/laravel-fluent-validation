<?php declare(strict_types=1);

use Illuminate\Support\Fluent;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use SanderMuller\FluentValidation\OptimizedValidator;

/** @return iterable<string, array{array<string, mixed>, array<string, mixed>}> */
function validatedParityCases(): iterable
{
    yield 'nested keys under an array rule, with unvalidated keys' => [
        ['items' => [['a' => 'x', 'junk' => 1], ['a' => 'y']], 'extra' => 1],
        ['items' => 'array', 'items.*.a' => 'string'],
    ];
    yield 'an array rule without rules below it, and a list rule with one' => [
        ['tags' => ['a', 'b'], 'meta' => ['v', 'w']],
        ['tags' => 'array', 'meta' => 'list', 'meta.0' => 'string'],
    ];
    yield 'a null value under an array rule' => [
        ['items' => null],
        ['items' => 'nullable|array', 'items.0' => 'nullable|string'],
    ];
    yield 'a parent rule after its child rule' => [
        ['a' => ['b' => 1, 'c' => 2]],
        ['a.b' => 'integer', 'a' => 'nullable'],
    ];
    yield 'a scalar and a null on the path' => [
        ['a' => 'text', 'n' => null],
        ['a' => 'nullable', 'a.b' => 'nullable', 'n.b' => 'nullable'],
    ];
    yield 'an escaped dot and an asterisk in a data key' => [
        ['a.b' => 1, 'c*d' => ['e' => 2]],
        ['a\.b' => 'integer', 'c*d' => 'array'],
    ];
    yield 'an object on the path' => [
        ['obj' => new Fluent(['x' => 1])],
        ['obj' => 'nullable', 'obj.x' => 'integer'],
    ];
    yield 'a key that data_get() reads in its own way' => [
        ['items' => ['x', 'y']],
        ['items.{first}' => 'string'],
    ];
    yield 'list keys' => [
        ['list' => ['x', 'y']],
        ['list.0' => 'string', 'list.1' => 'string', 'list.2' => 'nullable'],
    ];
}

it('returns the validated data of Laravel', function (array $data, array $rules, bool $excludeUnvalidated): void {
    $translator = new Translator(new ArrayLoader(), 'en');
    $native = new Validator($translator, $data, $rules);
    $optimized = new OptimizedValidator($translator, $data, $rules);
    $native->excludeUnvalidatedArrayKeys = $excludeUnvalidated;
    $optimized->excludeUnvalidatedArrayKeys = $excludeUnvalidated;

    expect($optimized->validated())->toBe($native->validated());
})->with(validatedParityCases(...))->with(['excludes unvalidated keys' => true, 'keeps unvalidated keys' => false]);

it('throws as Laravel does when validation fails', function (): void {
    $validator = new OptimizedValidator(new Translator(new ArrayLoader(), 'en'), ['a' => 'x'], ['a' => 'integer']);

    expect(fn (): array => $validator->validated())->toThrow(ValidationException::class);
});
