<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

/**
 * The fields an exclude rule removed from one item, as a tree: the keys to
 * remove at this level, and a tree for each key whose children lose fields.
 * A `*` key stands for every key at its level, as in a rule key.
 *
 * @internal
 */
final readonly class ExcludedFieldTree
{
    /**
     * @param  array<array-key, true>  $keys
     * @param  array<array-key, self>  $children
     */
    private function __construct(
        public array $keys,
        public array $children,
    ) {}

    /**
     * @param  list<non-empty-list<string>>  $paths  the path segments of each excluded field
     */
    public static function fromPaths(array $paths): ?self
    {
        $keys = [];
        $childPaths = [];

        foreach ($paths as $path) {
            $key = array_shift($path);

            if ($path === []) {
                $keys[$key] = true;
            } else {
                $childPaths[$key][] = $path;
            }
        }

        $children = [];

        foreach (array_diff_key($childPaths, $keys) as $key => $nested) {
            $children[$key] = self::fromPaths($nested);
        }

        return $keys === [] && $children === [] ? null : new self($keys, array_filter($children));
    }

    /**
     * The fields excluded from the whole data: the attributes the top-level
     * validator excluded, and the tree of each item of each group.
     *
     * @param  list<string>  $topLevel
     * @param  array<string, array<int|string, self|bool>>  $byGroup  per parent, per item index; true when the whole item is excluded
     */
    public static function forData(array $topLevel, array $byGroup): ?self
    {
        $tree = self::fromPaths(array_map(static fn (string $attribute): array => explode('.', $attribute), $topLevel));

        foreach ($byGroup as $parent => $byIndex) {
            // A whole excluded item is gone from the data, but an explicit
            // rule key below it, such as `items.0.tags`, still names it.
            $items = array_filter($byIndex, static fn (self|bool $item): bool => $item instanceof self);
            $whole = array_filter($byIndex, static fn (self|bool $item): bool => $item === true);

            if ($items === [] && $whole === []) {
                continue;
            }

            $group = new self($whole, $items);

            foreach (array_reverse(explode('.', $parent)) as $segment) {
                $group = new self([], [$segment => $group]);
            }

            $tree = $tree instanceof self ? $tree->with($group) : $group;
        }

        return $tree;
    }

    /**
     * The excluded fields below a key: true when the key itself is excluded.
     */
    public function below(int|string $key): self|true|null
    {
        return isset($this->keys[$key]) ? true : $this->children[$key] ?? null;
    }

    public function with(self $other): self
    {
        $keys = $this->keys + $other->keys;
        $children = array_diff_key($this->children, $keys);

        foreach (array_diff_key($other->children, $keys) as $key => $child) {
            $children[$key] = isset($children[$key]) ? $children[$key]->with($child) : $child;
        }

        return new self($keys, $children);
    }

    /**
     * @param  array<mixed, mixed>  $data
     * @return array<mixed, mixed>
     */
    public function removeFrom(array $data): array
    {
        if (isset($this->keys['*'])) {
            return [];
        }

        $data = array_diff_key($data, $this->keys);

        foreach ($this->children as $key => $child) {
            foreach ($key === '*' ? array_keys($data) : [$key] as $dataKey) {
                if (isset($data[$dataKey]) && is_array($data[$dataKey])) {
                    $data[$dataKey] = $child->removeFrom($data[$dataKey]);
                }
            }
        }

        return $data;
    }
}
