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

        return $validator;
    }

    /**
     * Build fast-check closures and the attribute-to-pattern lookup map.
     *
     * @param  array<string, mixed>  $preparedRules  Rules after pre-exclusion
     * @return array{0: array<string, \Closure(mixed): bool>, 1: array<string, string>}
     */
    private function buildFastCheckMaps(PreparedRules $prepared, array $preparedRules): array
    {
        $wildcardRules = [];
        foreach ($prepared->implicitAttributes as $pattern => $expandedPaths) {
            if (isset($preparedRules[$expandedPaths[0] ?? ''])) {
                $wildcardRules[$pattern] = $preparedRules[$expandedPaths[0]];
            }
        }

        $fastChecks = OptimizedValidator::buildFastChecks($wildcardRules);

        return [$fastChecks, $this->buildAttributePatternMap($fastChecks, $prepared->implicitAttributes, $preparedRules)];
    }

    /**
     * @param  array<string, \Closure(mixed): bool>  $fastChecks
     * @param  array<string, list<string>>  $implicitAttributes
     * @param  array<string, mixed>  $preparedRules
     * @return array<string, string>
     */
    private function buildAttributePatternMap(array $fastChecks, array $implicitAttributes, array $preparedRules): array
    {
        $map = [];

        foreach ($implicitAttributes as $pattern => $expandedPaths) {
            if (isset($fastChecks[$pattern])) {
                foreach ($expandedPaths as $path) {
                    if (isset($preparedRules[$path])) {
                        $map[$path] = $pattern;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * Pre-exclude rules whose exclude_unless/exclude_if conditions
     * don't match the actual data. Operates on expanded rules (concrete
     * paths like interactions.5.style.top) before they reach the validator.
     *
     * @param  array<string, mixed>  $rules
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function preExcludeRules(array $rules, array $data): array
    {
        /** @var array<string, string> $cache */
        $cache = [];

        foreach ($rules as $attribute => $ruleSet) {
            if (! is_array($ruleSet)) {
                continue;
            }

            foreach ($ruleSet as $rule) {
                if (! is_array($rule)) {
                    continue;
                }

                if (count($rule) < 3) {
                    continue;
                }

                $action = $rule[0];

                if ($action !== 'exclude_unless' && $action !== 'exclude_if') {
                    continue;
                }

                /** @var string $conditionField */
                $conditionField = $rule[1];
                /** @var list<string> $allowedValues */
                $allowedValues = array_slice($rule, 2);

                // Resolve wildcard: interactions.*.type → interactions.5.type
                if (str_contains($conditionField, '*')) {
                    preg_match_all('/\.(\d+)(?:\.|$)/', (string) $attribute, $m);
                    /** @var list<numeric-string> $indices */
                    $indices = $m[1];
                    $idx = 0;
                    $conditionField = (string) preg_replace_callback('/\*/', function () use ($indices, &$idx): string {
                        return $indices[$idx++] ?? '*';
                    }, $conditionField);
                }

                if (! isset($cache[$conditionField])) {
                    $rawValue = data_get($data, $conditionField);
                    $cache[$conditionField] = is_scalar($rawValue) ? (string) $rawValue : '';
                }

                $actual = $cache[$conditionField];

                $shouldExclude = ($action === 'exclude_unless' && ! in_array($actual, $allowedValues, true))
                    || ($action === 'exclude_if' && in_array($actual, $allowedValues, true));

                if ($shouldExclude) {
                    unset($rules[$attribute]);
                    break;
                }
            }
        }

        return $rules;
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
