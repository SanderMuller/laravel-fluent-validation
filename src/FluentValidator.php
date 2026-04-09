<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\Validator;
use ReflectionProperty;

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
 */
abstract class FluentValidator extends Validator
{
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

        parent::__construct(
            resolve(Translator::class),
            $data,
            $prepared->rules,
            $messages + $prepared->messages,
            $attributes + $prepared->attributes,
        );

        if ($prepared->implicitAttributes !== []) {
            (new ReflectionProperty($this, 'implicitAttributes'))
                ->setValue($this, $prepared->implicitAttributes);
        }

        if (app()->bound('validation.presence')) {
            $this->setPresenceVerifier(resolve(DatabasePresenceVerifier::class));
        }
    }
}
