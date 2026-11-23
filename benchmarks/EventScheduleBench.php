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
 * Date comparisons against sibling fields, partly fast-checked.
 */
final class EventScheduleBench
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
        yield 'events-25' => [25];
        yield 'events-100' => [100];
        yield 'events-400' => [400];
    }

    /** @return iterable<string, array{int}> */
    public static function size(): iterable
    {
        yield 'events-100' => [100];
    }

    #[Before]
    public function prepare(int $count): void
    {
        Scenarios::boot();
        // Loads the classes and fills the compile caches, so the counted call measures validation.
        Scenarios::validate(Scenarios::eventRules(), Scenarios::eventData(2));

        $this->rules = Scenarios::eventRules();
        $this->data = Scenarios::eventData($count);

        $this->nativeRules = RuleSet::compile(RuleSet::from(Scenarios::eventRules())->flattenRules());
        Scenarios::validateNative($this->nativeRules, Scenarios::eventData(2));
    }

    #[DataProvider('sizes')]
    #[Linear('count')]
    public function benchGrowth(int $count): void
    {
        Scenarios::validate($this->rules, $this->data);
    }

    #[DataProvider('size')]
    #[ExpectedFor('events-100', instructions: '10.24M', cycles: '13.16M', memory: '55.07KiB')]
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
    #[ExpectedFor('events-100', instructions: '238.7M', cycles: '306.1M', memory: '377.6KiB')]
    public function benchNative(int $count): void
    {
        Scenarios::validateNative($this->nativeRules, $this->data);
    }
}
