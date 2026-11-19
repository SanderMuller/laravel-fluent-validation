<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Benchmarks;

use SanderMuller\FluentValidation\Benchmarks\Support\Scenarios;
use SanderMuller\FluentValidation\RuleSet;
use Tally\Attribute\Before;
use Tally\Attribute\DataProvider;
use Tally\Attribute\ExpectedFor;
use Tally\Attribute\Linear;
use Tally\Attribute\RelativeTolerance;

/**
 * A wildcard group where every 10th item fails, so those items go to Laravel.
 */
final class FailingProductImportBench
{
    /** @var array<string, mixed> */
    private array $rules = [];

    /** @var array<string, mixed> */
    private array $data = [];

    /** @var array<string, mixed> */
    private array $nativeRules = [];

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

        $this->nativeRules = RuleSet::compile(RuleSet::from(Scenarios::productRules())->flattenRules());
        Scenarios::validateNative($this->nativeRules, Scenarios::productData(10, 10));
    }

    #[DataProvider('sizes')]
    #[Linear('count')]
    public function benchGrowth(int $count): void
    {
        Scenarios::validate($this->rules, $this->data);
    }

    #[DataProvider('size')]
    #[ExpectedFor('items-500', instructions: '31.67M', cycles: '42.71M', memory: '215.1KiB')]
    public function benchValidate(int $count): void
    {
        Scenarios::validate($this->rules, $this->data);
    }

    /**
     * The same rules through Laravel's own validator. The count follows the Laravel version
     * that CI installs, so it may change by up to 100 % before the case fails.
     */
    #[DataProvider('size')]
    #[RelativeTolerance(1.0)]
    #[ExpectedFor('items-500', instructions: '1.533B', cycles: '1.901B', memory: '2.232MiB')]
    public function benchNative(int $count): void
    {
        Scenarios::validateNative($this->nativeRules, $this->data);
    }
}
