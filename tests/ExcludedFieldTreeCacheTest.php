<?php declare(strict_types=1);

use SanderMuller\FluentValidation\Internal\WildcardValidatedData;

it('builds a separate tree for a name with a NUL byte', function (): void {
    $validated = ['q' => [["a\0b" => 1, 'a' => 2, 'b' => 3]]];

    $pair = WildcardValidatedData::splitExcluded(['a' => true, 'b' => true]);
    $single = WildcardValidatedData::splitExcluded(["a\0b" => true]);

    expect(WildcardValidatedData::withoutExcluded($validated, ['q' => [$pair]]))->toBe(['q' => [["a\0b" => 1]]])
        ->and(WildcardValidatedData::withoutExcluded($validated, ['q' => [$single]]))->toBe(['q' => [['a' => 2, 'b' => 3]]]);
});
