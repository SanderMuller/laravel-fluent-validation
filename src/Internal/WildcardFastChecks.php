<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Closure;
use Illuminate\Support\Arr;

/**
 * The fast checks of the expanded wildcard attributes of a validator, run
 * before Laravel's loop. An attribute that passes its check leaves the rules,
 * so Laravel does not validate it. A referenced attribute leaves only when no
 * attribute left in the rules reads its rules.
 *
 * @internal Implementation detail of `OptimizedValidator`.
 */
final readonly class WildcardFastChecks
{
    /**
     * @param  array<string, Closure(mixed): bool>  $valueChecks  keyed by pattern
     * @param  array<string, array{0: Closure(mixed, array<string, mixed>): bool, 1: list<string>, 2: list<string>}>  $itemChecks  checks that read fields of the item of the attribute, with those fields, keyed by pattern
     * @param  array<string, list<string>>  $groups  pattern => expanded attributes
     * @param  array<string, array{0: string, 1: list<string>}>  $referenced  referenced attribute => its pattern and the attributes that read its rules
     */
    private function __construct(
        private array $valueChecks,
        private array $itemChecks,
        private array $groups,
        private array $referenced,
    ) {}

    /**
     * @param  array<string, Closure(mixed): bool>  $valueChecks
     * @param  array<string, string>  $attributePatternMap
     * @param  array<string, true>  $referencedAttributes
     * @param  array<string, array{0: Closure(mixed, array<string, mixed>): bool, 1: list<string>, 2: list<string>}>  $itemChecks
     * @param  array<string, list<string>|false>  $dependents
     */
    public static function of(array $valueChecks, array $attributePatternMap, array $referencedAttributes, array $itemChecks, array $dependents): self
    {
        $groups = [];
        $referenced = [];

        foreach ($attributePatternMap as $attribute => $pattern) {
            if (! isset($valueChecks[$pattern]) && ! isset($itemChecks[$pattern])) {
                continue;
            }

            if (! isset($referencedAttributes[$attribute])) {
                $groups[$pattern][] = $attribute;
            } elseif (is_array($dependents[$attribute] ?? null)) {
                $referenced[$attribute] = [$pattern, $dependents[$attribute]];
            }
        }

        return new self($valueChecks, $itemChecks, $groups, $referenced);
    }

    /**
     * Removes the attributes that pass their check from the rules, except the
     * referenced ones. An attribute whose rules differ from the rules the
     * checks were built for stays.
     *
     * @param  array<array-key, mixed>  $rules
     * @param  array<array-key, mixed>|null  $checkedRules  the rules the checks were built for, or null when no rule changed
     * @param  array<array-key, mixed>  $data
     * @param  Closure(int|string): mixed  $getValue  the validator's value of an attribute
     * @return array<string, mixed> the removed rules
     */
    public function removePassing(array &$rules, ?array $checkedRules, array $data, Closure $getValue): array
    {
        if ($this->groups === []) {
            return [];
        }

        $removedRules = [];
        $flatData = Arr::dot($data);

        foreach ($this->groups as $pattern => $attributes) {
            $check = $this->valueChecks[$pattern] ?? null;

            foreach ($attributes as $attribute) {
                // Arr::dot() has no key for a non-empty array, so read those values as Laravel does.
                if (isset($rules[$attribute]) && ($checkedRules === null || $this->isUnchanged($rules, $checkedRules, $attribute)) && ($check instanceof Closure ? $check(array_key_exists($attribute, $flatData) ? $flatData[$attribute] : $getValue($attribute)) : $this->passesItemCheck($pattern, (string) $attribute, $getValue))) {
                    $removedRules[$attribute] = $rules[$attribute];
                    unset($rules[$attribute]);
                }
            }
        }

        return $removedRules;
    }

    /**
     * Removes the referenced attributes that no attribute left in the rules
     * reads, and that pass their check. One that leaves can let another go.
     *
     * @param  array<array-key, mixed>  $rules
     * @param  array<array-key, mixed>|null  $checkedRules  the rules the checks were built for, or null when no rule changed
     * @param  array<string, mixed>  $removedRules
     * @param  Closure(int|string): mixed  $getValue
     */
    public function removePassingReferenced(array &$rules, ?array $checkedRules, array &$removedRules, Closure $getValue): void
    {
        $pending = $this->referenced;

        do {
            $removed = false;

            foreach ($pending as $attribute => [$pattern, $dependents]) {
                if (isset($rules[$attribute]) && array_intersect_key($rules, array_flip($dependents)) !== []) {
                    continue;
                }

                unset($pending[$attribute]);

                if ($this->isUnchanged($rules, $checkedRules, $attribute) && $this->passes($pattern, (string) $attribute, $getValue)) {
                    $removedRules[$attribute] = $rules[$attribute];
                    unset($rules[$attribute]);
                    $removed = true;
                }
            }
        } while ($removed);
    }

    /**
     * @param  array<array-key, mixed>  $rules
     * @param  array<array-key, mixed>|null  $checkedRules
     */
    private function isUnchanged(array $rules, ?array $checkedRules, int|string $attribute): bool
    {
        if ($checkedRules === null) {
            return isset($rules[$attribute]);
        }

        return isset($rules[$attribute], $checkedRules[$attribute]) && $rules[$attribute] === $checkedRules[$attribute];
    }

    /**
     * @param  Closure(int|string): mixed  $getValue
     */
    private function passes(string $pattern, string $attribute, Closure $getValue): bool
    {
        $check = $this->valueChecks[$pattern] ?? null;

        return $check instanceof Closure ? $check($getValue($attribute)) : $this->passesItemCheck($pattern, $attribute, $getValue);
    }

    /**
     * Runs the check that reads fields of the item: the array that holds the
     * attribute. A missing field reads as null, as in the check.
     *
     * @param  Closure(int|string): mixed  $getValue
     */
    private function passesItemCheck(string $pattern, string $attribute, Closure $getValue): bool
    {
        $dot = strrpos($attribute, '.');

        if ($dot === false || ! isset($this->itemChecks[$pattern])) {
            return false;
        }

        $itemPath = substr($attribute, 0, $dot);
        $item = $getValue($itemPath);

        if (! is_array($item)) {
            return false;
        }

        [$check, $fields, $dates] = $this->itemChecks[$pattern];

        // Laravel reads a date field as a date when its full path, such as
        // `apr.utc.from`, parses as one.
        foreach ($dates as $field) {
            if (DateLikePaths::parses($itemPath . '.' . $field)) {
                return false;
            }
        }

        $values = [];

        foreach ($fields as $field) {
            $values[$field] = $item[$field] ?? null;
        }

        $field = substr($attribute, $dot + 1);

        return $check($item[$field] ?? null, $values);
    }
}
