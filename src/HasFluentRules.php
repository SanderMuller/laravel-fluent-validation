<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Illuminate\Container\Container;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Validation\Validator;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use SanderMuller\FluentValidation\Internal\PreparesOptimizedRules;
use SanderMuller\FluentValidation\Internal\ValidatorStateCopier;

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
     * Memoized schema()-vs-rules() precedence per concrete class. The winner is
     * fixed by where each method is declared, which never changes at runtime,
     * so it is resolved by reflection once per class. Bounded by the number of
     * FormRequest classes, so it needs no eviction.
     *
     * @var array<class-string, bool>
     */
    private static array $schemaOutranksRulesByClass = [];

    /**
     * Fallback so a request that defines only `schema()` still answers
     * `rules()` — returning what the builder produced (the raw field map, the
     * same shape a hand-written `rules()` would return) so tooling or interop
     * expecting a `rules()` method keeps working. A consumer-defined `rules()`
     * overrides this and it never runs; its signature must stay compatible
     * (`public`, returning `array` or `RuleSet`). Returns `[]` when there is no
     * FluentSchema-typed `schema()`.
     *
     * @return array<string, mixed>|RuleSet
     */
    public function rules(): array|RuleSet
    {
        if (! method_exists($this, 'schema') || ! $this->schemaExpectsFluentSchema()) {
            return [];
        }

        // Resolve schema()'s FluentSchema (and any other typed dependency) from
        // the global container. This can be called before the request is
        // resolved (when its own container isn't set yet), and schema() has no
        // request-scoped dependencies, so the app container is enough — in a
        // booted app it is the same instance the request would hold anyway.
        /** @var array<string, mixed>|RuleSet $schema */
        $schema = Container::getInstance()->call([$this, 'schema']);

        return $schema;
    }

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

    /**
     * Merge the already-resolved rule sets from schema() and rules() into one.
     * The more specific declaration wins each shared field (see
     * {@see schemaOutranksRules()}), so a base class or trait can define shared
     * fields via one method and each implementor override or extend them via
     * the other. Neither source is dropped and a collision never throws.
     * Sources are resolved by the caller (where method existence is proven), so
     * this never references a method the using class may not declare.
     */
    private function mergeSchemaAndRules(mixed $schema, mixed $rules): RuleSet
    {
        /** @var array<string, mixed>|RuleSet $schema */
        $schemaSet = $schema instanceof RuleSet ? $schema : RuleSet::from($schema);
        /** @var array<string, mixed>|RuleSet $rules */
        $rulesSet = $rules instanceof RuleSet ? $rules : RuleSet::from($rules);

        // RuleSet::merge lets the argument override the receiver on shared keys,
        // so merge the loser as the base and overlay the winner on top.
        return $this->schemaOutranksRules()
            ? $rulesSet->merge($schemaSet)
            : $schemaSet->merge($rulesSet);
    }

    private function schemaOutranksRules(): bool
    {
        return self::$schemaOutranksRulesByClass[$this::class] ??= $this->resolveSchemaOutranksRules();
    }

    /**
     * Whether schema()'s declaration outranks rules()' on a shared field. Each
     * method resolves to one live definition on this object's linear ancestor
     * chain, so the deeper (more-derived) declaring class wins — a concrete
     * request beats the abstract base or trait it inherits the other method
     * from. When both resolve to the same class (e.g. one pulled from a trait,
     * the other defined in the class body), a body definition outranks a trait
     * import; if neither or both come from a trait the tie is genuine and
     * schema() wins (preserving the pre-merge precedence for that shape).
     */
    private function resolveSchemaOutranksRules(): bool
    {
        $schemaClass = (new ReflectionMethod($this, 'schema'))->getDeclaringClass();
        $rulesClass = (new ReflectionMethod($this, 'rules'))->getDeclaringClass();

        if ($schemaClass->getName() !== $rulesClass->getName()) {
            return $schemaClass->isSubclassOf($rulesClass->getName());
        }

        // schema() wins the same-class tie unless it is the trait import and
        // rules() is the more-specific body definition.
        if (! $this->methodImportedFromTrait('schema')) {
            return true;
        }

        return $this->methodImportedFromTrait('rules');
    }

    /**
     * Best-effort check of whether the resolved $method lives in a trait rather
     * than the declaring class's own body, by comparing the method's source
     * file to the class's. If the file can't be determined, it is treated as
     * body-level.
     */
    private function methodImportedFromTrait(string $method): bool
    {
        $reflection = new ReflectionMethod($this, $method);
        $classFile = $reflection->getDeclaringClass()->getFileName();
        $methodFile = $reflection->getFileName();

        return $classFile !== false && $methodFile !== false && $methodFile !== $classFile;
    }

    /**
     * Whether the consumer declared their own rules(), as opposed to inheriting
     * this trait's fallback. The fallback lives in this file, so a rules()
     * resolved to any other file is consumer-defined and counts as a rule
     * source; the fallback does not (it just re-exposes schema()).
     */
    private function definesOwnRules(): bool
    {
        return (new ReflectionMethod($this, 'rules'))->getFileName() !== __FILE__;
    }

    protected function createDefaultValidator(ValidationFactory $factory): Validator
    {
        // schema(FluentSchema $rules) — the builder shape — and rules() are both
        // rule sources. schema() detection keys off the FluentSchema-typed first
        // parameter (not just the method name), so an unrelated schema() method
        // (e.g. one returning a JSON/DB schema) is never hijacked. rules() counts
        // only when the consumer declared their own (definesOwnRules()); the
        // trait's fallback rules() just re-exposes schema() and must not trigger
        // a merge. When both are present they are merged rather than one shadowing
        // the other — the more specific declaration wins each shared field (see
        // mergeSchemaAndRules()), so an abstract base or trait can supply one
        // method and a concrete request the other. Each source is resolved by the
        // container.
        $hasSchema = method_exists($this, 'schema') && $this->schemaExpectsFluentSchema();
        $hasRules = $this->definesOwnRules();

        /** @var array<string, mixed>|RuleSet $rules */
        $rules = match (true) {
            $hasSchema && $hasRules => $this->mergeSchemaAndRules(
                $this->container->call([$this, 'schema']),
                $this->container->call([$this, 'rules']),
            ),
            $hasSchema => $this->container->call([$this, 'schema']),
            $hasRules => $this->container->call([$this, 'rules']),
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
        // The parsed rules AND factory-applied configuration are copied from
        // the base validator below.
        $optimized = new OptimizedValidator(
            $base->getTranslator(),
            $data,
            [],
            $messages,
            $attributes,
        );

        ValidatorStateCopier::copy($base, $optimized);

        return $optimized;
    }
}
