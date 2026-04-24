<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\FastCheck\Shared;

use Closure;
use SanderMuller\FluentValidation\FastCheck\CoreValueCompiler;
use SanderMuller\FluentValidation\FastCheck\ItemContextCompiler;

/**
 * Builds an item-aware branch closure for a rule-string remainder.
 *
 * Tries {@see ItemContextCompiler::compile()} first so combinations like
 * `required_with:foo|same:bar` compose into one fast closure; falls back
 * to the value-only {@see CoreValueCompiler::compile()} wrapped to the
 * item-aware signature when the remainder is purely value-level.
 *
 * @internal
 */
final class ItemAwareBranchBuilder
{
    /**
     * @return Closure(mixed, array<string, mixed>): bool|null
     */
    public static function build(string $ruleString): ?Closure
    {
        $itemAware = ItemContextCompiler::compile($ruleString);

        if ($itemAware instanceof Closure) {
            return $itemAware;
        }

        $valueOnly = CoreValueCompiler::compile($ruleString);

        if (! $valueOnly instanceof Closure) {
            return null;
        }

        return static fn (mixed $value, array $_item): bool => $valueOnly($value);
    }
}
