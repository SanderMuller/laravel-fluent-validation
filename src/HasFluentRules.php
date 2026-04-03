<?php

declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Validation\Validator;
use ReflectionProperty;

/**
 * Add this trait to a FormRequest to enable FluentRule features:
 * each()/children() flattening, wildcard expansion, label/message
 * extraction, rule compilation, and implicit attribute mapping.
 *
 *     class StorePostRequest extends FormRequest
 *     {
 *         use HasFluentRules;
 *
 *         public function rules(): array
 *         {
 *             return [
 *                 'name'  => FluentRule::string('Full Name')->required()->max(255),
 *                 'items' => FluentRule::array()->required()->each([
 *                     'name' => FluentRule::string()->required(),
 *                 ]),
 *             ];
 *         }
 *     }
 */
trait HasFluentRules
{
    protected function createDefaultValidator(ValidationFactory $factory): Validator
    {
        $rules = method_exists($this, 'rules')
            ? $this->container->call([$this, 'rules'])
            : [];

        $data = $this->validationData();
        $prepared = RuleSet::from($rules)->prepare($data);

        $validator = $factory->make(
            $data,
            $prepared->rules,
            array_merge($prepared->messages, $this->messages()),
            array_merge($prepared->attributes, $this->attributes()),
        );

        if ($prepared->implicitAttributes !== []) {
            (new ReflectionProperty($validator, 'implicitAttributes'))
                ->setValue($validator, $prepared->implicitAttributes);
        }

        return $validator;
    }
}
