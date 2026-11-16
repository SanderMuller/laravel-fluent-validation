<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Benchmarks;

use SanderMuller\FluentValidation\Benchmarks\Support\Scenarios;
use Tally\Attribute\Before;
use Tally\Attribute\DataProvider;
use Tally\Attribute\ExpectedFor;
use Tally\Attribute\Linear;

/**
 * Many exclude_unless conditions on a wildcard group.
 */
final class ConditionalImportBench
{
    /** @var array<string, mixed> */
    private array $rules = [];

    /** @var array<string, mixed> */
    private array $data = [];

    /** @return iterable<string, array{int}> */
    public static function sizes(): iterable
    {
        yield 'items-25' => [25];
        yield 'items-100' => [100];
        yield 'items-400' => [400];
    }

    /** @return iterable<string, array{int}> */
    public static function size(): iterable
    {
        yield 'items-100' => [100];
    }

    #[Before]
    public function prepare(int $count): void
    {
        Scenarios::boot();
        // Loads the classes and fills the compile caches, so the counted call measures validation.
        Scenarios::validate(Scenarios::conditionalRules(), Scenarios::conditionalData(9));

        $this->rules = Scenarios::conditionalRules();
        $this->data = Scenarios::conditionalData($count);
    }

    #[DataProvider('sizes')]
    #[Linear('count')]
    public function benchGrowth(int $count): void
    {
        Scenarios::validate($this->rules, $this->data);
    }

    #[DataProvider('size')]
    #[ExpectedFor('items-100', instructions: '12.98M', cycles: '16.10M', memory: '219.5KiB')]
    public function benchValidate(int $count): void
    {
        Scenarios::validate($this->rules, $this->data);
    }
}
