<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Benchmarks;

use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use SanderMuller\FluentValidation\Benchmarks\Support\ConditionalImportRequest;
use SanderMuller\FluentValidation\Benchmarks\Support\Scenarios;
use Tally\Attribute\Before;
use Tally\Attribute\DataProvider;
use Tally\Attribute\ExpectedFor;
use Tally\Attribute\Linear;
use Tally\Attribute\Warmup;

/**
 * The conditional import through a HasFluentRules form request, where most
 * fields carry an exclude rule that the form request evaluates up front.
 */
#[Warmup(1)]
final class ConditionalFormRequestBench
{
    private ConditionalImportRequest $request;

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
        $this->makeRequest(9)->validateResolved();

        $this->request = $this->makeRequest($count);
    }

    #[DataProvider('sizes')]
    #[Linear('count')]
    public function benchGrowth(int $count): void
    {
        (clone $this->request)->validateResolved();
    }

    #[DataProvider('size')]
    #[ExpectedFor('items-100', instructions: '18.44M', cycles: '20.39M', memory: '301.0KiB')]
    public function benchValidate(int $count): void
    {
        (clone $this->request)->validateResolved();
    }

    private function makeRequest(int $count): ConditionalImportRequest
    {
        $request = ConditionalImportRequest::createFrom(Request::create('/import', 'POST', Scenarios::conditionalData($count)));
        $request->setContainer(app());
        $request->setRedirector(resolve(Redirector::class));

        return $request;
    }
}
