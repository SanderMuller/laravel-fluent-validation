<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Convenience base class for FormRequests using fluent validation rules.
 * Equivalent to extending FormRequest and adding `use HasFluentRules`.
 *
 *     class BulkImportRequest extends FluentFormRequest
 *     {
 *         public function rules(): array
 *         {
 *             return [
 *                 'items' => FluentRule::array()->required()->each([
 *                     'name' => FluentRule::string('Name')->required()->max(255),
 *                     'qty'  => FluentRule::numeric('Quantity')->required()->min(1),
 *                 ]),
 *             ];
 *         }
 *     }
 */
class FluentFormRequest extends FormRequest
{
    use HasFluentRules;
}
