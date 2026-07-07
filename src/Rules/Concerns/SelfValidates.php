<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Rules\Concerns;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\ExcludeIf;
use Illuminate\Validation\Rules\ExcludeUnless;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\NotIn;
use Illuminate\Validation\Rules\ProhibitedIf;
use Illuminate\Validation\Rules\ProhibitedUnless;
use Illuminate\Validation\Rules\RequiredIf;
use Illuminate\Validation\Rules\RequiredUnless;
use ReflectionProperty;
use Stringable;

trait SelfValidates
{
    public bool $implicit = true;

    protected \Illuminate\Validation\Validator $validator;

    /** @var array<array-key, mixed> */
    protected array $data = [];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->hasPresenceModifier() && ! Arr::has($this->data, $attribute)) {
            return;
        }

        if ($this->shouldExclude($attribute)) {
            return;
        }

        $nestedRules = $this->buildNestedRules($attribute);

        // A null value with `nullable` skips validation — but only when there
        // are no nested (`each()` / `children()`) rules. With nested rules,
        // defer to the inner validator so a required child behaves like native
        // Laravel (and the compiled HasFluentRules path): enforced on a null
        // parent rather than silently skipped. Wildcard `each()` still passes
        // (it expands to nothing over null); only fixed `children()` differ.
        if ($nestedRules === [] && $this->isNullable($value)) {
            return;
        }

        $rules = [$attribute => $this->buildValidationRules()] + $nestedRules;

        $innerValidator = Validator::make(
            $this->data,
            $rules,
            $this->buildMessages($attribute),
            $this->buildAttributes($attribute),
        );

        if ($innerValidator->passes()) {
            return;
        }

        $this->forwardErrors($innerValidator, $attribute, $nestedRules !== [], $fail);
    }

    /** @return array<string, string> */
    private function buildMessages(string $attribute): array
    {
        /** @var array<string, string> $messages */
        $messages = $this->validator->customMessages ?? [];

        foreach ($this->getCustomMessages() as $ruleName => $message) {
            $messages[$ruleName === '' ? $attribute : $attribute . '.' . $ruleName] = $message;
        }

        return $messages;
    }

    /** @return array<string, string> */
    private function buildAttributes(string $attribute): array
    {
        /** @var array<string, string> $attributes */
        $attributes = $this->validator->customAttributes ?? [];

        if ($this->getLabel() !== null) {
            $attributes[$attribute] = $this->getLabel();
        }

        return $attributes;
    }

    private function forwardErrors(
        \Illuminate\Validation\Validator $innerValidator,
        string $attribute,
        bool $hasNestedRules,
        Closure $fail,
    ): void {
        /** @var array<string, list<string>> $errors */
        $errors = $innerValidator->errors()->toArray();

        foreach ($errors as $errorAttribute => $errorMessages) {
            foreach ($errorMessages as $errorMessage) {
                if ($hasNestedRules && $errorAttribute !== $attribute) {
                    $this->validator->errors()->add($errorAttribute, $errorMessage);
                } else {
                    $fail($errorMessage);
                }
            }
        }

        $this->forwardFailedRuleIdentifiers($innerValidator);
    }

    /**
     * Copy failed rule identifiers from the inner validator to the outer
     * so assertHasErrors(['field' => 'rule']) and $validator->failed() work.
     */
    private function forwardFailedRuleIdentifiers(\Illuminate\Validation\Validator $innerValidator): void
    {
        /** @var array<string, array<string, list<mixed>>> $failedRules */
        $failedRules = $innerValidator->failed();

        if ($failedRules === []) {
            return;
        }

        $prop = new ReflectionProperty($this->validator, 'failedRules');

        /** @var array<string, array<string, list<mixed>>> $existing */
        $existing = $prop->getValue($this->validator);

        foreach ($failedRules as $failedAttribute => $rules) {
            /** @var array<string, list<mixed>> $rules */
            foreach ($rules as $rule => $params) {
                $existing[$failedAttribute][$rule] = $params;
            }
        }

        $prop->setValue($this->validator, $existing);
    }

    private function isNullable(mixed $value): bool
    {
        return is_null($value)
            && ! $this->hasPresenceForcingModifier()
            && in_array('nullable', $this->constraints, true);
    }

    /**
     * Exact rule names (the part before any `:`) whose rules must be evaluated
     * against a null/absent value rather than skipped by `nullable`. Matched
     * exactly — not by prefix — so a non-presence rule that merely shares a
     * stem (most notably `required_array_keys`) never counts, and no entry is
     * silently subsumed by another (`required_with` vs `required_without`).
     */
    private const PRESENCE_FORCING_RULES = [
        'required', 'filled', 'present', 'missing',
        'required_if', 'required_unless',
        'required_with', 'required_with_all',
        'required_without', 'required_without_all',
        'required_if_accepted', 'required_if_declined',
        'present_if', 'present_unless', 'present_with', 'present_with_all',
        'missing_if', 'missing_unless', 'missing_with', 'missing_with_all',
    ];

    /**
     * Whether the chain carries a modifier that forces evaluation of a
     * null/absent value, in which case `nullable` must NOT short-circuit
     * validation. Covers every presence family that diverges from a plain
     * `nullable` skip:
     *
     *   - required — literal `required`, the string conditionals
     *     (`required_if`/`required_unless`/`required_with`/`required_without`
     *     and their `_all` / `_if_accepted` / `_if_declined` variants), and the
     *     object conditionals `RequiredIf` / `RequiredUnless` (bool/closure).
     *   - `filled`  — implicit in Laravel; rejects a present-null value.
     *   - `present` / `present_*` — require the key to be in the input.
     *   - `missing` / `missing_*` — require the key to be absent (so a
     *     present-null value must fail).
     *
     * When present, the inner Laravel validator evaluates the modifier
     * together with `nullable` exactly as native Laravel does. `prohibited*`
     * is intentionally excluded: it is satisfied by an empty/null value, so the
     * `nullable` short-circuit already yields the native result.
     *
     * Without this guard, any of these modifiers combined with `nullable()`
     * silently dropped its constraint for a null/missing field, because only
     * the literal `'required'` string was once checked here while the rest live
     * as `*_` constraint strings or as `RequiredIf`/`RequiredUnless` objects in
     * `$this->rules`.
     */
    private function hasPresenceForcingModifier(): bool
    {
        foreach ($this->constraints as $constraint) {
            $name = explode(':', (string) $constraint, 2)[0];

            if (in_array($name, self::PRESENCE_FORCING_RULES, true)) {
                return true;
            }
        }

        foreach ($this->rules as $rule) {
            if ($rule instanceof RequiredIf || $rule instanceof RequiredUnless) {
                return true;
            }
        }

        return false;
    }

    protected function hasPresenceModifier(): bool
    {
        if (array_intersect($this->constraints, ['required', 'sometimes', 'nullable', 'present']) !== []) {
            return true;
        }

        if ($this->hasPresenceConstraint()) {
            return true;
        }

        return $this->hasPresenceRule();
    }

    private function hasPresenceConstraint(): bool
    {
        foreach ($this->constraints as $constraint) {
            if (str_starts_with((string) $constraint, 'required_')
                || str_starts_with((string) $constraint, 'present_')
                || str_starts_with((string) $constraint, 'exclude')
                || str_starts_with((string) $constraint, 'prohibited')
                || str_starts_with((string) $constraint, 'missing')
            ) {
                return true;
            }
        }

        return false;
    }

    private function hasPresenceRule(): bool
    {
        foreach ($this->rules as $rule) {
            if ($rule instanceof RequiredIf
                || $rule instanceof RequiredUnless
                || $rule instanceof ProhibitedIf
                || $rule instanceof ProhibitedUnless
                || $rule instanceof ExcludeIf
                || $rule instanceof ExcludeUnless
            ) {
                return true;
            }
        }

        return false;
    }

    private function shouldExclude(string $attribute): bool
    {
        // Check ExcludeIf/ExcludeUnless rule objects (closure/bool variant)
        foreach ($this->rules as $rule) {
            if ($rule instanceof ExcludeIf || $rule instanceof ExcludeUnless) {
                $resolved = (string) $rule;

                if ($resolved === 'exclude') {
                    $this->validator->addFailure($attribute, 'Exclude');

                    return true;
                }
            }
        }

        // Check string-based exclude constraints
        foreach ($this->constraints as $constraint) {
            if ($constraint === 'exclude') {
                $this->validator->addFailure($attribute, 'Exclude');

                return true;
            }
        }

        return false;
    }

    public function canCompile(): bool
    {
        return $this->rules === [];
    }

    /** @var string|list<string|object>|null */
    protected string|array|null $compiledCache = null;

    public function __clone(): void
    {
        $this->compiledCache = null;
    }

    /**
     * Compile to native Laravel format. Returns a pipe-joined string
     * when possible (faster for Laravel to parse), or an array when
     * non-stringable object rules are present. Memoized since rule
     * objects are immutable after construction.
     *
     * @return string|list<string|object>
     */
    public function compiledRules(): string|array
    {
        return $this->compiledCache ??= $this->buildCompiledRules();
    }

    /**
     * Get the compiled rules as an array. Useful for debugging and testing.
     *
     * @return list<string|object>
     */
    public function toArray(): array
    {
        $compiled = $this->compiledRules();

        if (is_array($compiled)) {
            return $compiled;
        }

        return $compiled === '' ? [] : explode('|', $compiled);
    }

    /**
     * Dump the compiled rules and terminate execution.
     */
    public function dd(mixed ...$args): never
    {
        dd($this->toArray(), ...$args);
    }

    /**
     * Dump the compiled rules.
     */
    public function dump(mixed ...$args): static
    {
        dump($this->toArray(), ...$args);

        return $this;
    }

    /** @return string|list<string|object> */
    private function buildCompiledRules(): string|array
    {
        $rules = $this->buildValidationRules();

        // Try to produce a pipe-joined string for fastest Laravel parsing.
        // Only In and NotIn are safe to stringify — Exists/Unique/Dimensions
        // can have closure-based wheres that are silently dropped by __toString().
        /** @var list<string> $stringified */
        $stringified = [];
        $allStringifiable = true;

        foreach ($rules as $rule) {
            if (is_string($rule)) {
                $stringified[] = $rule;
            } elseif ($rule instanceof Stringable && $this->isSafeToStringify($rule)) {
                $stringified[] = (string) $rule;
            } else {
                $allStringifiable = false;
                break;
            }
        }

        if ($allStringifiable && ! self::anyContainsPipe($stringified)) {
            // Skip the pipe-join when a rule token contains a literal `|` — e.g.
            // `regex:/^(a|b)$/`. Laravel's parser splits string rules on `|`, so
            // joining would corrupt the pattern; the array form below is parsed
            // element-by-element and keeps it intact.
            /** @var list<string> $stringified — all elements are strings (either originally or via __toString) */
            return implode('|', $stringified);
        }

        // Presence modifiers (ExcludeIf, RequiredIf, etc.) must run before
        // type constraints. Other object rules (closures, custom ValidationRule)
        // run after constraints so bail/type checks can stop validation first.
        $presenceRules = [];
        $strings = [];
        $otherRules = [];

        foreach ($rules as $rule) {
            if (is_string($rule)) {
                $strings[] = $rule;
            } elseif ($this->isPresenceModifier($rule)) {
                $presenceRules[] = $rule;
            } else {
                $otherRules[] = $rule;
            }
        }

        return [...$presenceRules, ...$strings, ...$otherRules];
    }

    /**
     * Does any compiled rule token contain a literal `|`? Such a token (e.g. a
     * `regex:/^(a|b)$/` pattern) cannot be safely pipe-joined — Laravel's parser
     * would split it. Rules with one fall back to array form instead.
     *
     * @param  list<string>  $rules
     */
    private function anyContainsPipe(array $rules): bool
    {
        foreach ($rules as $rule) {
            if (str_contains($rule, '|')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Only In and NotIn are safe to stringify — they hold scalar value lists.
     * Exists, Unique, and Dimensions can have closure-based wheres or callbacks
     * that are silently dropped by __toString().
     */
    private function isSafeToStringify(object $rule): bool
    {
        return $rule instanceof In
            || $rule instanceof NotIn;
    }

    private function isPresenceModifier(object $rule): bool
    {
        return $rule instanceof ExcludeIf || $rule instanceof ExcludeUnless
            || $rule instanceof RequiredIf || $rule instanceof RequiredUnless
            || $rule instanceof ProhibitedIf || $rule instanceof ProhibitedUnless;
    }

    /** @return list<string|object> */
    protected function buildValidationRules(): array
    {
        return [...$this->rules, ...$this->constraints];
    }

    /** @return array<string, mixed> */
    public function buildNestedRules(string $attribute): array
    {
        return [];
    }

    public function setValidator(\Illuminate\Contracts\Validation\Validator $validator): static
    {
        $this->validator = $validator;

        return $this;
    }

    /** @param  array<array-key, mixed>  $data */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }
}
