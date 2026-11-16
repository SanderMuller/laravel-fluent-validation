<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Benchmarks;

use SanderMuller\FluentValidation\Benchmarks\Support\Scenarios;
use Tally\Attribute\Before;
use Tally\Attribute\DataProvider;
use Tally\Attribute\ExpectedFor;
use Tally\Attribute\Linear;

/**
 * A wildcard group where every 10th item fails, so those items go to Laravel.
 */
final class FailingProductImportBench
{
    /** @var array<string, mixed> */
    private array $rules = [];

    /** @var array<string, mixed> */
    private array $data = [];

    /** @return iterable<string, array{int}> */
    public static function sizes(): iterable
    {
        yield 'items-50' => [50];
        yield 'items-200' => [200];
        yield 'items-800' => [800];
    }

    /** @return iterable<string, array{int}> */
    public static function size(): iterable
    {
        yield 'items-500' => [500];
    }

    #[Before]
    public function prepare(int $count): void
    {
        Scenarios::boot();
        // Loads the classes and fills the compile caches, so the counted call measures validation.
        Scenarios::validate(Scenarios::productRules(), Scenarios::productData(10, 10));

        $this->rules = Scenarios::productRules();
        $this->data = Scenarios::productData($count, 10);
    }

    #[DataProvider('sizes')]
    #[Linear('count')]
    public function benchGrowth(int $count): void
    {
        Scenarios::validate($this->rules, $this->data);
    }

    #[DataProvider('size')]
    #[ExpectedFor('items-500', instructions: '30.58M', cycles: '40.79M', memory: '214.2KiB')]
    public function benchValidate(int $count): void
    {
        Scenarios::validate($this->rules, $this->data);
    }
}
