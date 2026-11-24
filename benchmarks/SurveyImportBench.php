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
 * Conditionals on the item type and, inside nested answers, on each answer.
 */
#[Warmup(1)]
final class SurveyImportBench
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
        yield 'questions-10' => [10];
        yield 'questions-40' => [40];
        yield 'questions-160' => [160];
    }

    /** @return iterable<string, array{int}> */
    public static function size(): iterable
    {
        yield 'questions-100' => [100];
    }

    #[Before]
    public function prepare(int $count): void
    {
        Scenarios::boot();
        // Loads the classes and fills the compile caches, so the counted call measures validation.
        Scenarios::validate(Scenarios::surveyRules(), Scenarios::surveyData(5));

        $this->rules = Scenarios::surveyRules();
        $this->data = Scenarios::surveyData($count);

        $this->nativeRules = RuleSet::compile(RuleSet::from(Scenarios::surveyRules())->flattenRules());
        Scenarios::validateNative($this->nativeRules, Scenarios::surveyData(5));
    }

    #[DataProvider('sizes')]
    #[Linear('count')]
    public function benchGrowth(int $count): void
    {
        Scenarios::validate($this->rules, $this->data);
    }

    #[DataProvider('size')]
    #[ExpectedFor('questions-100', instructions: '29.91M', cycles: '35.25M', memory: '337.6KiB')]
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
    #[ExpectedFor('questions-100', instructions: '1.420B', cycles: '1.638B', memory: '2.113MiB')]
    public function benchNative(int $count): void
    {
        Scenarios::validateNative($this->nativeRules, $this->data);
    }
}
