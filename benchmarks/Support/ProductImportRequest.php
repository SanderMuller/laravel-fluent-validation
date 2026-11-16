<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Benchmarks\Support;

use SanderMuller\FluentValidation\FluentFormRequest;

final class ProductImportRequest extends FluentFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return Scenarios::productRules();
    }
}
