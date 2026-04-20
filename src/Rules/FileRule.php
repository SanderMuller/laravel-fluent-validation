<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Rules;

use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use SanderMuller\FluentValidation\Contracts\FluentRuleContract;
use SanderMuller\FluentValidation\Rules\Concerns\HasFieldModifiers;
use SanderMuller\FluentValidation\Rules\Concerns\SelfValidates;

class FileRule implements DataAwareRule, FluentRuleContract, ValidationRule, ValidatorAwareRule
{
    use Conditionable;
    use HasFieldModifiers;
    use Macroable;
    use SelfValidates;

    /** @var list<string> */
    protected array $constraints = ['file'];

    public function min(int|string $size): static
    {
        return $this->addRule('min:' . $this->toKilobytes($size));
    }

    public function max(int|string $size): static
    {
        return $this->addRule('max:' . $this->toKilobytes($size));
    }

    public function between(int|string $min, int|string $max): static
    {
        return $this->addRule('between:' . $this->toKilobytes($min) . ',' . $this->toKilobytes($max));
    }

    public function exactly(int|string $size): static
    {
        return $this->addRule('size:' . $this->toKilobytes($size));
    }

    public function extensions(string ...$extensions): static
    {
        return $this->addRule('extensions:' . implode(',', $extensions));
    }

    public function mimes(string ...$mimes): static
    {
        return $this->addRule('mimes:' . implode(',', $mimes));
    }

    public function mimetypes(string ...$mimetypes): static
    {
        return $this->addRule('mimetypes:' . implode(',', $mimetypes));
    }

    protected function toKilobytes(int|string $size): int
    {
        if (is_int($size)) {
            return $size;
        }

        if (preg_match('/^(\d+(?:\.\d+)?)\s*(kb|mb|gb|tb)$/i', trim($size), $matches) === 1) {
            $value = (float) $matches[1];
            $unit = strtolower($matches[2]);

            return (int) round(match ($unit) {
                'kb' => $value,
                'mb' => $value * 1_000,
                'gb' => $value * 1_000_000,
                'tb' => $value * 1_000_000_000,
                default => $value,
            });
        }

        return (int) $size;
    }

    /** @return list<string|object> */
    protected function buildValidationRules(): array
    {
        return [...$this->reorderConstraints($this->constraints), ...$this->rules];
    }
}
