<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests\Internal;

use SanderMuller\FluentValidation\Internal\ItemErrorCollector;
use SanderMuller\FluentValidation\Internal\ItemRuleCompiler;
use SanderMuller\FluentValidation\Internal\ItemValidator;

function makeItemValidator(bool $stopOnFirstFailure = false): ItemValidator
{
    return new ItemValidator(
        $stopOnFirstFailure,
        new ItemRuleCompiler(),
        new ItemErrorCollector(),
    );
}

it('validate returns empty errors for passing items', function (): void {
    $validator = makeItemValidator();

    $errors = $validator->validate(
        items: [['name' => 'Alice'], ['name' => 'Bob']],
        itemRules: ['name' => 'required|string'],
        itemMessages: [],
        itemAttributes: [],
        parent: 'users',
        isScalar: false,
    );

    expect($errors)->toBeEmpty();
});

it('validate collects errors keyed by full dotted path', function (): void {
    $validator = makeItemValidator();

    $errors = $validator->validate(
        items: [['name' => ''], ['name' => 'ok']],
        itemRules: ['name' => 'required|string'],
        itemMessages: [],
        itemAttributes: [],
        parent: 'users',
        isScalar: false,
    );

    expect($errors)->toHaveKey('users.0.name')
        ->and($errors)->not->toHaveKey('users.1.name');
});

it('validate handles scalar each under _v key', function (): void {
    $validator = makeItemValidator();

    $errors = $validator->validate(
        items: ['a', '', 'c'],
        itemRules: ['_v' => 'required|string'],
        itemMessages: [],
        itemAttributes: [],
        parent: 'tags',
        isScalar: true,
    );

    expect($errors)->toHaveKey('tags.1')
        ->and($errors)->not->toHaveKey('tags.0')
        ->and($errors)->not->toHaveKey('tags.2');
});

it('validate with stopOnFirstFailure=true returns after the first failing item', function (): void {
    $validator = makeItemValidator(stopOnFirstFailure: true);

    $errors = $validator->validate(
        items: [['name' => ''], ['name' => ''], ['name' => '']],
        itemRules: ['name' => 'required|string'],
        itemMessages: [],
        itemAttributes: [],
        parent: 'users',
        isScalar: false,
    );

    expect($errors)->toHaveCount(1)
        ->and($errors)->toHaveKey('users.0.name');
});

it('validate with stopOnFirstFailure=false collects errors from every failing item', function (): void {
    $validator = makeItemValidator(stopOnFirstFailure: false);

    $errors = $validator->validate(
        items: [['name' => ''], ['name' => ''], ['name' => '']],
        itemRules: ['name' => 'required|string'],
        itemMessages: [],
        itemAttributes: [],
        parent: 'users',
        isScalar: false,
    );

    expect($errors)->toHaveCount(3);
});

it('validate applies exclude_unless conditional reduction per item', function (): void {
    $validator = makeItemValidator();

    $errors = $validator->validate(
        items: [
            ['type' => 'product', 'price' => null],
            ['type' => 'draft', 'price' => null],
        ],
        itemRules: [
            'type' => 'required|string',
            'price' => [['exclude_unless', 'type', 'product'], 'required', 'numeric'],
        ],
        itemMessages: [],
        itemAttributes: [],
        parent: 'items',
        isScalar: false,
    );

    expect($errors)->toHaveKey('items.0.price')
        ->and($errors)->not->toHaveKey('items.1.price');
});
