<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;
use SanderMuller\FluentValidation\FluentSchema;
use SanderMuller\FluentValidation\HasFluentRules;

/**
 * Abstract base that supplies shared fields through schema(). A concrete
 * subclass adds/overrides via rules(); the subclass (more derived) wins any
 * shared key, and both layers merge. Named (not anonymous) so declaring-class
 * depth is a real inheritance relationship.
 */
abstract class MergeSchemaBaseRequest extends FormRequest
{
    use HasFluentRules;

    /** @return array<string, mixed> */
    public function schema(FluentSchema $rules): array
    {
        return [
            'shared' => $rules->string()->required()->in(['base']),
            'base_only' => $rules->string()->required(),
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
