<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Contracts;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Fluent;
use Illuminate\Support\HigherOrderWhenProxy;

/**
 * Contract implemented by every rule class shipped in
 * `SanderMuller\FluentValidation\Rules\*`.
 *
 * Use as the return-type for fluent rule arrays — collapses unwieldy
 * concrete-type unions like `FieldRule|StringRule|NumericRule|…` to one
 * stable alias downstream code can depend on:
 *
 *     /** @return array<string, FluentRuleContract> *\/
 *     public function rules(): array
 *     {
 *         return [
 *             'name'  => FluentRule::string()->required()->min(2),
 *             'email' => FluentRule::email()->required()->unique('users'),
 *             'age'   => FluentRule::numeric()->nullable()->integer()->min(0),
 *         ];
 *     }
 *
 * Scope: every modifier + conditional + metadata method universally shared
 * across all rule classes via the `HasFieldModifiers` + `SelfValidates`
 * traits, plus `ValidationRule` (Laravel's native contract that every rule
 * class already implements). Type-specific methods (`StringRule::email()`,
 * `NumericRule::integer()`, `ImageRule::dimensions()`, etc.) stay on their
 * concrete class — narrowing to the concrete type is the right move when
 * downstream code calls type-specific methods.
 *
 * All chaining methods return `static` so concrete subclasses keep their
 * own type when callers narrow to the concrete class.
 */
interface FluentRuleContract extends ValidationRule
{
    // ---------- Metadata ----------

    public function label(string $label): static;

    public function getLabel(): ?string;

    public function message(string $message): static;

    public function messageFor(string $rule, string $message): static;

    public function fieldMessage(string $message): static;

    /**
     * @return array<string, string>
     */
    public function getCustomMessages(): array;

    // ---------- Presence / prohibition modifiers ----------

    public function bail(): static;

    public function nullable(): static;

    public function required(): static;

    public function sometimes(): static;

    public function filled(): static;

    public function present(): static;

    public function prohibited(): static;

    public function exclude(): static;

    public function missing(): static;

    // ---------- Conditional presence ----------

    public function presentIf(string $field, string|int|bool|BackedEnum ...$values): static;

    public function presentUnless(string $field, string|int|bool|BackedEnum ...$values): static;

    public function presentWith(string ...$fields): static;

    public function presentWithAll(string ...$fields): static;

    public function missingIf(string $field, string|int|bool|BackedEnum ...$values): static;

    public function missingUnless(string $field, string|int|bool|BackedEnum ...$values): static;

    public function missingWith(string ...$fields): static;

    public function missingWithAll(string ...$fields): static;

    public function requiredIf(Closure|bool|string $field, string|int|bool|BackedEnum ...$values): static;

    public function requiredUnless(Closure|bool|string $field, string|int|bool|BackedEnum ...$values): static;

    public function requiredWith(string ...$fields): static;

    public function requiredWithAll(string ...$fields): static;

    public function requiredWithout(string ...$fields): static;

    public function requiredWithoutAll(string ...$fields): static;

    public function requiredIfAccepted(string $field): static;

    public function requiredIfDeclined(string $field): static;

    public function excludeIf(Closure|bool|string $field, string|int|bool|BackedEnum ...$values): static;

    public function excludeUnless(Closure|bool|string $field, string|int|bool|BackedEnum ...$values): static;

    public function excludeWith(string $field): static;

    public function excludeWithout(string $field): static;

    public function prohibitedIf(Closure|bool|string $field, string|int|bool|BackedEnum ...$values): static;

    public function prohibitedUnless(Closure|bool|string $field, string|int|bool|BackedEnum ...$values): static;

    public function prohibits(string ...$fields): static;

    public function prohibitedIfAccepted(string $field): static;

    public function prohibitedIfDeclined(string $field): static;

    // ---------- Escape hatch ----------

    /**
     * @param  object|string|array<int, string>  $rule
     */
    public function rule(object|string|array $rule): static;

    // ---------- Conditional dispatch ----------

    /**
     * Apply `$callback` to this rule when `$value` is truthy; otherwise
     * optionally apply `$default`. Inherited from Laravel's `Conditionable`
     * trait — on the contract so downstream code can narrow to
     * `FluentRuleContract` and still chain `when()` / `unless()`.
     *
     * Return type omitted to match Laravel's `Conditionable` trait exactly
     * — adding a return type here (including `mixed`) would trip
     * `Declaration must be compatible` at class-load time. The effective
     * return is `$this|\Illuminate\Conditionable\HigherOrderWhenProxy`
     * depending on whether a callback is supplied.
     *
     * @param  mixed  $value
     * @param  (callable(static, mixed): mixed)|null  $callback
     * @param  (callable(static, mixed): mixed)|null  $default
     * @return static|HigherOrderWhenProxy
     */
    public function when($value = null, ?callable $callback = null, ?callable $default = null);

    /**
     * @param  mixed  $value
     * @param  (callable(static, mixed): mixed)|null  $callback
     * @param  (callable(static, mixed): mixed)|null  $default
     * @return static|HigherOrderWhenProxy
     */
    public function unless($value = null, ?callable $callback = null, ?callable $default = null);

    /**
     * @param  Closure(Fluent<string, mixed>): bool  $condition
     * @param  Closure(static): static|string|list<string>  $rules
     * @param  Closure(static): static|string|list<string>  $defaultRules
     */
    public function whenInput(Closure $condition, Closure|string|array $rules, Closure|string|array $defaultRules = []): static;

    // ---------- Self-compilation ----------

    /**
     * Whether `compiledRules()` can safely return a pipe-string form
     * (true) vs must return the array form (false, when the rule set
     * contains non-stringifiable objects).
     */
    public function canCompile(): bool;

    /**
     * @return string|list<string|object>
     */
    public function compiledRules(): string|array;

    /**
     * @return list<string|object>
     */
    public function toArray(): array;

    /**
     * Expand this rule into a flat `[nestedAttribute => rule]` map for
     * the given parent attribute. Used by `each()` / `children()` and
     * by `RuleSet::expand` to fan out nested rule sets.
     *
     * @return array<string, mixed>
     */
    public function buildNestedRules(string $attribute): array;
}
