<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Benchmarks;

use SanderMuller\FluentValidation\Benchmarks\Support\Scenarios;
use Tally\Attribute\Before;
use Tally\Attribute\DataProvider;
use Tally\Attribute\ExpectedFor;
use Tally\Attribute\Linear;

/**
 * Rule objects under wildcard keys in Laravel's own validator. Each item
 * validates through an inner validator of the rule object.
 */
final class RuleObjectBench
{
    /** @var array<string, mixed> */
    private array $rules = [];

    /** @var array<string, mixed> */
    private array $data = [];

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
        // Loads the classes, so the counted call measures validation.
        Scenarios::validateNative(Scenarios::eventRuleObjects(), Scenarios::eventData(2));

        $this->rules = Scenarios::eventRuleObjects();
        $this->data = Scenarios::eventData($count);
    }

    #[DataProvider('sizes')]
    #[Linear('count')]
    public function benchGrowth(int $count): void
    {
        Scenarios::validateNative($this->rules, $this->data);
    }

    #[DataProvider('size')]
    #[ExpectedFor('events-100', instructions: '332.3M', cycles: '425.8M', memory: '319.3KiB')]
    public function benchValidate(int $count): void
    {
        Scenarios::validateNative($this->rules, $this->data);
    }
}
