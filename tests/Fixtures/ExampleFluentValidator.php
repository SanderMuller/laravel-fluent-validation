<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests\Fixtures;

use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\FluentValidator;

/**
 * FluentValidator subclass mirroring hihaho's JsonImportValidator shape:
 * accepts an extra ctor arg (`$prefix`) used inside buildRules() to shape
 * the effective rule set. Exercises the tester's variadic ctor-arg
 * forwarding contract.
 *
 * @internal
 */
final class ExampleFluentValidator extends FluentValidator
{
    /** @param  array<string, mixed>  $data */
    public function __construct(array $data, private readonly string $prefix = '')
    {
        parent::__construct($data, $this->buildRules());
    }

    /** @return array<string, mixed> */
    private function buildRules(): array
    {
        $rule = FluentRule::string()->required();

        if ($this->prefix !== '') {
            $rule = $rule->startsWith($this->prefix);
        }

        return ['name' => $rule];
    }
}
