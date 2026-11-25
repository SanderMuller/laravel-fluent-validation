<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Closure;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\Validator;
use SanderMuller\FluentValidation\OptimizedValidator;

/**
 * A validator for data that RuleSet's own engine already validated. It
 * starts without rules: `passes()` runs only the after hooks, and
 * `validated()` gives RuleSet's result while no hook added an error.
 *
 * Code that reads or changes the rules or the data gets the validator of
 * the full path: the validator first builds those rules on itself.
 *
 * @internal Implementation detail of `HasFluentRules`.
 */
final class PrevalidatedValidator extends OptimizedValidator
{
    /** @var array<array-key, mixed> */
    private array $prevalidated = [];

    /** @var (Closure(self): void)|null builds the rules of the full path on the validator, null once it did */
    private ?Closure $fullPath = null;

    /**
     * A validator with the configuration of the factory, or null when the
     * factory makes another kind of validator.
     *
     * @param  array<array-key, mixed>  $data
     * @param  array<array-key, mixed>  $validated  RuleSet's validated data, in Laravel's key order
     * @param  array<array-key, mixed>  $messages
     * @param  array<array-key, mixed>  $attributes
     * @param  Closure(self): void  $fullPath
     */
    public static function make(ValidationFactory $factory, array $data, array $validated, array $messages, array $attributes, Closure $fullPath): ?self
    {
        $base = $factory->make([], [], $messages, $attributes);

        if (! $base instanceof Validator) {
            return null;
        }

        $validator = new self($base->getTranslator(), $data, [], $messages, $attributes);
        ValidatorStateCopier::copy($base, $validator);
        $validator->prevalidated = $validated;
        $validator->fullPath = $fullPath;

        return $validator;
    }

    /**
     * Sets the rules of the full path, with their messages and attribute
     * names after those of the request, as on the full path.
     *
     * @param  array<string, mixed>  $rules
     * @param  array<array-key, mixed>  $messages
     * @param  array<array-key, mixed>  $attributes
     */
    public function withFullPathRules(array $rules, array $messages, array $attributes): static
    {
        $this->customMessages += $messages;
        $this->customAttributes += $attributes;
        OptimizedRules::set($this, $rules);

        return $this;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function validated(): array
    {
        if (! $this->fullPath instanceof Closure) {
            return parent::validated();
        }

        if (! $this->messages instanceof MessageBag) {
            $this->passes();
        }

        return $this->messages->isNotEmpty() ? parent::validated() : $this->prevalidated;
    }

    public function setData(array $data): static
    {
        $this->buildFullPath();

        return parent::setData($data);
    }

    public function setValue($attribute, $value): void
    {
        $this->buildFullPath();

        parent::setValue($attribute, $value);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getRules(): array
    {
        $this->buildFullPath();

        return parent::getRules();
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getRulesWithoutPlaceholders(): array
    {
        $this->buildFullPath();

        return parent::getRulesWithoutPlaceholders();
    }

    public function setRules(array $rules): static
    {
        $this->buildFullPath();

        return parent::setRules($rules);
    }

    /**
     * @param  array<array-key, mixed>  $rules
     */
    public function addRules($rules): void
    {
        $this->buildFullPath();

        parent::addRules($rules);
    }

    /**
     * @param  string|array<array-key, mixed>  $attribute
     * @param  string|array<array-key, mixed>  $rules
     */
    public function sometimes($attribute, $rules, callable $callback): static
    {
        $this->buildFullPath();

        return parent::sometimes($attribute, $rules, $callback);
    }

    private function buildFullPath(): void
    {
        if (! $this->fullPath instanceof Closure) {
            return;
        }

        $build = $this->fullPath;
        $this->fullPath = null;
        $build($this);
    }
}
