<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\Preset;
use Boundwize\StructArmed\Preset\Presets\CodeQualityPreset;
use Boundwize\StructArmed\Preset\Presets\Psr4Preset;

return Architecture::define()
    ->skip([
        // pest tests doesn't allow anonymous functions to be static
        CodeQualityPreset::ANONYMOUS_FUNCTIONS_MUST_BE_STATIC => [
            __DIR__ . '/tests',
        ],
        // some tests don't have namespace and have multiple classses
        Psr4Preset::CLASSES_MUST_MATCH_COMPOSER => [
            __DIR__ . '/tests',
        ],
    ])
    ->withPresets(Preset::PSR4(), Preset::CODEQUALITY())
    ->layer('Contracts', 'src/Contracts')
    ->layer('Exceptions', 'src/Exceptions')
    ->layer('RuleConcerns', 'src/Rules/Concerns')
    ->layer('Rules', 'src/Rules', 'src/Rules/Concerns')
    ->layer('FluentRule', ['src/FluentRule.php', 'src/FluentSchema.php'])
    ->layer('FastCheck', ['src/FastCheckCompiler.php', 'src/FastCheck'])
    ->layer('Results', ['src/PreparedRules.php', 'src/Validated.php'])
    // Per-item pre-evaluation of Laravel's conditional rules.
    ->layer('ConditionalRules', [
        'src/Internal/AbstractConditionalReducer.php',
        'src/Internal/ConditionalEvaluationPhase.php',
        'src/Internal/ConditionalValueMatcher.php',
        'src/Internal/ConditionalVerdict.php',
        'src/Internal/ExcludeConditionExtractor.php',
        'src/PresenceConditionalReducer.php',
        'src/ValueConditionalReducer.php',
    ])
    // Generic validators: drop-in subclasses of Laravel's Validator.
    ->layer('GenericValidator', ['src/MemoizingValidator.php', 'src/OptimizedValidator.php'])
    ->layer(
        'Internal',
        [
            'src/Internal',
            'src/BatchDatabaseChecker.php',
            'src/PrecomputedPresenceVerifier.php',
        ],
        // The conditional-rule helpers are part of the ConditionalRules layer defined above.
        [
            'src/Internal/AbstractConditionalReducer.php',
            'src/Internal/ConditionalEvaluationPhase.php',
            'src/Internal/ConditionalValueMatcher.php',
            'src/Internal/ConditionalVerdict.php',
            'src/Internal/ExcludeConditionExtractor.php',
        ]
    )
    ->layer('RuleSet', ['src/RuleSet.php', 'src/WildcardExpander.php'])
    // Specific validator: the base class for app validators built on fluent rules.
    ->layer('FluentValidator', ['src/FluentRules.php', 'src/FluentValidator.php'])
    ->layer('FormRequest', ['src/FluentFormRequest.php', 'src/HasFluentRules.php'])
    ->layer('Livewire', ['src/HasFluentValidation.php', 'src/HasFluentValidationForFilament.php'])
    ->layer('Testing', 'src/Testing', 'src/Testing/Arch')
    ->layer('TestingArch', 'src/Testing/Arch')
    ->ruleset([
        'Contracts' => [],
        'RuleConcerns' => [],
        // The typed-builder hint exceptions name the builders they point to.
        'Exceptions' => ['Rules'],
        'Rules' => ['Contracts', 'Exceptions', 'RuleConcerns'],
        'FluentRule' => ['Rules'],
        'FastCheck' => [],
        'Results' => [],
        'ConditionalRules' => [],
        'GenericValidator' => ['ConditionalRules', 'FastCheck'],
        'Internal' => ['+GenericValidator', 'Exceptions', 'Results'],
        'RuleSet' => ['+FluentRule', '+Internal'],
        'FluentValidator' => ['+RuleSet'],
        'FormRequest' => ['+RuleSet'],
        'Livewire' => ['+RuleSet'],
        'Testing' => ['+FluentValidator'],
        'TestingArch' => ['+FluentRule', 'Exceptions'],
    ]);
