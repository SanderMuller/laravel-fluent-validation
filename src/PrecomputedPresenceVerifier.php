<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Illuminate\Validation\DatabasePresenceVerifierInterface;
use Illuminate\Validation\PresenceVerifierInterface;

/**
 * A presence verifier that returns pre-computed results from batch queries.
 *
 * Used by BatchDatabaseChecker to replace per-item DB queries with lookup-set
 * checks. The original Exists/Unique rule objects stay in place, so Laravel's
 * full message resolution pipeline (custom messages, :attribute replacement)
 * works unchanged.
 *
 * Results are scoped by table+column so multiple exists/unique rules on
 * different fields don't interfere with each other.
 *
 * Falls back to the original verifier for lookups that weren't pre-computed
 * (e.g., rules with closure callbacks that couldn't be batched).
 */
final class PrecomputedPresenceVerifier implements DatabasePresenceVerifierInterface
{
    /** @var array<string, array<int, mixed>>  Keyed by "table:column" */
    private array $lookups = [];

    public function __construct(private readonly ?PresenceVerifierInterface $fallback = null) {}

    /**
     * Register pre-computed values for a table+column pair.
     *
     * @param  array<int, mixed>  $values  Values that exist in the database
     */
    public function addLookup(string $table, string $column, array $values): void
    {
        $this->lookups[$table . ':' . $column] = $values;
    }

    /** @param  array<mixed>  $extra */
    public function getCount(mixed $collection, mixed $column, mixed $value, mixed $excludeId = null, mixed $idColumn = null, array $extra = []): int
    {
        $key = $collection . ':' . $column;

        if (! isset($this->lookups[$key])) {
            if ($this->fallback instanceof PresenceVerifierInterface) {
                return $this->fallback->getCount($collection, $column, $value, $excludeId, $idColumn, $extra);
            }

            return 0;
        }

        return in_array($value, $this->lookups[$key], true) ? 1 : 0;
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @param  array<mixed>  $extra
     */
    public function getMultiCount(mixed $collection, mixed $column, array $values, array $extra = []): int
    {
        $key = $collection . ':' . $column;

        if (! isset($this->lookups[$key])) {
            if ($this->fallback instanceof PresenceVerifierInterface) {
                return $this->fallback->getMultiCount($collection, $column, $values, $extra);
            }

            return 0;
        }

        $count = 0;

        foreach ($values as $val) {
            if (in_array($val, $this->lookups[$key], true)) {
                ++$count;
            }
        }

        return $count;
    }

    public function setConnection(mixed $connection): void
    {
        if ($this->fallback instanceof DatabasePresenceVerifierInterface) {
            $this->fallback->setConnection($connection);
        }
    }

    public function hasLookups(): bool
    {
        return $this->lookups !== [];
    }
}
