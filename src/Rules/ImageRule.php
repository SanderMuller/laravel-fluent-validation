<?php

declare(strict_types=1);

namespace SanderMuller\FluentValidation\Rules;

use Illuminate\Validation\Rules\Dimensions;

class ImageRule extends FileRule
{
    /** @var list<string> */
    protected array $constraints = ['image'];

    public function allowSvg(): static
    {
        $this->constraints[array_search('image', $this->constraints, true)] = 'image:allow_svg';

        return $this;
    }

    public function dimensions(Dimensions $dimensions): static
    {
        return $this->addRule($dimensions);
    }

    public function width(int $value): static
    {
        return $this->dimensions(new Dimensions(['width' => $value]));
    }

    public function height(int $value): static
    {
        return $this->dimensions(new Dimensions(['height' => $value]));
    }

    public function minWidth(int $value): static
    {
        return $this->dimensions(new Dimensions(['min_width' => $value]));
    }

    public function maxWidth(int $value): static
    {
        return $this->dimensions(new Dimensions(['max_width' => $value]));
    }

    public function minHeight(int $value): static
    {
        return $this->dimensions(new Dimensions(['min_height' => $value]));
    }

    public function maxHeight(int $value): static
    {
        return $this->dimensions(new Dimensions(['max_height' => $value]));
    }

    public function ratio(float|string $value): static
    {
        return $this->dimensions(new Dimensions(['ratio' => $value]));
    }
}
