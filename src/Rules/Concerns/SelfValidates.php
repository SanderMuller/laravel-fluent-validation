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

        $this->shareWildcardKeys($innerValidator, $attribute, array_keys($nestedRules));

        if ($innerValidator->passes()) {
            return;
        }

        $this->forwardErrors($innerValidator, $attribute, $nestedRules !== [], $fail);
    }

    /**
     * Give the inner validator the wildcard keys of the outer validator, each
     * with the attribute it has for this item. Laravel uses them to replace the
     * `*` in a parameter such as `after:events.*.start_date`, and it shows the
     * raw name (`0.sort_order`, not `0.sort order`) only for these attributes.
     * A fixed child key from `children()` gets its wildcard key too, because the
     * outer validator never expanded it. The outer formatter for these names is
     * shared too.
     *
     * Only this item's attribute is shared, not the full list of the outer
     * validator, so a lookup in the inner validator does not grow with the
     * number of items.
     *
     * @param  list<string>  $nestedKeys
     */
    private function shareWildcardKeys(\Illuminate\Validation\Validator $innerValidator, string $attribute, array $nestedKeys): void
    {
        if (! isset($this->validator)) {
            return;
        }

        $formatter = (new ReflectionProperty($this->validator, 'implicitAttributesFormatter'))->getValue($this->validator);

        if ($formatter !== null) {
            /** @var callable(string): string $formatter */
            $innerValidator->setImplicitAttributesFormatter($formatter);
        }

        /** @var array<string, list<int|string>> $outerKeys */
        $outerKeys = (new ReflectionProperty($this->validator, 'implicitAttributes'))->getValue($this->validator);
        $match = $this->wildcardKeyOf($attribute, $outerKeys);

        if ($match === null) {
            return;
        }

        [$wildcardKey, $outerAttribute] = $match;
        $shared = [$wildcardKey => [$outerAttribute]];

        foreach ($nestedKeys as $nestedKey) {
            if (! str_contains($nestedKey, '*') && str_starts_with($nestedKey, $attribute . '.')) {
                $shared[$wildcardKey . substr($nestedKey, strlen($attribute))] = [$nestedKey];
            }
        }

        $indexes = $this->wildcardIndexes($wildcardKey, $attribute);

        foreach (array_keys($outerKeys) as $key) {
            $expanded = $this->expandForThisItem($key, $indexes);

            if ($expanded !== null) {
                $shared[$key] ??= [$expanded];
            }
        }

        $property = new ReflectionProperty($innerValidator, 'implicitAttributes');

        /** @var array<string, list<int|string>> $innerKeys */
        $innerKeys = $property->getValue($innerValidator);
        $property->setValue($innerValidator, $this->underWildcardKey($innerKeys, $attribute, $wildcardKey) + $shared);
    }

    /**
     * The inner validator expanded `each()` under the concrete attribute
     * (`groups.0.rows.*`). A reference such as `groups.*.rows.*.start` needs the
     * full wildcard key (`groups.*.rows.*`) to find both indexes.
     *
     * @param  array<string, list<int|string>>  $innerKeys
     * @return array<string, list<int|string>>
     */
    private function underWildcardKey(array $innerKeys, string $attribute, string $wildcardKey): array
    {
        $keys = [];

        foreach ($innerKeys as $key => $attributes) {
            $keys[str_starts_with($key, $attribute . '.') ? $wildcardKey . substr($key, strlen($attribute)) : $key] = $attributes;
        }

        return $keys;
    }

    /**
     * The attribute that a wildcard key has for this item's indexes, when the
     * data has it. Laravel expands a wildcard only over the keys in the data, so
     * `other.*.start_date` has no `other.0.start_date` when `other` has no `0`.
     *
     * @param  list<string>  $indexes
     */
    private function expandForThisItem(string $key, array $indexes): ?string
    {
        $segments = explode('.', $key);
        $used = 0;
        $last = null;

        foreach ($segments as $position => $segment) {
            if ($segment === '*') {
                if (! isset($indexes[$used])) {
                    return null;
                }

                $segments[$position] = $indexes[$used++];
                $last = $position;
            }
        }

        if ($last === null || ! Arr::has($this->data, implode('.', array_slice($segments, 0, $last + 1)))) {
            return null;
        }

        return implode('.', $segments);
    }

    /**
     * The wildcard key that the outer validator expanded into this attribute,
     * and the attribute as that validator stores it.
     *
     * @param  array<string, list<int|string>>  $outerKeys
     * @return array{string, int|string}|null
     */
    private function wildcardKeyOf(string $attribute, array $outerKeys): ?array
    {
        // A top-level `*` expands to integer keys. PHP makes a key an integer only
        // when it is the canonical form of one (`1`, `-1`, not `01`).
        $index = (string) (int) $attribute === $attribute ? (int) $attribute : null;

        foreach ($outerKeys as $wildcardKey => $attributes) {
            if (in_array($attribute, $attributes, true)) {
                return [$wildcardKey, $attribute];
            }

            if ($index !== null && in_array($index, $attributes, true)) {
                return [$wildcardKey, $index];
            }
        }

        return null;
    }

    /**
     * The values that the `*` segments of the wildcard key have in this
     * attribute, as Laravel's Validator::getExplicitKeys() finds them.
     *
     * @return list<string>
     */
    private function wildcardIndexes(string $wildcardKey, string $attribute): array
    {
        $pattern = str_replace('\\*', '([^\\.]*)', preg_quote($wildcardKey, '/'));

        if (preg_match('/^' . $pattern . '/', $attribute, $matches) !== 1) {
            return [];
        }

        return array_values(array_slice($matches, 1));
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
        // An object that stands in for a string constraint keeps its place.
        $presenceRules = [];
        $constraints = [];
        $otherRules = [];

        foreach ($rules as $rule) {
            if (is_string($rule) || $this->isConstraintObject($rule)) {
                $constraints[] = $rule;
            } elseif ($this->isPresenceModifier($rule)) {
                $presenceRules[] = $rule;
            } else {
                $otherRules[] = $rule;
            }
        }

        return [...$presenceRules, ...$constraints, ...$otherRules];
    }

    /**
     * Whether an object rule replaces a string constraint, so it must keep
     * that constraint's position. BooleanRule::allowWords() uses this.
     */
    protected function isConstraintObject(object $rule): bool
    {
        return false;
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
