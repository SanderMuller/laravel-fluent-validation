<?php

declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Illuminate\Support\MessageBag;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use ReflectionProperty;

/**
 * Base class for custom Validators that use FluentRules.
 * Handles the full prepare() pipeline and uses per-item validation
 * with fast-check closures for optimal performance.
 *
 *     class JsonImportValidator extends FluentValidator
 *     {
 *         public function __construct(array $data, protected ?User $user = null)
 *         {
 *             parent::__construct($data, $this->buildRules());
 *         }
 *     }
 */
abstract class FluentValidator extends Validator
{
    private RuleSet $ruleSet;

    private bool $hasValidated = false;

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
        $this->ruleSet = RuleSet::from($rules);
        $prepared = $this->ruleSet->prepare($data);

        parent::__construct(
            app('translator'),
            $data,
            $prepared->rules,
            array_merge($prepared->messages, $messages),
            array_merge($prepared->attributes, $attributes),
        );

        if ($prepared->implicitAttributes !== []) {
            (new ReflectionProperty($this, 'implicitAttributes'))
                ->setValue($this, $prepared->implicitAttributes);
        }

        if (app()->bound('validation.presence')) {
            $this->setPresenceVerifier(app(DatabasePresenceVerifier::class));
        }
    }

    /**
     * Run validation using the optimized per-item pipeline.
     * Falls back to parent::passes() for after-hooks and non-wildcard rules.
     */
    public function passes(): bool
    {
        // Run the optimized path once, cache the result.
        // Subsequent calls (e.g. from fails()) reuse the cached messages.
        if ($this->hasValidated) {
            return $this->messages->isEmpty();
        }

        $this->hasValidated = true;

        try {
            $this->ruleSet->validate(
                $this->data,
                $this->customMessages,
                $this->customAttributes,
            );

            $this->messages = new MessageBag();

            return true;
        } catch (ValidationException $e) {
            $this->messages = $e->validator->errors();

            // Copy failed rules for Laravel's error formatting
            if (property_exists($e->validator, 'failedRules')) {
                $this->failedRules = $e->validator->failedRules;
            }

            return false;
        }
    }
}
