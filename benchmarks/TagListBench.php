<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Benchmarks;

use SanderMuller\FluentValidation\Benchmarks\Support\Scenarios;
use Tally\Attribute\Before;
use Tally\Attribute\DataProvider;
use Tally\Attribute\ExpectedFor;
use Tally\Attribute\Linear;

/**
 * A large flat list of scalar values.
 */
final class TagListBench
{
    /** @var array<string, mixed> */
    private array $rules = [];

    /** @var array<string, mixed> */
    private array $data = [];

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
    }

    #[DataProvider('sizes')]
    #[Linear('count')]
    public function benchGrowth(int $count): void
    {
        Scenarios::validate($this->rules, $this->data);
    }

    #[DataProvider('size')]
    #[ExpectedFor('tags-1000', instructions: '3.469M', cycles: '4.079M', memory: '37.40KiB')]
    public function benchValidate(int $count): void
    {
        Scenarios::validate($this->rules, $this->data);
    }
}
