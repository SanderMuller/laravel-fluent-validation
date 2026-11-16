<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Benchmarks;

use SanderMuller\FluentValidation\Benchmarks\Support\Scenarios;
use Tally\Attribute\Before;
use Tally\Attribute\DataProvider;
use Tally\Attribute\Linear;

/**
 * Nested each(): orders with five line items each.
 */
final class NestedOrderBench
{
    /** @var array<string, mixed> */
    private array $rules = [];

    /** @var array<string, mixed> */
    private array $data = [];

    /** @return iterable<string, array{int}> */
    public static function sizes(): iterable
    {
        yield 'orders-25' => [25];
        yield 'orders-100' => [100];
        yield 'orders-400' => [400];
    }

    /** @return iterable<string, array{int}> */
    public static function size(): iterable
    {
        yield 'orders-1000' => [1000];
    }

    #[Before]
    public function prepare(int $count): void
    {
        Scenarios::boot();
        // Loads the classes and fills the compile caches, so the counted call measures validation.
        Scenarios::validate(Scenarios::orderRules(), Scenarios::orderData(2));

        $this->rules = Scenarios::orderRules();
        $this->data = Scenarios::orderData($count);
    }

    #[DataProvider('sizes')]
    #[Linear('count')]
    public function benchGrowth(int $count): void
    {
        Scenarios::validate($this->rules, $this->data);
    }

    #[DataProvider('size')]
    public function benchValidate(int $count): void
    {
        Scenarios::validate($this->rules, $this->data);
    }
}
