<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator as BaseValidator;
use ReflectionClass;
use ReflectionProperty;

/**
 * Rewrites `parent.*.field` references to the item-relative `field` for
 * per-item validation. Mirrors Laravel, which resolves wildcards only in the
 * parameters of dependent rules (`Validator::$dependentRules`, plus custom
 * rules registered with `Validator::extendDependent()`). Parameters of other
 * rules, such as `in:items.*.foo`, are literal values and stay unchanged.
 *
 * @internal Implementation detail of `RuleSet`.
 */
final class WildcardReferenceRewriter
{
    /** @var array<string, int> studly rule names */
    private readonly array $dependentRules;

    public function __construct(private readonly string $prefix)
    {
        $this->dependentRules = array_flip(self::dependentRules());
    }

    public function rewrite(mixed $rule): mixed
    {
        if (is_string($rule)) {
            return $this->rewriteString($rule);
        }

        if (! is_array($rule)) {
            return $rule;
        }

        $rewritten = [];

        foreach ($rule as $part) {
            if (is_string($part)) {
                $part = $this->rewriteString($part);
            } elseif (is_array($part) && isset($part[0]) && is_string($part[0]) && $this->isDependent($part[0])) {
                // ['exclude_unless', 'items.*.type', 'chapter'] → ['exclude_unless', 'type', 'chapter']
                $part = array_map(fn (mixed $value): mixed => is_string($value) ? $this->stripPrefix($value) : $value, $part);
            }

            $rewritten[] = $part;
        }

        return $rewritten;
    }

    /**
     * 'gte:items.*.start|in:items.*.foo' → 'gte:start|in:items.*.foo'.
     * Parts that are not rewritten implode back to the exact input, so a
     * regex token like `regex:/^(a|b)$/` keeps its pipe.
     */
    private function rewriteString(string $rule): string
    {
        if (! str_contains($rule, $this->prefix)) {
            return $rule;
        }

        $parts = [];

        foreach (explode('|', $rule) as $part) {
            $pieces = explode(':', $part, 2);

            if (isset($pieces[1]) && $this->isDependent($pieces[0])) {
                $part = $pieces[0] . ':' . implode(',', array_map($this->stripPrefix(...), explode(',', $pieces[1])));
            }

            $parts[] = $part;
        }

        return implode('|', $parts);
    }

    private function isDependent(string $name): bool
    {
        return isset($this->dependentRules[Str::studly(trim($name))]);
    }

    private function stripPrefix(string $value): string
    {
        return str_starts_with($value, $this->prefix)
            ? substr($value, strlen($this->prefix))
            : $value;
    }

    /**
     * The factory adds custom dependent extensions to each validator it
     * makes. A custom resolver may return a validator that is not a
     * `BaseValidator`; fall back to Laravel's built-in list then.
     *
     * @return list<string>
     */
    private static function dependentRules(): array
    {
        $validator = Validator::make([], []);

        /** @var list<string> $rules */
        $rules = $validator instanceof BaseValidator
            ? (new ReflectionProperty(BaseValidator::class, 'dependentRules'))->getValue($validator)
            : (new ReflectionClass(BaseValidator::class))->getDefaultProperties()['dependentRules'];

        return $rules;
    }
}
