<?php declare(strict_types=1);

class SomeOtherBuilder
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

return SomeOtherBuilder::field()->min(5);
