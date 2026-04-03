<?php

declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Illuminate\Support\MessageBag;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * @internal Used by HasFluentRules to provide per-item validation
 * with fast-check closures inside FormRequests.
 */
class OptimizedValidator extends Validator
{
    private bool $hasValidated = false;

    public function __construct(
        private readonly RuleSet $ruleSet,
        Validator $base,
    ) {
        parent::__construct(
            $base->getTranslator(),
            $base->getData(),
            $base->getRules(),
            $base->customMessages,
            $base->customAttributes,
        );

        $this->setPresenceVerifier($base->getPresenceVerifier());
    }

    public function passes(): bool
    {
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

            if (property_exists($e->validator, 'failedRules')) {
                $this->failedRules = $e->validator->failedRules;
            }

            return false;
        }
    }
}
