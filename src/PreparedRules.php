<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

/**
 * Result of RuleSet::prepare(). Contains everything needed to construct
 * a Validator with full optimization, metadata, and implicit attributes.
 *
 *     $prepared = RuleSet::from($rules)->prepare($data);
 *     $validator = Validator::make($data, $prepared->rules, $prepared->messages, $prepared->attributes);
 */
final readonly class PreparedRules
{
    /**
     * @param  array<string, mixed>  $rules  Compiled rules in native Laravel format
     * @param  array<string, string>  $messages  Extracted per-rule messages (from ->message() / ->fieldMessage())
     * @param  array<string, string>  $attributes  Extracted labels (from ->label() / factory argument)
     * @param  array<string, list<string>>  $implicitAttributes  Wildcard-to-concrete path mapping
     */
    public function __construct(
        public array $rules,
        public array $messages = [],
        public array $attributes = [],
        public array $implicitAttributes = [],
    ) {}
}
