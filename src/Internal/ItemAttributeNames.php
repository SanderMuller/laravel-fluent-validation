<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Illuminate\Support\Str;
use Illuminate\Validation\Validator;
use ReflectionProperty;

/**
 * Makes a per-item validator name its fields by the full path, as Laravel
 * does: `items.0.name`, not `name`. The validator sees only the item, so
 * each rule key and each path in the item becomes an implicit attribute, and
 * the formatter puts the item's path in front of it. As in Laravel, a field
 * without a rule gets the snake-case name. A cached validator is shared by
 * items, so the path changes for each item.
 *
 * It also maps the caller's messages and attribute names to the item's keys:
 * `items.*.name` for every item, `items.0.name` for item 0 only.
 *
 * @internal Collaborator of {@see ItemValidator}.
 */
final class ItemAttributeNames
{
    private string $path = '';

    private readonly string $rulePattern;

    /** @var array{base: array<string, string>, wildcard: list<array{int, string, string}>, byIndex: array<string, list<array{int, string, string}>>}|null */
    private readonly ?array $messagesPerItem;

    /** @var array{base: array<string, string>, wildcard: list<array{int, string, string}>, byIndex: array<string, list<array{int, string, string}>>}|null */
    private readonly ?array $attributesPerItem;

    /**
     * @param  list<string>  $ruleKeys  the item's rule keys, such as `rows.*.start`
     * @param  array<string, string>  $messages  the item validator's messages
     * @param  array<string, string>  $attributes  the item validator's attribute names
     */
    public function __construct(
        private readonly bool $isScalar,
        array $ruleKeys,
        private readonly string $parent,
        array $messages,
        array $attributes,
    ) {
        $patterns = array_map(static fn (string $key): string => str_replace('\\*', '[^.]*', preg_quote($key, '/')), $ruleKeys);

        $this->rulePattern = $patterns === [] ? '/(?!)/' : '/^(?:' . implode('|', $patterns) . ')$/';
        $this->messagesPerItem = $this->underItems($messages);
        $this->attributesPerItem = $this->underItems($attributes);
    }

    /**
     * The per-item validator sees the item's keys, so a message or attribute
     * name keyed `items.*.name` becomes `name`, and `items.*` of a scalar
     * each() becomes `_v`.
     *
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    public static function relativeToItem(array $values, string $parent, bool $isScalar): array
    {
        $prefix = $parent . '.*';
        $relative = [];

        foreach ($values as $key => $value) {
            if ($key === $prefix || str_starts_with($key, $prefix . '.')) {
                $rest = substr($key, strlen($prefix) + 1);
                $relative[$isScalar ? ($rest === '' ? '_v' : '_v.' . $rest) : $rest] = $value;
            }
        }

        unset($relative['']);

        return $relative;
    }

    /** @param  array<string, mixed>  $itemData */
    public function forItem(Validator $validator, int|string $index, array $itemData): void
    {
        $this->path = $this->parent . '.' . $index;

        $property = new ReflectionProperty($validator, 'implicitAttributes');

        /** @var array<array-key, list<int|string>> $current */
        $current = $property->getValue($validator);

        // Keep only Laravel's own wildcard keys. The keys of an earlier item
        // would make each item cost more than the one before it.
        $implicitAttributes = array_filter(
            $current,
            static fn (int|string $key): bool => str_contains((string) $key, '*'),
            ARRAY_FILTER_USE_KEY,
        );

        foreach (array_keys($validator->getRules()) as $key) {
            $implicitAttributes[$key] ??= [$key];
        }

        $this->addPaths($implicitAttributes, $itemData, '');

        $property->setValue($validator, $implicitAttributes);
        $validator->setImplicitAttributesFormatter($this->fullPath(...));

        // Laravel uses the first message that fits in the caller's order, but
        // an exact attribute name before a wildcard one.
        if ($this->messagesPerItem !== null) {
            $validator->customMessages = $this->forIndex($this->messagesPerItem, (string) $index, exactFirst: false);
        }

        if ($this->attributesPerItem !== null) {
            $validator->customAttributes = $this->forIndex($this->attributesPerItem, (string) $index, exactFirst: true);
        }
    }

    /**
     * The keys under the parent that name one item (`items.0.name`) or every
     * item (`items.*.name`), as [position, item key, value], and the other
     * keys as the base. Null when no key names one item: the `*` keys are
     * then already in the validator's messages and attribute names.
     *
     * @param  array<string, string>  $values
     * @return array{base: array<string, string>, wildcard: list<array{int, string, string}>, byIndex: array<string, list<array{int, string, string}>>}|null
     */
    private function underItems(array $values): ?array
    {
        $base = [];
        $wildcard = [];
        $byIndex = [];
        $position = 0;

        foreach ($values as $key => $value) {
            if (! str_starts_with($key, $this->parent . '.')) {
                $base[$key] = $value;

                continue;
            }

            $segments = explode('.', substr($key, strlen($this->parent) + 1), 2);
            $rest = $segments[1] ?? '';
            $itemKey = $this->isScalar ? ($rest === '' ? '_v' : '_v.' . $rest) : $rest;

            if ($itemKey === '') {
                continue;
            }

            if ($segments[0] === '*') {
                $wildcard[] = [$position++, $itemKey, $value];
            } else {
                $byIndex[$segments[0]][] = [$position++, $itemKey, $value];
            }
        }

        return $byIndex === [] ? null : ['base' => $base, 'wildcard' => $wildcard, 'byIndex' => $byIndex];
    }

    /**
     * @param  array{base: array<string, string>, wildcard: list<array{int, string, string}>, byIndex: array<string, list<array{int, string, string}>>}  $perItem
     * @return array<string, string>
     */
    private function forIndex(array $perItem, string $index, bool $exactFirst): array
    {
        $exact = $perItem['byIndex'][$index] ?? [];
        $entries = [...$exact, ...$perItem['wildcard']];

        if (! $exactFirst) {
            usort($entries, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        }

        $values = [];

        foreach ($entries as [, $itemKey, $value]) {
            $values[$itemKey] ??= $value;
        }

        return $values + $perItem['base'];
    }

    /**
     * @param  array<array-key, list<int|string>>  $implicitAttributes
     * @param  array<array-key, mixed>  $data
     */
    private function addPaths(array &$implicitAttributes, array $data, string $prefix): void
    {
        foreach ($data as $key => $value) {
            $path = $prefix . $key;
            $implicitAttributes[$path] ??= [$path];

            if (is_array($value)) {
                $this->addPaths($implicitAttributes, $value, $path . '.');
            }
        }
    }

    private function fullPath(string $attribute): string
    {
        if ($this->isScalar) {
            return $this->path;
        }

        $path = $this->path . '.' . $attribute;

        return preg_match($this->rulePattern, $attribute) === 1 ? $path : str_replace('_', ' ', Str::snake($path));
    }
}
