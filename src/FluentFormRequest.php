<?php

declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Factory;
use Illuminate\Validation\Validator;
use ReflectionProperty;

/**
 * Base FormRequest with automatic fluent rule compilation and
 * per-attribute fast-check optimization for wildcard rules.
 *
 * Extend this class instead of FormRequest to get both:
 * - FluentRule compilation, label/message extraction, O(n) wildcard expansion
 * - Pure PHP fast-checks that skip Laravel validation for valid wildcard items
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
    protected function createDefaultValidator(ValidationFactory $factory): Validator
    {
        /** @var array<string, mixed> $rules */
        $rules = method_exists($this, 'rules')
            ? $this->container->call([$this, 'rules'])
            : [];

        /** @var array<string, mixed> $data */
        $data = $this->validationData();
        $prepared = RuleSet::from($rules)->prepare($data);

        [$fastChecks, $attributePatternMap] = $this->buildFastCheckMaps($prepared);

        $validator = $this->makeOptimizedValidator($factory, $data, $prepared);
        $validator->withFastChecks($fastChecks, $attributePatternMap);

        if ($prepared->implicitAttributes !== []) {
            (new ReflectionProperty(Validator::class, 'implicitAttributes'))
                ->setValue($validator, $prepared->implicitAttributes);
        }

        return $validator;
    }

    /**
     * Build fast-check closures and the attribute-to-pattern lookup map.
     *
     * @return array{0: array<string, \Closure(mixed): bool>, 1: array<string, string>}
     */
    private function buildFastCheckMaps(PreparedRules $prepared): array
    {
        $wildcardRules = [];
        foreach ($prepared->implicitAttributes as $pattern => $expandedPaths) {
            if (isset($prepared->rules[$expandedPaths[0] ?? ''])) {
                $wildcardRules[$pattern] = $prepared->rules[$expandedPaths[0]];
            }
        }

        $fastChecks = OptimizedValidator::buildFastChecks($wildcardRules);

        $attributePatternMap = [];
        foreach ($prepared->implicitAttributes as $pattern => $expandedPaths) {
            if (isset($fastChecks[$pattern])) {
                foreach ($expandedPaths as $path) {
                    $attributePatternMap[$path] = $pattern;
                }
            }
        }

        return [$fastChecks, $attributePatternMap];
    }

    /**
     * Create an OptimizedValidator through the factory so it gets full setup
     * (extensions, container, presence verifier, excludeUnvalidatedArrayKeys).
     *
     * @param  array<string, mixed>  $data
     */
    private function makeOptimizedValidator(
        ValidationFactory $factory,
        array $data,
        PreparedRules $prepared,
    ): OptimizedValidator {
        // Temporarily override the factory's resolver so make() instantiates
        // an OptimizedValidator instead of a standard Validator.
        $resolverProp = new ReflectionProperty(Factory::class, 'resolver');
        $originalResolver = $resolverProp->getValue($factory);

        /** @var Factory $factory */
        $factory->resolver(
            static fn (\Illuminate\Contracts\Translation\Translator $translator, array $data, array $rules, array $messages, array $attributes) => new OptimizedValidator($translator, $data, $rules, $messages, $attributes),
        );

        /** @var OptimizedValidator $validator */
        $validator = $factory->make(
            $data,
            $prepared->rules,
            array_merge($prepared->messages, $this->messages()),
            array_merge($prepared->attributes, $this->attributes()),
        );

        // Restore original resolver immediately.
        $resolverProp->setValue($factory, $originalResolver);

        return $validator;
    }
}
