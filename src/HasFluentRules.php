<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Illuminate\Contracts\Validation\Factory as ValidationFactory;
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

        $messages = $this->messages() + $prepared->messages;
        $attributes = $this->attributes() + $prepared->attributes;

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
     * Create an OptimizedValidator with the same setup the factory provides
     * (extensions, container, presence verifier, excludeUnvalidatedArrayKeys)
     * without mutating the shared factory's resolver. Octane-safe.
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
        // Create a standard Validator through the factory to get full setup,
        // then transfer its configuration to an OptimizedValidator.
        /** @var Validator $base */
        $base = $factory->make($data, $rules, $messages, $attributes);

        // Create with EMPTY rules to skip re-parsing the 3500+ expanded rules.
        // The parsed rules are copied from the base validator below.
        $optimized = new OptimizedValidator(
            $base->getTranslator(),
            $data,
            [],
            $messages,
            $attributes,
        );

        // Copy parsed rules AND factory-applied configuration from the base.
        $ref = new \ReflectionObject($base);
        foreach (['rules', 'initialRules', 'container', 'presenceVerifier', 'excludeUnvalidatedArrayKeys', 'extensions', 'implicitExtensions', 'dependentExtensions', 'replacers', 'fallbackMessages'] as $prop) {
            if ($ref->hasProperty($prop)) {
                $p = $ref->getProperty($prop);
                $value = $p->getValue($base);
                if (! in_array($value, [null, [], false], true)) {
                    $p->setValue($optimized, $value);
                }
            }
        }

        return $optimized;
    }
}
