<?php

declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Validation\Factory;
use Illuminate\Validation\Validator;
use ReflectionProperty;

/**
 * Add this trait to a FormRequest to enable FluentRule features:
 * each()/children() flattening, wildcard expansion, label/message
 * extraction, rule compilation, implicit attribute mapping, and
 * per-attribute fast-check optimization for wildcard rules.
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
        /** @var array<string, mixed> $rules */
        $rules = method_exists($this, 'rules') // @phpstan-ignore function.alreadyNarrowedType
            ? $this->container->call([$this, 'rules'])
            : [];

        /** @var array<string, mixed> $data */
        $data = $this->validationData();
        $prepared = RuleSet::from($rules)->prepare($data);

        [$fastChecks, $attributePatternMap] = $this->buildFastCheckMaps($prepared);

        $messages = array_merge($prepared->messages, $this->messages());
        $attributes = array_merge($prepared->attributes, $this->attributes());

        // Only use OptimizedValidator when there are fast-checkable wildcard
        // rules. Otherwise return a plain Validator — zero overhead, zero
        // behavior change for non-wildcard FormRequests.
        if ($fastChecks !== []) {
            $validator = $this->makeOptimizedValidator($factory, $data, $prepared->rules, $messages, $attributes);
            $validator->withFastChecks($fastChecks, $attributePatternMap);
        } else {
            /** @var Validator $validator */
            $validator = $factory->make($data, $prepared->rules, $messages, $attributes);
        }

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
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     */
    private function makeOptimizedValidator(
        ValidationFactory $factory,
        array $data,
        array $rules,
        array $messages,
        array $attributes,
    ): OptimizedValidator {
        $resolverProp = new ReflectionProperty(Factory::class, 'resolver');
        $originalResolver = $resolverProp->getValue($factory);

        /** @var Factory $factory */
        $factory->resolver(
            static fn (Translator $translator, array $data, array $rules, array $messages, array $attributes) => new OptimizedValidator($translator, $data, $rules, $messages, $attributes),
        );

        /** @var OptimizedValidator $validator */
        $validator = $factory->make($data, $rules, $messages, $attributes);

        $resolverProp->setValue($factory, $originalResolver);

        return $validator;
    }
}
