<?php

declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Validation\Validator;
use ReflectionProperty;

/**
 * Add this trait to a FormRequest to automatically expand wildcard rules
 * for optimal performance. Works with both plain rules and each() syntax.
 *
 *     class ImportRequest extends FormRequest
 *     {
 *         use ExpandsWildcards;
 *
 *         public function rules(): array
 *         {
 *             return [
 *                 'items' => FluentRule::array()->required()->each([
 *                     'name' => FluentRule::string()->required()->min(2),
 *                 ]),
 *             ];
 *         }
 *     }
 */
trait ExpandsWildcards
{
    protected function createDefaultValidator(ValidationFactory $validationFactory): Validator
    {
        $rules = method_exists($this, 'rules')
            ? $this->container->call([$this, 'rules'])
            : [];

        $data = $this->validationData();

        $ruleSet = RuleSet::from($rules);
        [$expanded, $implicitAttributes] = $ruleSet->expand($data);

        // Extract labels/messages before compilation destroys rule objects.
        [$ruleMessages, $ruleAttributes] = RuleSet::extractMetadata($expanded);

        $validator = $validationFactory->make(
            $data,
            RuleSet::compile($expanded),
            array_merge($ruleMessages, $this->messages()),
            array_merge($ruleAttributes, $this->attributes())
        );

        if ($implicitAttributes !== []) {
            (new ReflectionProperty($validator, 'implicitAttributes'))
                ->setValue($validator, $implicitAttributes);
        }

        return $validator;
    }
}
