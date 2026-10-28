<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests\Types;

use Iterator;
use PHPStan\Testing\TypeInferenceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class RuleSetGenericsTypeTest extends TypeInferenceTestCase
{
    /** @return Iterator<mixed> */
    public static function dataFileAsserts(): Iterator
    {
        yield from self::gatherAssertTypes(__DIR__ . '/data/rule-set-generics.php');
    }

    #[DataProvider('dataFileAsserts')]
    public function test_file_asserts(string $assertType, string $file, mixed ...$args): void
    {
        $this->assertFileAsserts($assertType, $file, ...$args);
    }
}
