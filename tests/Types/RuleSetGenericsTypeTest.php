<?php declare(strict_types=1);

use PHPStan\Testing\TypeInferenceTestCase;

// Pest's TIA mode rejects PHPUnit test classes, so this Pest test drives
// PHPStan's TypeInferenceTestCase through an anonymous subclass.
dataset('rule set generics type asserts', static fn (): array => iterator_to_array(
    TypeInferenceTestCase::gatherAssertTypes(__DIR__ . '/data/rule-set-generics.php'),
));

it('infers the validated shape of RuleSet and Validated', function (string $assertType, string $file, mixed ...$args): void {
    (new class ('typeInference') extends TypeInferenceTestCase {})->assertFileAsserts($assertType, $file, ...$args);
})->with('rule set generics type asserts');
