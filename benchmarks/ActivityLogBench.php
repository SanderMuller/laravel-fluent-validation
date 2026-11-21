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
 * Most fields pair requiredIf with excludeUnless on the same sibling field.
 */
final class ActivityLogBench
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
        yield 'entries-25' => [25];
        yield 'entries-100' => [100];
        yield 'entries-400' => [400];
    }

    /** @return iterable<string, array{int}> */
    public static function size(): iterable
    {
        yield 'entries-250' => [250];
    }

    #[Before]
    public function prepare(int $count): void
    {
        Scenarios::boot();
        // Loads the classes and fills the compile caches, so the counted call measures validation.
        Scenarios::validate(Scenarios::activityLogRules(), Scenarios::activityLogData(75));

        $this->rules = Scenarios::activityLogRules();
        $this->data = Scenarios::activityLogData($count);

        $this->nativeRules = RuleSet::compile(RuleSet::from(Scenarios::activityLogRules())->flattenRules());
        Scenarios::validateNative($this->nativeRules, Scenarios::activityLogData(75));
    }

    #[DataProvider('sizes')]
    #[Linear('count')]
    public function benchGrowth(int $count): void
    {
        Scenarios::validate($this->rules, $this->data);
    }

    #[DataProvider('size')]
    #[ExpectedFor('entries-250', instructions: '12.57M', cycles: '15.74M', memory: '117.2KiB')]
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
    #[ExpectedFor('entries-250', instructions: '1.853B', cycles: '2.175B', memory: '2.535MiB')]
    public function benchNative(int $count): void
    {
        Scenarios::validateNative($this->nativeRules, $this->data);
    }
}
