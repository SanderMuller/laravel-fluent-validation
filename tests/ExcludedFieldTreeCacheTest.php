<?php declare(strict_types=1);

use SanderMuller\FluentValidation\Internal\WildcardValidatedData;

it('builds a separate tree for a name with a NUL byte', function (): void {
    $validated = ['q' => [["a\0b" => 1, 'a' => 2, 'b' => 3]]];

    $pair = WildcardValidatedData::excludedTree(['a' => true, 'b' => true]);
    $single = WildcardValidatedData::excludedTree(["a\0b" => true]);

    expect(WildcardValidatedData::withoutExcluded($validated, ['q' => [$pair]]))->toBe(['q' => [["a\0b" => 1]]])
        ->and(WildcardValidatedData::withoutExcluded($validated, ['q' => [$single]]))->toBe(['q' => [['a' => 2, 'b' => 3]]]);
});

it('builds a separate tree for each set of names', function (): void {
    $validated = ['q' => [['a' => 1, 'b' => 2, 'c' => 3]]];

    WildcardValidatedData::excludedTree(['a' => true, 'b' => true]);
    $second = WildcardValidatedData::excludedTree(['a' => true, 'c' => true]);

    expect(WildcardValidatedData::withoutExcluded($validated, ['q' => [$second]]))->toBe(['q' => [['b' => 2]]]);
});
