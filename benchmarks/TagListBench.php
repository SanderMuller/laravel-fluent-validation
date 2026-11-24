<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Benchmarks;

use SanderMuller\FluentValidation\Benchmarks\Support\Scenarios;
use SanderMuller\FluentValidation\RuleSet;
use Tally\Attribute\Before;
use Tally\Attribute\DataProvider;
use Tally\Attribute\ExpectedFor;
use Tally\Attribute\Linear;
use Tally\Attribute\RelativeTolerance;
use Tally\Attribute\Warmup;

/**
 * A large flat list of scalar values.
 */
#[Warmup(1)]
final class TagListBench
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
        yield 'tags-200' => [200];
        yield 'tags-1000' => [1000];
        yield 'tags-5000' => [5000];
    }

    /** @return iterable<string, array{int}> */
    public static function size(): iterable
    {
        yield 'tags-1000' => [1000];
    }

    #[Before]
    public function prepare(int $count): void
    {
        Scenarios::boot();
        // Loads the classes and fills the compile caches, so the counted call measures validation.
        Scenarios::validate(Scenarios::tagRules(), Scenarios::tagData(2));

        $this->rules = Scenarios::tagRules();
        $this->data = Scenarios::tagData($count);

        $this->nativeRules = RuleSet::compile(RuleSet::from(Scenarios::tagRules())->flattenRules());
        Scenarios::validateNative($this->nativeRules, Scenarios::tagData(2));
    }

    #[DataProvider('sizes')]
    #[Linear('count')]
    public function benchGrowth(int $count): void
    {
        Scenarios::validate($this->rules, $this->data);
    }

    #[DataProvider('size')]
    #[ExpectedFor('tags-1000', instructions: '1.623M', cycles: '1.717M', memory: '38.55KiB')]
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
    #[ExpectedFor('tags-1000', instructions: '319.4M', cycles: '413.0M', memory: '530.4KiB')]
    public function benchNative(int $count): void
    {
        Scenarios::validateNative($this->nativeRules, $this->data);
    }
}
