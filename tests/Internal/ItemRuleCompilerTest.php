<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests\Internal;

use Illuminate\Validation\Rules\In;
use SanderMuller\FluentValidation\Internal\ItemRuleCompiler;

it('analyzeConditionals extracts exclude_unless tuples', function (): void {
    $compiler = new ItemRuleCompiler();

    $result = $compiler->analyzeConditionals([
        'price' => [['exclude_unless', 'type', 'product'], 'required', 'numeric'],
        'title' => 'required|string',
    ]);

    expect($result)->toHaveKey('price')
        ->and($result['price']['action'])->toBe('exclude_unless')
        ->and($result['price']['field'])->toBe('type')
        ->and($result['price']['values'])->toBe(['product'])
        ->and($result)->not->toHaveKey('title');
});

it('analyzeConditionals extracts exclude_if with multiple values', function (): void {
    $compiler = new ItemRuleCompiler();

    $result = $compiler->analyzeConditionals([
        'sku' => [['exclude_if', 'type', 'draft', 'template'], 'required'],
    ]);

    expect($result['sku']['action'])->toBe('exclude_if')
        ->and($result['sku']['values'])->toBe(['draft', 'template']);
});

it('analyzeConditionals skips rules that are not arrays or have no conditional tuple', function (): void {
    $compiler = new ItemRuleCompiler();

    expect($compiler->analyzeConditionals(['name' => 'required|string']))->toBeEmpty()
        ->and($compiler->analyzeConditionals(['name' => ['required', 'string']]))->toBeEmpty();
});

it('findCommonDispatchField returns shared field when all conditionals reference it', function (): void {
    $compiler = new ItemRuleCompiler();

    $field = $compiler->findCommonDispatchField([
        'a' => ['action' => 'exclude_unless', 'field' => 'type', 'values' => ['x']],
        'b' => ['action' => 'exclude_if', 'field' => 'type', 'values' => ['y']],
    ]);

    expect($field)->toBe('type');
});

it('findCommonDispatchField returns null when fields differ', function (): void {
    $compiler = new ItemRuleCompiler();

    $field = $compiler->findCommonDispatchField([
        'a' => ['action' => 'exclude_unless', 'field' => 'type', 'values' => ['x']],
        'b' => ['action' => 'exclude_if', 'field' => 'status', 'values' => ['y']],
    ]);

    expect($field)->toBeNull();
});

it('findCommonDispatchField returns null for empty input', function (): void {
    expect((new ItemRuleCompiler())->findCommonDispatchField([]))->toBeNull();
});

it('reduceRulesForItem strips excluded fields when action matches', function (): void {
    $compiler = new ItemRuleCompiler();
    $conditionals = [
        'price' => ['action' => 'exclude_unless', 'field' => 'type', 'values' => ['product']],
    ];

    $reduced = $compiler->reduceRulesForItem(
        ['price' => [['exclude_unless', 'type', 'product'], 'required'], 'name' => 'required|string'],
        ['type' => 'draft', 'name' => 'foo'],
        $conditionals,
    );

    expect($reduced)->not->toHaveKey('price')
        ->and($reduced['name'])->toBe('required|string');
});

it('reduceRulesForItem keeps field and strips conditional tuple when active', function (): void {
    $compiler = new ItemRuleCompiler();
    $conditionals = [
        'price' => ['action' => 'exclude_unless', 'field' => 'type', 'values' => ['product']],
    ];

    $reduced = $compiler->reduceRulesForItem(
        ['price' => [['exclude_unless', 'type', 'product'], 'required', 'numeric']],
        ['type' => 'product'],
        $conditionals,
    );

    expect($reduced['price'])->toBe('required|numeric');
});

it('ruleCacheKey encodes field name + rule content to distinguish items with varying reduced rules', function (): void {
    $compiler = new ItemRuleCompiler();

    // Keys with different string content must produce different cache keys —
    // this is what prevents a cached validator from being reused across
    // items whose conditionals reduced to different rule chains.
    $keyA = $compiler->ruleCacheKey(['a' => 'required', 'b' => 'string']);
    $keyB = $compiler->ruleCacheKey(['a' => 'required|exists:users,id', 'b' => 'string']);
    expect($keyA)->not->toBe($keyB);

    // Identical rules produce identical keys (cache reuse still works).
    expect($compiler->ruleCacheKey(['a' => 'required', 'b' => 'string']))->toBe($keyA);

    expect($compiler->ruleCacheKey([]))->toBeEmpty();
});

it('buildFastChecks separates fast-checkable fields from slow rules', function (): void {
    $compiler = new ItemRuleCompiler();

    [$checks, $slowRules] = $compiler->buildFastChecks([
        'name' => 'required|string',
        'email' => 'required|email',
        'meta' => ['required', new In(['a', 'b'])],
    ]);

    expect($checks)->toHaveCount(2)
        ->and($slowRules)->toHaveKey('meta')
        ->and($slowRules)->not->toHaveKey('name')
        ->and($slowRules)->not->toHaveKey('email');
});
