<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Rules;

use Illuminate\Validation\Rules\Dimensions;

class ImageRule extends FileRule
{
    /** @var list<string> */
    protected array $constraints = ['image'];

    protected function defaultConstraintName(): string
    {
        return 'image';
    }

    public function allowSvg(): static
    {
        $this->constraints = array_values(array_map(
            static fn (string $c): string => $c === 'image' ? 'image:allow_svg' : $c,
            $this->constraints,
        ));

        return $this;
    }

    public function dimensions(Dimensions $dimensions, ?string $message = null): static
    {
        return $this->addRule($dimensions, $message);
    }

    public function width(int $value, ?string $message = null): static
    {
        return $this->dimensions(new Dimensions(['width' => $value]), $message);
    }

    public function height(int $value, ?string $message = null): static
    {
        return $this->dimensions(new Dimensions(['height' => $value]), $message);
    }

    public function minWidth(int $value, ?string $message = null): static
    {
        return $this->dimensions(new Dimensions(['min_width' => $value]), $message);
    }

    public function maxWidth(int $value, ?string $message = null): static
    {
        return $this->dimensions(new Dimensions(['max_width' => $value]), $message);
    }

    public function minHeight(int $value, ?string $message = null): static
    {
        return $this->dimensions(new Dimensions(['min_height' => $value]), $message);
    }

    public function maxHeight(int $value, ?string $message = null): static
    {
        return $this->dimensions(new Dimensions(['max_height' => $value]), $message);
    }

    public function ratio(float|string $value, ?string $message = null): static
    {
        return $this->dimensions(new Dimensions(['ratio' => $value]), $message);
    }
}
