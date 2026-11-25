<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Benchmarks;

use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use SanderMuller\FluentValidation\Benchmarks\Support\ProductImportRequest;
use SanderMuller\FluentValidation\Benchmarks\Support\Scenarios;
use Tally\Attribute\Before;
use Tally\Attribute\DataProvider;
use Tally\Attribute\ExpectedFor;
use Tally\Attribute\Linear;
use Tally\Attribute\Warmup;

/**
 * The product import through a HasFluentRules form request.
 */
#[Warmup(1)]
final class FormRequestImportBench
{
    private ProductImportRequest $request;

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
        $this->makeRequest(10)->validateResolved();

        $this->request = $this->makeRequest($count);
    }

    #[DataProvider('sizes')]
    #[Linear('count')]
    public function benchGrowth(int $count): void
    {
        (clone $this->request)->validateResolved();
    }

    #[DataProvider('size')]
    #[ExpectedFor('items-500', instructions: '12.97M', cycles: '14.23M', memory: '48.26KiB')]
    public function benchValidate(int $count): void
    {
        (clone $this->request)->validateResolved();
    }

    private function makeRequest(int $count): ProductImportRequest
    {
        $request = ProductImportRequest::createFrom(Request::create('/import', 'POST', Scenarios::productData($count)));
        $request->setContainer(app());
        $request->setRedirector(resolve(Redirector::class));

        return $request;
    }
}
