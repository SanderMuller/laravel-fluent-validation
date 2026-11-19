<?php declare(strict_types=1);

use PHPStan\Testing\TypeInferenceTestCase;

dataset('validated input type asserts', static fn (): array => iterator_to_array(
    TypeInferenceTestCase::gatherAssertTypes(__DIR__ . '/data/validated-input.php'),
));

it('infers the return type of ValidatedInput accessors', function (string $assertType, string $file, mixed ...$args): void {
    (new class ('typeInference') extends TypeInferenceTestCase {})->assertFileAsserts($assertType, $file, ...$args);
})->with('validated input type asserts');
