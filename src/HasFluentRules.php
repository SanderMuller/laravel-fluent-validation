<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Validation\Validator;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionObject;
use ReflectionProperty;
use SanderMuller\FluentValidation\Internal\PreparesOptimizedRules;

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
    use PreparesOptimizedRules;

    /**
     * Whether the request's `schema()` method is the FluentSchema builder hook
     * (its first parameter is typed FluentSchema) rather than a coincidental,
     * unrelated `schema()`. Called only after method_exists() confirms the
     * method, so reflecting it is safe.
     */
    private function schemaExpectsFluentSchema(): bool
    {
        $parameters = (new ReflectionMethod($this, 'schema'))->getParameters();
        $firstType = ($parameters[0] ?? null)?->getType();

        return $firstType instanceof ReflectionNamedType
            && $firstType->getName() === FluentSchema::class;
    }

    protected function createDefaultValidator(ValidationFactory $factory): Validator
    {
        // A schema(FluentSchema $rules) method — the builder shape — takes
        // precedence over rules(). Detection keys off the FluentSchema-typed
        // first parameter, not just the method name, so an unrelated schema()
        // method (e.g. one returning a JSON/DB schema) is never hijacked. The
        // builder is resolved by the container from that type-hint.
        /** @var array<string, mixed>|RuleSet $rules */
        $rules = match (true) {
            method_exists($this, 'schema') && $this->schemaExpectsFluentSchema() => $this->container->call([$this, 'schema']),
            method_exists($this, 'rules') => $this->container->call([$this, 'rules']),
            default => [],
        };

        /** @var array<string, mixed> $data */
        $data = $this->validationData();

        // Auto-unwrap: rules() may return either a plain array or a RuleSet
        // (the latter pattern lets callers chain ->only/->except/->merge
        // before returning, eliminating a terminal ->toArray() call).
        $ruleSet = $rules instanceof RuleSet ? $rules : RuleSet::from($rules);
        $prepared = $ruleSet->prepare($data);

        // Pre-exclude rules whose exclude_unless/exclude_if conditions
        // don't match the actual data. This happens BEFORE the validator
        // constructor, so excluded rules are never parsed.
        $preparedRules = $this->preExcludeRules($prepared->rules, $data);

        [$fastChecks, $attributePatternMap] = $this->buildFastCheckMaps($prepared, $preparedRules);

        $messages = $this->messages() + $prepared->messages;
        $attributes = $this->attributes() + $prepared->attributes;

        // Only use OptimizedValidator when there are fast-checkable wildcard
        // rules or conditional rules were pre-excluded.
        if ($fastChecks !== [] || count($preparedRules) < count($prepared->rules)) {
            $validator = $this->makeOptimizedValidator($factory, $data, $preparedRules, $messages, $attributes);
            $validator->withFastChecks($fastChecks, $attributePatternMap);
        } else {
            /** @var Validator $validator */
            $validator = $factory->make($data, $preparedRules, $messages, $attributes);
        }

        if ($prepared->implicitAttributes !== []) {
            (new ReflectionProperty(Validator::class, 'implicitAttributes'))
                ->setValue($validator, $prepared->implicitAttributes);
        }

        $validator->stopOnFirstFailure($this->stopOnFirstFailure);

        $this->applyBatchPresenceVerifier($validator, $prepared, $preparedRules, $data);

        if ($this->isPrecognitive()) {
            $validator->setRules(
                $this->filterPrecognitiveRules($validator->getRulesWithoutPlaceholders())
            );
        }

        return $validator;
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
        $ref = new ReflectionObject($base);
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
