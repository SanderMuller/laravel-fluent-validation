<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Exceptions;

use RuntimeException;

/**
 * Thrown before a batched database validation query fires when input is
 * obviously hostile or exceeds a declared size limit. Two sources:
 *
 * - `parent-max` — the parent array's user-declared `max:N` was breached.
 *   `$limit` is the user's `N`, `$attribute` is the concrete parent path.
 * - `hard-cap`  — the package-level safety fuse `BatchDatabaseChecker::$maxValuesPerGroup`
 *   was exceeded (defence in depth against forgotten `max:N`). `$attribute` is null.
 *
 * Callers that go through `HasFluentRules` or `RuleSet::validate()`/`check()`
 * see this remapped to `Illuminate\Validation\ValidationException`. Power
 * users can catch this type directly pre-remap.
 */
final class BatchLimitExceededException extends RuntimeException
{
    public const REASON_PARENT_MAX = 'parent-max';

    public const REASON_HARD_CAP = 'hard-cap';

    public function __construct(
        public readonly string $table,
        public readonly string $column,
        public readonly string $ruleType,
        public readonly string $reason,
        public readonly int $valueCount,
        public readonly int $limit,
        public readonly ?string $attribute = null,
    ) {
        parent::__construct($this->formatMessage());
    }

    private function formatMessage(): string
    {
        return match ($this->reason) {
            self::REASON_PARENT_MAX => sprintf(
                'Batched database validation refused: parent array "%s" has %d items, exceeding the declared max:%d before any query to %s.%s could run.',
                $this->attribute ?? '(unknown)',
                $this->valueCount,
                $this->limit,
                $this->table,
                $this->column,
            ),
            self::REASON_HARD_CAP => sprintf(
                'Batched database validation refused: %d values for %s.%s exceed BatchDatabaseChecker::$maxValuesPerGroup (%d). Raise the cap or add a parent max:N.',
                $this->valueCount,
                $this->table,
                $this->column,
                $this->limit,
            ),
            default => sprintf('Batched database validation refused for %s.%s (reason: %s).', $this->table, $this->column, $this->reason),
        };
    }
}
