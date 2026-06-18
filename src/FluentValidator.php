<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Validation\DatabasePresenceVerifier;
use ReflectionProperty;
use SanderMuller\FluentValidation\Internal\PreparesOptimizedRules;

/**
 * Base class for custom Validators that use FluentRules.
 * Handles the full prepare() pipeline automatically:
 * flatten, expand, compile, extract metadata, set implicit attributes.
 *
 *     class JsonImportValidator extends FluentValidator
 *     {
 *         public function __construct(array $data, protected ?User $user = null)
 *         {
 *             parent::__construct($data, $this->buildRules());
 *         }
 *     }
 *
 * Extends {@see OptimizedValidator}, so it picks up the same optimizations as
 * the `HasFluentRules` trait: O(n) wildcard expansion, pre-evaluation of
 * conditional rules (`exclude_unless`/`exclude_if` removed up front,
 * `required_if`/`required_unless` resolved per item), and fast-check closures
 * for wildcard patterns — instead of handing fully-expanded rules to a plain
 * `Illuminate\Validation\Validator` and paying its O(n²) conditional
 * resolution.
 */
abstract class FluentValidator extends OptimizedValidator
{
    use PreparesOptimizedRules;

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     */
    public function __construct(
        array $data,
        array $rules,
        array $messages = [],
        array $attributes = [],
    ) {
        $prepared = RuleSet::from($rules)->prepare($data);

        // Remaining conditionals (required_if/_unless) are pre-evaluated later
        // by OptimizedValidator::passes(); this only drops the exclude_* ones.
        $preparedRules = $this->preExcludeRules($prepared->rules, $data);

        [$fastChecks, $attributePatternMap] = $this->buildFastCheckMaps($prepared, $preparedRules);

        parent::__construct(
            resolve(Translator::class),
            $data,
            $preparedRules,
            $messages + $prepared->messages,
            $attributes + $prepared->attributes,
        );

        $this->withFastChecks($fastChecks, $attributePatternMap);

        if ($prepared->implicitAttributes !== []) {
            (new ReflectionProperty($this, 'implicitAttributes'))
                ->setValue($this, $prepared->implicitAttributes);
        }

        if (app()->bound('validation.presence')) {
            $this->setPresenceVerifier(resolve(DatabasePresenceVerifier::class));
        }

        // Batch exists/unique queries for wildcard items into single whereIn
        // lookups, replacing the per-row verifier set above when applicable.
        $this->applyBatchPresenceVerifier($this, $prepared, $preparedRules, $data);
    }
}
