<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\HasFluentRules;

/**
 * Abstract base that supplies shared fields through rules(). A concrete
 * subclass adds/overrides via schema(); the subclass (more derived) wins any
 * shared key, and both layers merge.
 */
abstract class MergeRulesBaseRequest extends FormRequest
{
    use HasFluentRules;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'shared' => FluentRule::string()->required()->in(['base']),
            'base_only' => FluentRule::string()->required(),
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
