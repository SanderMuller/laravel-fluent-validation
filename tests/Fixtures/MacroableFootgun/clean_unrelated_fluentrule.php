<?php declare(strict_types=1);

namespace Acme;

final class FluentRule
{
    public static function field(): self
    {
        return new self();
    }

    public function min(int $value): self
    {
        return $this;
    }
}

return FluentRule::field()->min(5);
