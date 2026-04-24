<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\FastCheck;

use Closure;
use SanderMuller\FluentValidation\FastCheck\Shared\ItemAwareBranchBuilder;
use SanderMuller\FluentValidation\FastCheck\Shared\LaravelEmptiness;

/**
 * Compiles rule strings that contain presence conditionals —
 * `required_with`, `required_without`, `required_with_all`,
 * `required_without_all` — into an item-aware closure that gates on
 * sibling presence and then delegates the remainder to
 * {@see ItemAwareBranchBuilder}.
 *
 * Returns null if the rule contains no presence conditional, the field
 * identifiers are malformed, or the stripped remainder is itself not
 * fast-checkable.
 *
 * @internal
 */
final class PresenceConditionalCompiler
{
    /**
     * @return Closure(mixed, array<string, mixed>): bool|null
     */
    public static function compile(string $ruleString): ?Closure
    {
        if (! str_contains($ruleString, 'required_with:')
            && ! str_contains($ruleString, 'required_without:')
            && ! str_contains($ruleString, 'required_with_all:')
            && ! str_contains($ruleString, 'required_without_all:')
        ) {
            return null;
        }

        /** @var list<array{type: string, fields: list<string>}> $conditions */
        $conditions = [];
        $remaining = [];

        foreach (explode('|', $ruleString) as $part) {
            if (preg_match('/^(required_with_all|required_without_all|required_with|required_without):(.+)$/', $part, $m) === 1) {
                $fields = explode(',', $m[2]);

                foreach ($fields as $field) {
                    if (preg_match('/\A[a-zA-Z_]\w*\z/', $field) !== 1) {
                        return null;
                    }
                }

                $conditions[] = ['type' => $m[1], 'fields' => $fields];
            } else {
                $remaining[] = $part;
            }
        }

        if ($conditions === []) {
            return null;
        }

        $stripped = implode('|', $remaining);

        /** @var ?Closure(mixed, array<string, mixed>): bool $checkRest */
        $checkRest = $stripped === ''
            ? static fn (mixed $_value, array $_item): bool => true
            : ItemAwareBranchBuilder::build($stripped);

        if (! $checkRest instanceof Closure) {
            return null;
        }

        return static function (mixed $value, array $item) use ($conditions, $checkRest): bool {
            /** @var array<string, mixed> $item */
            $active = false;
            foreach ($conditions as $condition) {
                if (self::presenceConditionActive($condition['type'], $condition['fields'], $item)) {
                    $active = true;
                    break;
                }
            }

            // When presence conditions activate, the field is required in
            // Laravel's sense: fail if empty per `validateRequired`.
            if ($active && LaravelEmptiness::isEmpty($value)) {
                return false;
            }

            return $checkRest($value, $item);
        };
    }

    /**
     * A field is "present" by Laravel's `validateRequired` criteria: not null,
     * not whitespace-only string, not empty array/Countable.
     *
     * @param  list<string>  $fields
     * @param  array<string, mixed>  $item
     */
    private static function presenceConditionActive(string $type, array $fields, array $item): bool
    {
        $present = [];
        foreach ($fields as $field) {
            $present[] = ! LaravelEmptiness::isEmpty($item[$field] ?? null);
        }

        return match ($type) {
            'required_with' => in_array(true, $present, true),
            'required_without' => in_array(false, $present, true),
            'required_with_all' => ! in_array(false, $present, true),
            'required_without_all' => ! in_array(true, $present, true),
            default => false,
        };
    }
}
