<?php declare(strict_types=1);

/**
 * Optional Pest expectations for the FluentRulesTester.
 *
 * Consumers `require_once` this file from their `tests/Pest.php` to opt in:
 *
 *     // tests/Pest.php
 *     require_once __DIR__ . '/../vendor/sandermuller/laravel-fluent-validation/src/Testing/PestExpectations.php';
 *
 *     it('passes', function () {
 *         expect($rules)->toPassWith($data);
 *         expect($rules)->toFailOn($data, 'email', 'email');
 *         expect($rule)->toBeFluentRuleOf(StringRule::class);
 *     });
 *
 * Safe to require_once under PHPUnit (without Pest installed): the file
 * short-circuits when `Pest\Expectation` is unavailable.
 *
 * Stable surface: the three expectation names (`toPassWith`, `toFailOn`,
 * `toBeFluentRuleOf`) and their signatures are governed by semver alongside
 * `FluentRulesTester`. The internal narrowing helpers in this file are not.
 */

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Pest\Expectation;
use PHPUnit\Framework\Assert;
use SanderMuller\FluentValidation\FluentValidator;
use SanderMuller\FluentValidation\RuleSet;
use SanderMuller\FluentValidation\Testing\FluentRulesTester;

if (! class_exists(Expectation::class)) {
    return;
}

/**
 * Re-key an array as `array<string, mixed>`, throwing on int keys. Used by
 * the narrowers below to satisfy FluentRulesTester's stricter PHPStan types
 * without weakening the public API.
 *
 * @param  array<array-key, mixed>  $array
 * @return array<string, mixed>
 */
$assertStringKeyed = static function (array $array, string $context): array {
    $stringKeyed = [];
    foreach ($array as $key => $value) {
        if (! is_string($key)) {
            throw new InvalidArgumentException("{$context} must be keyed by strings.");
        }

        $stringKeyed[$key] = $value;
    }

    return $stringKeyed;
};

/**
 * @return array<string, mixed>|class-string<FormRequest>|class-string<FluentValidator>|RuleSet|ValidationRule
 */
$narrowTarget = static function (mixed $value) use ($assertStringKeyed): array|string|RuleSet|ValidationRule {
    if ($value instanceof RuleSet || $value instanceof ValidationRule) {
        return $value;
    }

    if (is_array($value)) {
        return $assertStringKeyed($value, 'Rule arrays passed to expect()');
    }

    if (is_string($value) && (is_subclass_of($value, FormRequest::class) || is_subclass_of($value, FluentValidator::class))) {
        return $value;
    }

    throw new InvalidArgumentException('Expectation value must be an array of rules, a RuleSet, a ValidationRule, or a FormRequest/FluentValidator class-string.');
};

/**
 * @param  array<array-key, mixed>  $data
 * @return array<string, mixed>
 */
$narrowData = static fn (array $data): array => $assertStringKeyed($data, 'Validation data');

/**
 * Assert the expectation value (rules, RuleSet, FluentRule, FormRequest
 * class-string, or FluentValidator class-string) passes validation against
 * the given data.
 */
expect()->extend(
    'toPassWith',
    function (array $data) use ($narrowTarget, $narrowData): Expectation {
        /** @var Expectation<mixed> $this */
        FluentRulesTester::for($narrowTarget($this->value))
            ->with($narrowData($data))
            ->passes();

        return $this;
    },
);

/**
 * Assert the expectation value fails validation on `$field`. When `$rule`
 * is given, asserts the specific Laravel rule key failed (Studly normalized).
 */
expect()->extend(
    'toFailOn',
    function (array $data, string $field, ?string $rule = null) use ($narrowTarget, $narrowData): Expectation {
        /** @var Expectation<mixed> $this */
        FluentRulesTester::for($narrowTarget($this->value))
            ->with($narrowData($data))
            ->failsWith($field, $rule);

        return $this;
    },
);

/**
 * Assert the expectation value is an instance of the given FluentRule
 * subclass (e.g. `StringRule::class`, `NumericRule::class`). Useful when
 * asserting on the head of a fluent chain.
 */
expect()->extend('toBeFluentRuleOf', function (string $class): Expectation {
    /** @var Expectation<mixed> $this */
    Assert::assertTrue(
        class_exists($class) || interface_exists($class),
        "[{$class}] is not a loadable class or interface.",
    );

    Assert::assertInstanceOf($class, $this->value);

    return $this;
});
