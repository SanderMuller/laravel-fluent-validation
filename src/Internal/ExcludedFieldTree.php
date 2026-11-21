<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

/**
 * The fields an exclude rule removed from one item, as a tree: the keys to
 * remove at this level, and a tree for each key whose children lose fields.
 * One tree serves every item with the same dispatch value or reduced rules.
 *
 * @internal Implementation detail of `WildcardValidatedData`.
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
        $data = array_diff_key($data, $this->keys);

        foreach ($this->children as $key => $child) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $data[$key] = $child->removeFrom($data[$key]);
            }
        }

        return $data;
    }
}
