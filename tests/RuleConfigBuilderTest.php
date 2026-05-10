<?php declare(strict_types=1);

use SanderMuller\FluentValidation\FastCheck\CoreValueCompiler;
use SanderMuller\FluentValidation\FastCheck\ItemContextCompiler;
use SanderMuller\FluentValidation\FastCheck\RuleConfigBuilder;

/**
 * Direct unit coverage for {@see RuleConfigBuilder} and the bailout
 * branches of {@see ItemContextCompiler} that the perf benchmark cannot
 * detect. Pins behaviour the cross-class refactor must preserve.
 */
it('parseValuePart compiles a basic type rule', function (): void {
    $config = RuleConfigBuilder::initialConfig();
    $result = RuleConfigBuilder::parseValuePart('string', $config);

    expect($result)->toBeArray();
    /** @var array<string, mixed> $result */
    expect($result['string'])->toBeTrue();
});

it('parseValuePart compiles a min:N rule', function (): void {
    $config = RuleConfigBuilder::initialConfig();
    $result = RuleConfigBuilder::parseValuePart('min:5', $config);

    expect($result)->toBeArray();
    /** @var array<string, mixed> $result */
    expect($result['min'])->toBe(5);
});

it('parseValuePart returns null on sometimes (unsupported)', function (): void {
    $config = RuleConfigBuilder::initialConfig();
    $result = RuleConfigBuilder::parseValuePart('sometimes', $config);

    expect($result)->toBeNull();
});

it('validateSizeRuleHasType requires a type when min set', function (): void {
    $config = [...RuleConfigBuilder::initialConfig(), 'min' => 5];

    expect(RuleConfigBuilder::validateSizeRuleHasType($config))->toBeFalse();
});

it('validateSizeRuleHasType passes with string + min', function (): void {
    $config = [...RuleConfigBuilder::initialConfig(), 'min' => 5, 'string' => true];

    expect(RuleConfigBuilder::validateSizeRuleHasType($config))->toBeTrue();
});

it('CoreValueCompiler still compiles via builder facade', function (): void {
    expect(CoreValueCompiler::compile('required|string|max:255'))->toBeInstanceOf(Closure::class);
    expect(CoreValueCompiler::compile('integer|min:1|max:100'))->toBeInstanceOf(Closure::class);
});

it('CoreValueCompiler still rejects unsupported parts', function (): void {
    expect(CoreValueCompiler::compile('sometimes|string'))->toBeNull();
    expect(CoreValueCompiler::compile('required|min:5'))->toBeNull(); // size without type
});

/**
 * Bailout: gt/gte/lt/lte against a sibling field require an explicit type
 * flag so the closure knows how to size both sides. ItemContextCompiler
 * returns null without one — pinning here so the unified builder doesn't
 * silently start compiling these as "always pass" or similar.
 */
it('ItemContextCompiler bails on gt:field without explicit type', function (): void {
    expect(ItemContextCompiler::compile('required|gt:other'))->toBeNull();
});

it('ItemContextCompiler bails on gte:field without explicit type', function (): void {
    expect(ItemContextCompiler::compile('required|gte:other'))->toBeNull();
});

it('ItemContextCompiler bails on lt:field without explicit type', function (): void {
    expect(ItemContextCompiler::compile('required|lt:other'))->toBeNull();
});

it('ItemContextCompiler bails on lte:field without explicit type', function (): void {
    expect(ItemContextCompiler::compile('required|lte:other'))->toBeNull();
});

it('ItemContextCompiler accepts gt:field with numeric type', function (): void {
    expect(ItemContextCompiler::compile('required|numeric|gt:other'))->toBeInstanceOf(Closure::class);
});

it('ItemContextCompiler accepts gt:field with integer type', function (): void {
    expect(ItemContextCompiler::compile('required|integer|gt:other'))->toBeInstanceOf(Closure::class);
});

it('ItemContextCompiler accepts gt:field with string type', function (): void {
    expect(ItemContextCompiler::compile('required|string|gt:other'))->toBeInstanceOf(Closure::class);
});

it('ItemContextCompiler accepts gt:field with array type', function (): void {
    expect(ItemContextCompiler::compile('required|array|gt:other'))->toBeInstanceOf(Closure::class);
});

/**
 * Bailout: date_format combined with a date-field reference is intentionally
 * unsupported. Laravel's checkDateTimeOrder uses the attribute's format to
 * parse BOTH sides AND treats null/missing references as passing — neither
 * matches our strtotime-based resolver. Bail and let Laravel handle it.
 */
it('ItemContextCompiler bails on date_format + after:field combo', function (): void {
    expect(ItemContextCompiler::compile('required|date|date_format:Y-m-d|after:start_date'))->toBeNull();
});

it('ItemContextCompiler bails on date_format + before:field combo', function (): void {
    expect(ItemContextCompiler::compile('required|date|date_format:Y-m-d|before:end_date'))->toBeNull();
});

it('ItemContextCompiler bails on date_format + before_or_equal:field combo', function (): void {
    expect(ItemContextCompiler::compile('required|date|date_format:Y-m-d|before_or_equal:end_date'))->toBeNull();
});

it('ItemContextCompiler bails on date_format + after_or_equal:field combo', function (): void {
    expect(ItemContextCompiler::compile('required|date|date_format:Y-m-d|after_or_equal:start_date'))->toBeNull();
});

it('ItemContextCompiler bails on date_format + date_equals:field combo', function (): void {
    expect(ItemContextCompiler::compile('required|date|date_format:Y-m-d|date_equals:other_date'))->toBeNull();
});

/**
 * Counter-checks: date_format with a date LITERAL (not field ref) is fine.
 */
it('ItemContextCompiler accepts date_format with literal-only after', function (): void {
    expect(ItemContextCompiler::compile('required|date|date_format:Y-m-d|after:2030-01-01'))->toBeInstanceOf(Closure::class);
});
